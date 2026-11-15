"""Runs the cases through the production pipelines against one endpoint and writes the report.

Only the model differs between two runs: the pipeline is the production one (prompt, schema, retry, checks), the
retrieval is fixed (candidates in Laravel's order, the case's passages), the date is fixed, and every call has the
production budget — a model too slow for it fails here as it would in the app. Up to `concurrency` cases run at
once, as many as the engine takes (MAXREQ), so each latency is one request's, not a queue's.
"""

import asyncio
import time
from collections import defaultdict
from collections.abc import Awaitable, Callable, Sequence
from contextvars import ContextVar
from dataclasses import asdict, dataclass
from datetime import UTC, date, datetime
from typing import Any

from pydantic import BaseModel

from zon_agents.embeddings import Embedding
from zon_agents.errors import DependencyUnavailable
from zon_agents.lab.cases import Case, digest
from zon_agents.lab.scoring import ids, score, stability
from zon_agents.lab.variants import variants
from zon_agents.llm import LLM, LlmResult, ModelFailed
from zon_agents.pipelines import PIPELINES
from zon_agents.pipelines.base import Ctx, Result
from zon_agents.profiles import Use
from zon_agents.store import Hit, KnowledgeChunk

REPORT_VERSION = 1
TODAY = date(2026, 11, 5)  # the cases' «today»: a knowledge passage in force on it stays in force on every run
RANKING = ("search_listings", "search_requests", "matching", "match_searchers")
# How much each agent weighs in the overall score: what the users meet first and most counts most.
WEIGHTS = {
    "matching": 3,
    "search_listings": 3,
    "search_requests": 2,
    "clean_listing": 2,
    "chat": 2,
    "match_searchers": 1,
    "summarize": 1,
    "report_select": 1,
    "report_explore": 1,
    "report_write": 1,
}


# The case a pipeline is running for, readable from inside the model call: the simulation's lab answers
# (zon_agents/sim/oracle.py) are built from the case's expected answer. A real model never reads it.
CURRENT: ContextVar[Case | None] = ContextVar("lab_case", default=None)


class FixedRetrieval:
    """The shortlist in the order the candidates came (Laravel's: the closest price first), and the case's
    passages for the chat — so the model alone decides the answer."""

    def __init__(self, passages: Sequence[KnowledgeChunk]) -> None:
        self._passages = list(passages)

    async def query(self, text: str, terms: Sequence[str] = ()) -> Embedding:
        return Embedding([], [], [], [])

    async def rank(self, collection: str, query: Embedding, ids: Sequence[int], limit: int) -> list[Hit]:
        size = max(len(ids), 1)
        return [Hit(i, round(1 - position / (2 * size), 3)) for position, i in enumerate(list(ids)[:limit])]

    async def knowledge(self, question: str, today: date) -> list[KnowledgeChunk]:
        return list(self._passages)


def _passages(case: Case) -> list[KnowledgeChunk]:
    return [
        KnowledgeChunk(
            doc_id=p["doc_id"],
            title=p["title"],
            category=p.get("category", "faq"),
            version="1",
            effective_date=p.get("effective_date", "2026-01-01"),
            source=p.get("source", "زون"),
            next_review="2027-01-01",
            lang="ar",
            chunk_no=n,
            text=p["text"],
        )
        for n, p in enumerate(case.context.get("passages", []), start=1)
    ]


@dataclass
class Outcome:
    case: str
    agent: str
    variant: str
    round: int
    score: float
    metrics: dict[str, float]
    error: str | None
    latency_ms: int
    tokens_in: int
    tokens_out: int
    output: dict[str, Any] | None


async def _one(
    llm: LLM,
    case: Case,
    variant: str,
    round_: int,
    slots: asyncio.Semaphore,
    before: Callable[[], Awaitable[None]] | None = None,
) -> Outcome:
    ctx = Ctx(llm, FixedRetrieval(_passages(case)), today=lambda: TODAY)

    def outcome(
        error: str | None, elapsed: int, result: Result | None = None, value: float = 0.0, metrics: Any = None
    ) -> Outcome:
        return Outcome(
            case=case.id,
            agent=case.agent,
            variant=variant,
            round=round_,
            score=value,
            metrics=metrics or {},
            error=error,
            latency_ms=elapsed,
            tokens_in=result.tokens_in if result else 0,
            tokens_out=result.tokens_out if result else 0,
            output=result.output if result else None,
        )

    async with slots:
        if before is not None:
            await before()
        CURRENT.set(case)  # this task's own context: concurrent cases do not see one another's
        start = time.perf_counter()
        try:
            result = await PIPELINES[case.agent](ctx, dict(case.input))
        except DependencyUnavailable as exc:
            return outcome(f"model: {exc}", _ms(start))
        except Exception as exc:  # one broken case must not end a paid GPU session: recorded, the run goes on
            return outcome(f"{type(exc).__name__}: {exc}", _ms(start))
        elapsed = _ms(start)
    if result.degraded:
        return outcome(f"model: {result.log.get('llm', 'failed')}", elapsed, result)
    scored = score(case, result.output)
    metrics = {name: round(value, 4) for name, value in scored.metrics.items()}
    return outcome(None, elapsed, result, round(scored.value, 4), metrics)


class _NoModel(LLM):
    """The model that never answers: a ranking pipeline then returns the order the retrieval left — what the user
    gets with no model at all."""

    def __init__(self, like: LLM) -> None:
        self.prompts, self.profile = like.prompts, like.profile

    async def run[T: BaseModel](
        self, agent: str, payload: BaseModel | dict[str, Any], output: type[T], use: Use
    ) -> LlmResult[T]:
        raise ModelFailed("unavailable")


