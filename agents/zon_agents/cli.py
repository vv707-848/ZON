"""`zon-agents serve` — the HTTP service · `zon-agents bootstrap [--recreate]` — the Qdrant collections ·
`zon-agents lab check|run|plan|sim` — the model lab (zon_agents/lab) · `zon-agents sim` — the simulated AI node
(zon_agents/sim)."""

import argparse
import asyncio
import json
import os
import shlex
import sys
import time
from collections import Counter
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import httpx
import uvicorn

from zon_agents import logs, monitoring
from zon_agents.config import Settings
from zon_agents.lab import hosted
from zon_agents.lab.cases import check, load_cases
from zon_agents.lab.load import load
from zon_agents.lab.runner import Outcome, floors, refused, report, run_cases, sample
from zon_agents.llm import LLM, load_prompts, openai_model
from zon_agents.main import create_app
from zon_agents.profiles import load_profile
from zon_agents.services import build_store
from zon_agents.sim import labsim
from zon_agents.sim.server import create_sim_app

DEFAULTS = Settings.model_fields


async def _bootstrap(settings: Settings, recreate: bool) -> None:
    store = build_store(settings)
    try:
        created = await store.bootstrap(recreate=recreate)
    finally:
        await store.aclose()
    logs.event("bootstrap", created=created, recreate=recreate)
    if recreate:
        logs.event("bootstrap.next", hint="collections are empty: run `php artisan zon:reindex --force` in the backend")


def _lab_check() -> int:
    cases = load_cases()
    problems = check(cases)
    for problem in problems:
        print(problem, file=sys.stderr)
    print(f"{len(cases)} cases, {len(problems)} problems")
    return 1 if problems else 0


def _lab_run(args: argparse.Namespace) -> int:
    cases = load_cases()
    problems = check(cases)
    if problems:
        print("\n".join(problems), file=sys.stderr)
        return 1
    if args.agents:
        wanted = set(args.agents.split(","))
        cases = [c for c in cases if c.agent in wanted]
    if args.sample:
        cases = sample(cases, args.sample, args.sample_shift)
    key = os.environ.get("LLM_API_KEY", "")  # never an argument: it would stay in the shell's history
    model = openai_model(args.base_url, key, args.model, args.budget, strict_schemas=bool(args.hosted))
    profile = load_profile(args.profile)
    if args.hosted:
        try:
            extras = json.loads(args.request or "{}")
        except json.JSONDecodeError as exc:
            print(f"--request is not JSON: {exc}", file=sys.stderr)
            return 1
        profile = profile.hosted_by(args.hosted, extras)
    elif args.request:
        print("--request goes with --hosted: a profile's own extras are its file's", file=sys.stderr)
        return 1
    llm = LLM(model, load_prompts(), profile=profile, max_concurrency=args.concurrency, budget_seconds=args.budget)
    done = 0

    def progress(outcome: Outcome) -> None:
        nonlocal done
        done += 1
        mark = "!" if outcome.error else "."
        print(mark, end="\n" if done % 80 == 0 else "", flush=True, file=sys.stderr)

    started, clock = datetime.now(UTC), time.monotonic()
    outcomes = asyncio.run(
        run_cases(
            llm,
            cases,
            concurrency=args.concurrency,
            repeat=args.repeat,
            with_variants=not args.no_variants,
            progress=progress,
            per_minute=args.rpm,
            tokens_per_minute=args.tpm,
        )
    )
    seconds = time.monotonic() - clock
    floor = asyncio.run(floors(llm, cases))  # the same cases with no model: no call to the engine
    gpu = f"hosted: {args.hosted}" if args.hosted else args.gpu
    result = report(outcomes, cases, llm, started=started, seconds=seconds, gpu=gpu, notes=args.notes, floor=floor)
    out = args.out or Path(f"lab-{args.profile}-{started:%Y%m%d-%H%M}.json")
    out.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding="utf-8")
    print(file=sys.stderr)
    _print_summary(result, out)
    for reason, count in llm.refusals.most_common(5):
        print(f"the endpoint ×{count}: {reason}", file=sys.stderr)
    return 0


def _lab_load(args: argparse.Namespace) -> int:
    key = os.environ.get("LLM_API_KEY", "")
    model = openai_model(args.base_url, key, args.model, args.budget)
    llm = LLM(
        model,
        load_prompts(),
        profile=load_profile(args.profile),
        max_concurrency=args.at_once,
        budget_seconds=args.budget,
    )
    found = asyncio.run(load(llm, load_cases(), at_once=args.at_once, rounds=args.rounds))
    print(f"{'agent':16} {'asked':>5} {'failed':>6} {'tokens_in':>9} {'p50 ms':>7} {'p95 ms':>7}")
    for agent, row in found.items():
        counts = f"{row['asked']:5} {row['failed']:6} {row['tokens_in_max']:9}"
        print(f"{agent:16} {counts} {row['p50_ms']:7} {row['p95_ms']:7}")
        for reason, count in row["failures"].items():
            print(f"   ×{count}: {reason}")
    out = args.out or Path(f"load-{args.profile}-{datetime.now(UTC):%Y%m%d-%H%M}.json")
    out.write_text(json.dumps(found, ensure_ascii=False, indent=1), encoding="utf-8")
    # the gate (docs/deploy-runbook.md 2.4): no failure, and p95 within the budget — a run that misses it says so
    missed = [a for a, row in found.items() if row["failed"] or row["p95_ms"] > args.budget * 1000]
    if missed:
        print(f"lab load misses the gate: {', '.join(missed)}")
    return 1 if missed else 0


