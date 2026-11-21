"""«lab load»: the ranking cases at the sizes production sends, several at once, on the node itself.

What the golden cases cannot say: their inputs are a few candidates with short descriptions (search: under 4,000
characters), while Laravel sends candidates of up to 600 characters each, of which the model reads a shortlist — 15
in search, 17 per list in matching, each description cut at 400 (pipelines/ranking.py, issue #119) — and up to 100
unit models and 60 seekers to the project's searchers. That is what decides whether the context (CTXLEN) is enough,
and whether a prompt read on the card fits the time budget when users ask at the same time.

Each agent's first case is filled to those sizes (its own candidates repeated under new ids, its own texts repeated
to the limit Laravel cuts them at), then asked [at_once] at a time for [rounds] rounds. Per agent: the input tokens
the engine counted (its own tokenizer, not an estimate), latency p50 · p95, and the failures — a prompt past the
context or past the budget fails exactly as in production. No score: the added candidates have no expected order.
"""

import asyncio
import copy
import time
from collections.abc import Sequence
from typing import Any

from zon_agents.errors import DependencyUnavailable
from zon_agents.lab.cases import Case
from zon_agents.lab.runner import CURRENT, TODAY, FixedRetrieval, _percentile
from zon_agents.llm import LLM
from zon_agents.pipelines import PIPELINES, ranking
from zon_agents.pipelines.base import Ctx
from zon_agents.schemas.agents import ListingCandidate, Need, RequestCandidate

# list field → (items, characters of its text) as Laravel sends them (ListingCandidates · RequestCandidates ·
# UnitCandidates cut a description at 600, DeveloperMatching a unit's at 400, ProjectAudience a seeker's notes at 500).
# The pipelines then cut what the model reads, as in production: the ranking shortlists (15 · 17 per list, each
# description at 400) and the project's searchers (60 after the fit, from up to 200).
SIZES: dict[str, dict[str, tuple[int, int]]] = {
    "search_listings": {"candidates": (30, 600)},
    "search_requests": {"candidates": (30, 600)},
    "matching": {"matched": (30, 600), "broaden": (30, 600)},
    "match_searchers": {"units": (100, 400), "searchers": (200, 500)},
}
_TEXT = {"searchers": "notes"}  # every other list's text is its `desc`
FREE_TEXT = 1000  # the most a seeker may write (config zon.ai.free_text_max)


def _long(text: str, size: int) -> str:
    text = text.strip() or "شقة واسعة قريبة من الخدمات والمدارس والمساجد"
    return " ".join([text] * (size // (len(text) + 1) + 1))[:size]


def production_size(case: Case) -> Case:
    """[case] with every list at production's size: its own items repeated under new ids, its texts at the limit."""
    data = copy.deepcopy(dict(case.input))
    next_id = 1_000_000
    for field, (count, chars) in SIZES[case.agent].items():
        given = _keepers(case.agent, field, data.get("need"), data.get(field) or [])
        if not given:
            continue
        text = _TEXT.get(field, "desc")
        items = []
        for n in range(count):
            item = dict(given[n % len(given)])
            item["id"] = next_id
            item[text] = _long(str(item.get(text, "")), chars)
            items.append(item)
            next_id += 1
        data[field] = items
    if isinstance(data.get("need"), dict):
        data["need"]["free_text"] = _long(str(data["need"].get("free_text", "")), FREE_TEXT)
    return case.model_copy(update={"id": f"{case.id}-production", "input": data})


def _keepers(agent: str, field: str, need: Any, items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """The items the shortlist keeps (ranking._shortlist: budget, rooms, stated conditions): repeated, they make a
    full list for the model to read — production's 30 are what SQL let through, not what the rules then take out.
    All of them when none would be kept (the case is about something else)."""
    if (
        agent not in ("search_listings", "search_requests", "matching")
        or not isinstance(need, dict)
        or field == "units"
    ):
        return items
    wish = Need(**need)
    kind = RequestCandidate if agent == "search_requests" else ListingCandidate
    rule = ranking.fits_broaden if field == "broaden" else ranking.fits
    kept = [c for c in items if rule(wish, kind(**c)) and not ranking.contradicted(wish, kind(**c))]
    return kept or items


async def _timed(llm: LLM, case: Case) -> tuple[int, int, str | None]:
    """(latency ms, input tokens the engine counted, the failure or None)."""
    CURRENT.set(case)
    ctx = Ctx(llm, FixedRetrieval([]), today=lambda: TODAY)
    start = time.perf_counter()
    try:
        result = await PIPELINES[case.agent](ctx, dict(case.input))
    except DependencyUnavailable as exc:
        return round((time.perf_counter() - start) * 1000), 0, f"model: {exc}"
    elapsed = round((time.perf_counter() - start) * 1000)
    failed = f"model: {result.log.get('llm', 'failed')}" if result.degraded else None
    return elapsed, result.tokens_in, failed


async def load(llm: LLM, cases: Sequence[Case], *, at_once: int = 8, rounds: int = 3) -> dict[str, dict[str, Any]]:
    """Per agent of [SIZES] present in [cases]: [at_once] different cases together, each at production's size,
    [rounds] times over — eight users ask eight things, not one prompt eight times (review of 21 Nov)."""
    report: dict[str, dict[str, Any]] = {}
    for agent in SIZES:
        own = [c for c in cases if c.agent == agent]
        if not own:
            continue
        first = own[0]
        bigs = [production_size(own[n % len(own)]) for n in range(at_once)]
        runs: list[tuple[int, int, str | None]] = []
        for _ in range(rounds):
            runs += await asyncio.gather(*(_timed(llm, big) for big in bigs))
        latencies = [ms for ms, _, _ in runs]
        failures: dict[str, int] = {}
        for _, _, error in runs:
            if error:
                failures[error] = failures.get(error, 0) + 1
        report[agent] = {
            "case": first.id,
            "cases": sorted({c.id for c in own[:at_once]}),
            "asked": len(runs),
            "at_once": at_once,
            "failed": sum(failures.values()),
            "failures": failures,
            "tokens_in_max": max(tokens for _, tokens, _ in runs),
            "p50_ms": _percentile(latencies, 50),
            "p95_ms": _percentile(latencies, 95),
        }
    return report
