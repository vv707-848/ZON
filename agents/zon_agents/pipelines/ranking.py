"""The ranking agents: semantic search (listings · requests), the matching assistant and the developer's searchers.

Laravel builds the candidates in SQL (mandatory city · neighbourhood · type; the budget only orders). Here:
1. **Shortlist:** Qdrant ranks the candidates against the user's free text, never the whole collection.
2. **Rank:** the model orders the shortlist, scores it and gives a reason of twelve words — and judges the wishes
   (budget, rooms, area, age, deed, bank) from the fields and the cleaned descriptions.
3. **Check:** every id must be a candidate; anything else is dropped silently.

If the model fails, the answer is `degraded`: the hybrid order with its scores and no reasons. If the embedding
server or Qdrant fails, the call fails (503) and Laravel answers with the SQL order (§7 «سياسة الفشل»).
"""

from collections.abc import Sequence
from typing import Any

from zon_agents.embeddings import Embedding
from zon_agents.llm import ModelFailed
from zon_agents.pipelines.base import Ctx, Result, clip_words, parse
from zon_agents.schemas.agents import (
    ListingCandidate,
    MatchingAnswer,
    MatchingInput,
    MatchSearchersInput,
    Need,
    Ranked,
    RequestCandidate,
    SearchAnswer,
    Searcher,
    SearchersAnswer,
    SearchListingsInput,
    SearchRequestsInput,
    UnitBrief,
    UnitCandidate,
)
from zon_agents.store import LISTINGS, PROJECT_UNITS, REQUESTS

SHORTLIST = 30  # candidates the model reads after the hybrid query (§10.1 step 8 → 9)

Candidate = ListingCandidate | UnitCandidate | RequestCandidate


async def _query(ctx: Ctx, need: Need, terms: Sequence[str]) -> Embedding | None:
    return await ctx.retrieval.query(need.free_text, terms) if need.free_text.strip() else None


async def _shortlist[C: Candidate](
    ctx: Ctx, collection: str, query: Embedding | None, candidates: Sequence[C], size: int = SHORTLIST
) -> list[tuple[C, float]]:
    """The candidates in hybrid order, then — up to `size` — those not indexed yet in Laravel's (budget) order.
    Without free text there is nothing to embed: Laravel's order is kept."""
    if not candidates:
        return []
    by_id = {c.id: c for c in candidates}
    ranked: list[tuple[C, float]] = []
    if query is not None:
        hits = await ctx.retrieval.rank(collection, query, list(by_id), size)
        ranked = [(by_id[h.id], h.score) for h in hits if h.id in by_id]
    seen = {c.id for c, _ in ranked}
    rest = [c for c in candidates if c.id not in seen]
    for position, candidate in enumerate(rest[: max(0, size - len(ranked))]):
        ranked.append((candidate, round(max(0.0, 0.5 - position / (2 * size)), 3)))
    return ranked


def _checked(
    answer: Sequence[Ranked], shortlist: Sequence[tuple[Candidate, float]], limit: int
) -> list[dict[str, Any]]:
    """Only candidates, each once, in the model's order; reasons cut to twelve words."""
    allowed = {c.id for c, _ in shortlist}
    out: list[dict[str, Any]] = []
    for item in answer:
        if item.id in allowed:
            out.append({"id": item.id, "score": round(item.score, 3), "reason": clip_words(item.reason)})
            allowed.discard(item.id)
        if len(out) == limit:
            break
    return out


def _plain(shortlist: Sequence[tuple[Candidate, float]], limit: int) -> list[dict[str, Any]]:
    """The degraded answer: hybrid order and scores, no reason."""
    return [{"id": c.id, "score": score, "reason": ""} for c, score in shortlist[:limit]]


def _context(shortlist: Sequence[tuple[Candidate, float]]) -> list[dict[str, Any]]:
    return [c.model_dump(exclude_none=True) for c, _ in shortlist]