def _lab_plan(args: argparse.Namespace) -> int:
    """What a hosted run may ask, as the arguments of `lab run` (stdout, one line) — or why not (lab/hosted.py)."""
    key = os.environ.get("LLM_API_KEY", "")
    if not key:
        print("LLM_API_KEY is empty: the host's key goes there", file=sys.stderr)
        return 1
    try:
        with httpx.Client(timeout=60) as client:
            made = hosted.plan(
                key,
                load_cases(),
                client=client,
                model=args.model,
                agents=args.agents,
                per_agent=args.sample,
                shift=args.sample_shift,
            )
    except hosted.Refused as exc:
        print(f"refused: {exc}", file=sys.stderr)
        return 1
    print("\n".join(made.seen), file=sys.stderr)
    print(shlex.join(made.arguments))
    return 0


def _lab_sim(args: argparse.Namespace) -> int:
    """Every known result of the simulation, checked (zon_agents/sim/labsim.py)."""
    cases = load_cases()
    problems = check(cases)
    if problems:
        print("\n".join(problems), file=sys.stderr)
        return 1
    names = args.runs.split(",") if args.runs else list(labsim.RUNS)
    results = labsim.run_all(cases, names)
    floor = asyncio.run(floors(labsim.simulated_llm("rules"), cases))
    wrong = 0
    print(f"{len(cases)} cases · the floor (no model): " + " · ".join(f"{a} {v}" for a, v in floor.items()))
    for name, (outcomes, unknown) in results.items():
        summary = labsim.summary(name, outcomes, cases, floor)
        wrong += len(unknown)
        print(f"\n{name}: overall {summary['overall']:.4f} · {len(outcomes)} answers · {len(unknown)} not as known")
        for agent, entry in summary["agents"].items():
            metrics = " · ".join(f"{k} {v}" for k, v in entry["metrics"].items())
            print(f"  {agent:<16}{entry['cases']:>4}{entry['score']:>8.4f}  failed {entry['failed']:<3} {metrics}")
        for problem in unknown:
            print(f"  ! {problem}", file=sys.stderr)
        if args.out:
            args.out.mkdir(parents=True, exist_ok=True)
            text = json.dumps(summary, ensure_ascii=False, indent=1, sort_keys=True) + "\n"
            (args.out / f"sim-{name}.json").write_text(text, encoding="utf-8")
    print(f"\n{'every result as known' if wrong == 0 else f'{wrong} results not as known'}")
    return 1 if wrong else 0


def _print_summary(result: dict[str, Any], out: Path) -> None:
    print(
        f"{result['profile']['name']} · {result['gpu'] or '?'} · {result['dataset']['cases']} cases · "
        f"{result['duration_s']}s · {result['throughput']['tokens_out_per_s']} tok/s"
    )
    print(f"{'agent':<16}{'cases':>6}{'score':>8}{'failed':>8}{'p50 ms':>8}{'p95 ms':>8}  metrics")
    for agent, entry in result["agents"].items():
        metrics = " · ".join(f"{k} {v}" for k, v in entry["metrics"].items())
        stable = entry["variants"].get("reversed", {}).get("stability")
        if stable is not None:
            metrics += f" · stability {stable}"
        if "floor" in entry:
            metrics += f" · floor {entry['floor']}"
        print(
            f"{agent:<16}{entry['cases']:>6}{entry['score']:>8.3f}{entry['failed']:>8}"
            f"{entry['latency_ms']['p50']:>8}{entry['latency_ms']['p95']:>8}  {metrics}"
        )
    # why cases failed, and how many of them were the endpoint (refused, unreachable, over its limit), not the model
    errors = Counter(o["error"] for o in result["outcomes"] if o.get("error"))
    if errors:
        print("errors: " + " · ".join(f"{kind} ×{count}" for kind, count in errors.most_common()))
        print(f"of them the endpoint's, not the model's: {sum(n for kind, n in errors.items() if refused(kind))}")
    print(f"overall {result['overall']:.3f} → {out}")