async def floors(llm: LLM, cases: Sequence[Case]) -> dict[str, float]:
    """Per ranking agent, the score of the answer with no model. A model's score is read against it: NDCG stays
    high even for an order nobody chose (every candidate is still in the list), so a model that does not pass its
    floor adds nothing to what the database already orders."""
    silent = _NoModel(llm)
    scores: dict[str, list[float]] = defaultdict(list)
    for case in cases:
        if case.agent in RANKING:
            ctx = Ctx(silent, FixedRetrieval(_passages(case)), today=lambda: TODAY)
            result = await PIPELINES[case.agent](ctx, dict(case.input))
            scores[case.agent].append(score(case, result.output).value)
    return {agent: _mean(values) for agent, values in sorted(scores.items())}


def _ms(start: float) -> int:
    return round((time.perf_counter() - start) * 1000)


async def run_cases(
    llm: LLM,
    cases: Sequence[Case],
    *,
    concurrency: int = 8,
    repeat: int = 1,
    with_variants: bool = True,
    progress: Callable[[Outcome], None] | None = None,
    per_minute: int | None = None,
) -> list[Outcome]:
    """[per_minute]: at most that many cases started in a minute, one at a time — for an endpoint that limits
    requests (a hosted one). A refused request would be scored as the model's failure, so the run stays under it."""
    slots = asyncio.Semaphore(1 if per_minute else concurrency)
    gap = 60 / per_minute if per_minute else 0.0
    last_start = 0.0
    jobs: list[tuple[Case, str, int]] = []
    for round_ in range(1, repeat + 1):
        for case in cases:
            jobs.append((case, "base", round_))
            if with_variants:
                jobs += [(changed, name, round_) for name, changed in variants(case)]

    async def paced() -> None:
        nonlocal last_start
        wait = last_start + gap - time.monotonic()
        if wait > 0:
            await asyncio.sleep(wait)
        last_start = time.monotonic()

    async def tracked(case: Case, variant: str, round_: int) -> Outcome:
        result = await _one(llm, case, variant, round_, slots, paced if gap else None)
        if progress is not None:
            progress(result)
        return result

    return list(await asyncio.gather(*(tracked(*job) for job in jobs)))


def _mean(values: Sequence[float]) -> float:
    return round(sum(values) / len(values), 4) if values else 0.0


def _percentile(values: Sequence[int], p: int) -> int:
    ordered = sorted(values)
    return ordered[max(0, -(-p * len(ordered) // 100) - 1)] if ordered else 0


def _top(outcome: Outcome) -> list[int]:
    output = outcome.output or {}
    return ids(output.get("matched" if outcome.agent == "matching" else "results", []))


def _agent_entry(agent: str, outcomes: Sequence[Outcome]) -> dict[str, Any]:
    base = [o for o in outcomes if o.variant == "base"]
    names = sorted({name for o in base for name in o.metrics})
    timed = [o.latency_ms for o in base if o.error is None] or [o.latency_ms for o in base]
    entry: dict[str, Any] = {
        "cases": len({o.case for o in base}),
        "score": _mean([o.score for o in base]),
        "metrics": {name: _mean([o.metrics[name] for o in base if name in o.metrics]) for name in names},
        "failed": sum(o.error is not None for o in base),
        "latency_ms": {"p50": _percentile(timed, 50), "p95": _percentile(timed, 95)},
        "variants": {},
    }
    for variant in sorted({o.variant for o in outcomes} - {"base"}):
        changed = [o for o in outcomes if o.variant == variant]
        entry["variants"][variant] = {
            "score": _mean([o.score for o in changed]),
            "failed": sum(o.error is not None for o in changed),
        }
        if variant == "reversed" and agent in RANKING:
            pairs = {(o.case, o.round): o for o in base if o.error is None}
            kept = [
                stability(_top(pairs[(o.case, o.round)]), _top(o))
                for o in changed
                if (o.case, o.round) in pairs and o.error is None
            ]
            entry["variants"][variant]["stability"] = _mean(kept)
    return entry


def report(
    outcomes: Sequence[Outcome],
    cases: Sequence[Case],
    llm: LLM,
    *,
    started: datetime,
    seconds: float,
    gpu: str | None,
    notes: str | None,
    floor: dict[str, float] | None = None,
) -> dict[str, Any]:
    by_agent: dict[str, list[Outcome]] = defaultdict(list)
    for outcome in outcomes:
        by_agent[outcome.agent].append(outcome)
    agents = {agent: _agent_entry(agent, by_agent[agent]) for agent in sorted(by_agent)}
    for agent, value in (floor or {}).items():
        if agent in agents:
            agents[agent]["floor"] = value  # `floors`: the same cases with no model
    weight = sum(WEIGHTS[a] for a in agents)
    tokens_out = sum(o.tokens_out for o in outcomes)
    counts: dict[str, int] = defaultdict(int)
    for case in cases:
        counts[case.agent] += 1
    return {
        "lab": REPORT_VERSION,
        "profile": llm.profile.model_dump(),
        "prompts": {name: prompt.version for name, prompt in sorted(llm.prompts.items())},
        "gpu": gpu,
        "notes": notes,
        "dataset": {"digest": digest(list(cases)), "cases": len(cases), "agents": dict(sorted(counts.items()))},
        "started_at": started.astimezone(UTC).isoformat(timespec="seconds"),
        "duration_s": round(seconds, 1),
        "overall": round(sum(WEIGHTS[a] * e["score"] for a, e in agents.items()) / weight, 4) if weight else 0.0,
        "agents": agents,
        "throughput": {
            "calls": len(outcomes),
            "tokens_in": sum(o.tokens_in for o in outcomes),
            "tokens_out": tokens_out,
            "tokens_out_per_s": round(tokens_out / seconds, 1) if seconds else 0.0,
        },
        "outcomes": [asdict(o) for o in outcomes],
    }