async def search_listings(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(SearchListingsInput, data)
    shortlist = await _shortlist(ctx, LISTINGS, await _query(ctx, req.need, req.terms), req.candidates)
    return await _rank_one(ctx, "search_listings", req.need, shortlist, req.limit)


async def search_requests(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(SearchRequestsInput, data)
    shortlist = await _shortlist(ctx, REQUESTS, await _query(ctx, req.need, req.terms), req.candidates)
    return await _rank_one(ctx, "search_requests", req.need, shortlist, req.limit)


async def _rank_one(
    ctx: Ctx, agent: str, need: Need, shortlist: Sequence[tuple[Candidate, float]], limit: int
) -> Result:
    if not shortlist:
        return Result({"results": [], "query_understood": ""})
    payload = {"need": need.model_dump(exclude_none=True), "candidates": _context(shortlist), "limit": limit}
    try:
        answer = await ctx.llm.run(agent, payload, SearchAnswer, "json")
    except ModelFailed as exc:
        return Result(
            {"results": _plain(shortlist, limit), "query_understood": ""}, degraded=True, log={"llm": exc.reason}
        )
    output = {
        "results": _checked(answer.output.results, shortlist, limit),
        "query_understood": clip_words(answer.output.query_understood, 20),
    }
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


async def matching(ctx: Ctx, data: dict[str, Any]) -> Result:
    """Two listing lists that never overlap («مطابق» · «توسيع الإدراك») with reasons, and unit models ranked by
    their own descriptions — Laravel shows the winners as project cards, without a reason (§10.3 path هـ)."""
    req = parse(MatchingInput, data)
    query = await _query(ctx, req.need, req.terms)
    matched_ids = {c.id for c in req.matched}
    matched = await _shortlist(ctx, LISTINGS, query, req.matched)
    broaden = await _shortlist(ctx, LISTINGS, query, [c for c in req.broaden if c.id not in matched_ids])
    units = await _shortlist(ctx, PROJECT_UNITS, query, req.units)
    unit_scores = [{"id": c.id, "score": score} for c, score in units[: req.limit]]
    if not matched and not broaden:
        return Result({"matched": [], "broaden": [], "units": unit_scores})

    payload = {
        "need": req.need.model_dump(exclude_none=True),
        "matched": _context(matched),
        "broaden": _context(broaden),
        "limit": req.limit,
    }
    try:
        answer = await ctx.llm.run("matching", payload, MatchingAnswer, "json")
    except ModelFailed as exc:
        output = {"matched": _plain(matched, req.limit), "broaden": _plain(broaden, req.limit), "units": unit_scores}
        return Result(output, degraded=True, log={"llm": exc.reason})
    output = {
        "matched": _checked(answer.output.matched, matched, req.limit),
        "broaden": _checked(answer.output.broaden, broaden, req.limit),
        "units": unit_scores,
    }
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


def _fit(searcher: Searcher, units: Sequence[UnitBrief]) -> tuple[float, int | None]:
    """How far a searcher's budget and rooms are from the nearest unit model (0 = exact), and that model."""
    target = None
    if searcher.budget_min is not None or searcher.budget_max is not None:
        low = searcher.budget_min if searcher.budget_min is not None else searcher.budget_max
        high = searcher.budget_max if searcher.budget_max is not None else searcher.budget_min
        target = ((low or 0) + (high or 0)) / 2
    best: tuple[float, int | None] = (float("inf"), None)
    for unit in units:
        distance = 0.0
        if target and unit.price:
            distance += abs(unit.price - target) / target
        if searcher.bedrooms is not None and unit.bedrooms is not None:
            distance += abs(unit.bedrooms - searcher.bedrooms) * 0.15
        if searcher.type and unit.type and searcher.type != unit.type:
            distance += 1
        best = min(best, (distance, unit.id), key=lambda pair: pair[0])
    return best


async def match_searchers(ctx: Ctx, data: dict[str, Any]) -> Result:
    """The developer's first tab (§10.3 path ب): searchers whose wish fits the project's available unit models.
    Wishes are not indexed — SQL lists the newest; a budget/rooms fit keeps the closest 60 for the model, which
    reads what each searcher wrote and names the nearest model."""
    req = parse(MatchSearchersInput, data)
    if not req.searchers:
        return Result({"results": []})
    fits = sorted(((_fit(s, req.units), s) for s in req.searchers), key=lambda pair: pair[0][0])[: SHORTLIST * 2]
    unit_ids = {u.id for u in req.units}
    payload = {
        "project": req.project.model_dump(exclude_none=True),
        "units": [u.model_dump(exclude_none=True) for u in req.units],
        "searchers": [s.model_dump(exclude_none=True) for _, s in fits],
        "limit": req.limit,
    }
    try:
        answer = await ctx.llm.run("match_searchers", payload, SearchersAnswer, "json")
    except ModelFailed as exc:
        plain = [
            {"id": s.id, "score": round(1 / (1 + distance), 3), "reason": "", "unit_id": unit}
            for (distance, unit), s in fits[: req.limit]
        ]
        return Result({"results": plain}, degraded=True, log={"llm": exc.reason})

    allowed = {s.id for _, s in fits}
    results: list[dict[str, Any]] = []
    for item in answer.output.results:
        if item.id not in allowed:
            continue
        allowed.discard(item.id)
        unit = item.unit_id if item.unit_id in unit_ids else None
        results.append(
            {"id": item.id, "score": round(item.score, 3), "reason": clip_words(item.reason), "unit_id": unit}
        )
        if len(results) == req.limit:
            break
    return Result({"results": results}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)