def main() -> None:
    parser = argparse.ArgumentParser(prog="zon-agents")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("serve", help="run the HTTP service (one worker) on HOST:PORT")
    sim = commands.add_parser("sim", help="run the simulated AI node (the rule model and bag-of-words vectors)")
    sim.add_argument("--host", default="127.0.0.1")
    sim.add_argument("--port", type=int, default=30000)
    bootstrap = commands.add_parser(
        "bootstrap", help="create the Qdrant collections and payload indexes (safe to repeat)"
    )
    bootstrap.add_argument("--recreate", action="store_true", help="drop and create again — then reindex everything")

    lab = commands.add_parser("lab", help="the model lab: golden cases against a model profile")
    lab_commands = lab.add_subparsers(dest="lab", required=True)
    lab_commands.add_parser("check", help="validate the cases")
    run = lab_commands.add_parser("run", help="run the cases against an endpoint (key in LLM_API_KEY)")
    run.add_argument("--profile", required=True, help="zon_agents/models/<profile>.toml: the model it serves")
    run.add_argument("--base-url", default=DEFAULTS["llm_base_url"].default)
    run.add_argument("--model", default=DEFAULTS["llm_model"].default, help="the name the engine serves it under")
    run.add_argument("--concurrency", type=int, default=DEFAULTS["llm_max_concurrency"].default)
    run.add_argument("--budget", type=float, default=DEFAULTS["request_budget_seconds"].default, help="seconds")
    run.add_argument("--repeat", type=int, default=1, help="rounds of every case (variance of a sampled model)")
    run.add_argument("--agents", help="only these agents, comma-separated")
    run.add_argument("--no-variants", action="store_true", help="skip the reversed-order and digits variants")
    run.add_argument("--hosted", help="who serves the model when it is not our node (OpenRouter…): said in the report")
    run.add_argument("--request", help="with --hosted: the host's request extras, as JSON (they replace the profile's)")
    run.add_argument("--rpm", type=int, help="at most this many cases a minute, one at a time (a host's rate limit)")
    run.add_argument("--tpm", type=int, help="at most this many tokens a minute (a host's token limit)")
    run.add_argument("--sample", type=int, help="at most this many cases of each agent, spread over its file")
    run.add_argument("--sample-shift", type=int, default=0, help="with --sample: every pick moved this many cases on")
    run.add_argument("--gpu", help="the card it ran on, for the report (A40, L40S, RTX PRO 6000…)")
    run.add_argument("--notes", help="anything a reader of the report should know")
    run.add_argument("--out", type=Path, help="the report file (default lab-<profile>-<time>.json)")
    plan = lab_commands.add_parser("plan", help="before a hosted run: the host of the key in LLM_API_KEY, probed")
    plan.add_argument("--model", default="", help="another model id at the host (default: its Qwen3.8-27B)")
    plan.add_argument("--agents", default="", help="only these agents, comma-separated")
    plan.add_argument("--sample", type=int, help="cases of each agent (0: all; default: what the daily limit allows)")
    plan.add_argument("--sample-shift", type=int, default=0, help="another day's sample: every pick moved this many on")
    heavy = lab_commands.add_parser("load", help="ranking at production's sizes, several at once (key in LLM_API_KEY)")
    heavy.add_argument("--profile", required=True, help="zon_agents/models/<profile>.toml: the model it serves")
    heavy.add_argument("--base-url", default=DEFAULTS["llm_base_url"].default)
    heavy.add_argument("--model", default=DEFAULTS["llm_model"].default, help="the name the engine serves it under")
    heavy.add_argument("--at-once", type=int, default=DEFAULTS["llm_max_concurrency"].default, help="asked together")
    heavy.add_argument("--rounds", type=int, default=3)
    heavy.add_argument("--budget", type=float, default=DEFAULTS["request_budget_seconds"].default, help="seconds")
    heavy.add_argument("--out", type=Path, help="the report file (default load-<profile>-<time>.json)")
    known = lab_commands.add_parser("sim", help="the cases against the simulation: every known result checked")
    known.add_argument("--runs", help=f"only these, comma-separated (default {','.join(labsim.RUNS)})")
    known.add_argument("--out", type=Path, help="a folder for each run's summary (sim-<run>.json)")
    args = parser.parse_args()

    if args.command == "lab":
        if args.lab == "check":
            sys.exit(_lab_check())
        sys.exit({"run": _lab_run, "plan": _lab_plan, "sim": _lab_sim, "load": _lab_load}[args.lab](args))
    if args.command == "sim":
        uvicorn.run(create_sim_app(), host=args.host, port=args.port, log_level="warning")
        return

    settings = Settings()  # values come from the environment / .env
    logs.configure(settings.log_level)
    monitoring.init(settings)  # Sentry, when SENTRY_DSN is set
    if args.command == "bootstrap":
        asyncio.run(_bootstrap(settings, args.recreate))
        return
    uvicorn.run(create_app(settings), host=settings.host, port=settings.port, log_config=None, access_log=False)


if __name__ == "__main__":
    main()
