"""`zon-agents serve` — the HTTP service · `zon-agents bootstrap [--recreate]` — the Qdrant collections ·
`zon-agents lab check|run` — the model lab (zon_agents/lab)."""

import argparse
import asyncio
import json
import os
import sys
import time
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import uvicorn

from zon_agents import logs
from zon_agents.config import Settings
from zon_agents.lab.cases import check, load_cases
from zon_agents.lab.runner import Outcome, report, run_cases
from zon_agents.llm import LLM, load_prompts, openai_model
from zon_agents.main import create_app
from zon_agents.profiles import load_profile
from zon_agents.services import build_store

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
    key = os.environ.get("LLM_API_KEY", "")  # never an argument: it would stay in the shell's history
    model = openai_model(args.base_url, key, args.model, args.budget)
    profile = load_profile(args.profile)
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
        )
    )
    result = report(
        outcomes, cases, llm, started=started, seconds=time.monotonic() - clock, gpu=args.gpu, notes=args.notes
    )
    out = args.out or Path(f"lab-{args.profile}-{started:%Y%m%d-%H%M}.json")
    out.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding="utf-8")
    print(file=sys.stderr)
    _print_summary(result, out)
    return 0


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
        print(
            f"{agent:<16}{entry['cases']:>6}{entry['score']:>8.3f}{entry['failed']:>8}"
            f"{entry['latency_ms']['p50']:>8}{entry['latency_ms']['p95']:>8}  {metrics}"
        )
    print(f"overall {result['overall']:.3f} → {out}")


def main() -> None:
    parser = argparse.ArgumentParser(prog="zon-agents")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("serve", help="run the HTTP service (one worker) on HOST:PORT")
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
    run.add_argument("--gpu", help="the card it ran on, for the report (A40, L40S, RTX PRO 6000…)")
    run.add_argument("--notes", help="anything a reader of the report should know")
    run.add_argument("--out", type=Path, help="the report file (default lab-<profile>-<time>.json)")
    args = parser.parse_args()

    if args.command == "lab":
        sys.exit(_lab_check() if args.lab == "check" else _lab_run(args))

    settings = Settings()  # values come from the environment / .env
    logs.configure(settings.log_level)
    if args.command == "bootstrap":
        asyncio.run(_bootstrap(settings, args.recreate))
        return
    uvicorn.run(create_app(settings), host=settings.host, port=settings.port, log_config=None, access_log=False)


if __name__ == "__main__":
    main()
