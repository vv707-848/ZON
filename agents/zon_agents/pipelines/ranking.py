"""The ranking agents: semantic search (listings · requests), the matching assistant and the developer's searchers.

Laravel builds the candidates in SQL (mandatory city · neighbourhood · type; the budget only orders), and reads
into the need a budget and a room count that only the free text states (StatedNumbers). Here:
1. **Shortlist:** Qdrant ranks the candidates against the user's free text, never the whole collection.
2. **Rank:** the model reads the shortlist and writes its best few, ordered and scored, judging the wishes (budget,
   rooms, area, age, deed, bank) from the fields and the cleaned descriptions. It writes nothing else: the app shows
   no explanation under a result, so the model's whole answer is ids and scores. When it wrote as many as it was
   asked, the list goes on to Laravel's `limit` in the retrieval's order; fewer means it found no more that fit.
3. **Check:** every id must be a candidate; anything else is dropped silently.
4. **Conditions:** a result that breaks a condition the need states in numbers — its price more than a tenth over
   the top of the budget, or fewer rooms than asked — goes after every result that does not. Nothing is removed.
   The model orders by what the texts say and lets a close wording outrank a broken budget (measured: a request
   whose budget the offer broke was in the first three in 5 cases of 6, twice); arithmetic is the code's.

5. **Both orders:** search and matching ask the model twice at once, the shortlist as it came and reversed (what
   keeps the wish still first, each part reversed: `flipped`), and
   keep each candidate's mean score of the two (absent from one answer: 0 there). A model that prefers what comes
   first cannot carry that into the answer (A40, 21 Nov: the first three survived the reversal in 0.70 · 0.71 · 0.61
   of the cases at temperature 0). If one of the two fails, the other answers alone.

If the model fails, the answer is `degraded`: the hybrid order with its scores. If the embedding server or Qdrant
fails, the call fails (503) and Laravel answers with the SQL order (§7 «سياسة الفشل»).
"""

import asyncio
import math
import os
from collections import Counter
from collections.abc import Callable, Mapping, Sequence
from typing import Any

from pydantic import BaseModel

from zon_agents.conditions import contradicts
from zon_agents.embeddings import Embedding
from zon_agents.llm import LlmResult, ModelFailed
from zon_agents.pipelines.base import Ctx, Result, parse
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

# What the model reads after the hybrid query (§10.1 step 8 → 9), and what it writes. The owner's bounds (issue #119):
# at production's 30 candidates of 600 characters, eight users at once ran past the 15-second budget on the A40, and
# a listing's facts are fields while its description is a short note. What it writes is the time: on the A40 a
# search read in under a second and wrote its ten results (~16 tokens each) in about five; the five it now writes
# are those a user sees first, chosen from all it reads.
SEARCH_SHORTLIST = 15  # search_listings · search_requests: what the model reads
SEARCH_WRITES = 5  # ... and writes: its best, ordered; the rest of the list in the retrieval's order
MATCHING_SHORTLIST = 8  # matching: «مطابق» and «توسيع الإدراك», each — it runs for saved wishes twice a day
MATCHING_WRITES = 5  # each list
DESC_CHARS = 400  # a candidate's description as the ranking model reads it (clean_listing reads a listing whole)
# How search and matching ask the model (ZON_RANK_MODE; the lab's --rank-mode), until the A40 decides between them:
# - "both": twice at once, the shortlist and its reversal, each candidate at its mean score (step 5 above);
# - "compact": once, each candidate's description cut at COMPACT_DESC_CHARS — the need, the searcher's conditions,
#   always whole. Half the calls and a shorter read, at the price of the order the candidates came in.
RANK_MODES = ("both", "compact")
RANK_MODE = os.environ.get("ZON_RANK_MODE", "both")
COMPACT_DESC_CHARS = 160
SEARCHERS = 15  # match_searchers: the searchers the model reads, the closest to a unit model by budget and rooms
SEARCHER_UNITS = 8  # ... and the unit models, the nearest to those searchers first
SEARCHER_CHARS = 350  # a searcher's notes and a unit model's description as the model reads them
SEARCHERS_WRITES = 5  # its best searchers, each with a unit model; the rest by that closeness
SHORTLIST = 30  # unit models in matching: scored by retrieval, not shown to the model
OVER_BUDGET = 1.10  # a price above the top of the budget by more than this breaks it (docs/model-lab.md)

Candidate = ListingCandidate | UnitCandidate | RequestCandidate


async def _query(ctx: Ctx, need: Need, terms: Sequence[str]) -> Embedding | None:
    return await ctx.retrieval.query(need.free_text, terms) if need.free_text.strip() else None


async def _shortlist[C: Candidate](
    ctx: Ctx,
    collection: str,
    query: Embedding | None,
    candidates: Sequence[C],
    size: int = SHORTLIST,
    need: Need | None = None,
    keep: Callable[[Need, Candidate], bool] | None = None,
) -> list[tuple[C, float]]:
    """The candidates in hybrid order, then those not indexed yet, each scored below every hybrid hit (a score is
    never above one placed before it); without free text there is nothing to embed.

    With a `need` (owner's decision, 21 Nov 2026), only what keeps it is a candidate at all: its budget and rooms
    (`fits`) for a result, or what «عروض أخرى» asks (`fits_broaden`), and no condition it states outright said
    the opposite of (`contradicted`). Two that keep it are two results, never
    filled with what breaks it — after the model, without it, or when it times out. And the order the model reads
    is fixed by the candidates themselves, never by the order they came in: Qdrant's score, then the id; without
    retrieval (no free text, or not indexed yet), the price's distance from the top of the budget, then the id."""
    if not candidates:
        return []
    by_id = {c.id: c for c in candidates}
    ranked: list[tuple[C, float]] = []
    if query is not None:
        hits = await ctx.retrieval.rank(collection, query, list(by_id), len(by_id) if need else size)
        ranked = [(by_id[h.id], h.score) for h in hits if h.id in by_id]
    seen = {c.id for c, _ in ranked}
    top = min([0.5, *(score for _, score in ranked)])
    rest = [c for c in candidates if c.id not in seen]
    if need is not None:
        gap = budget_gap(need, [(c, 0.0) for c in rest])
        rest.sort(key=lambda c: (gap(c.id), c.id))
    whole = len(rest) if need else max(0, size - len(ranked))
    for position, candidate in enumerate(rest[:whole]):
        ranked.append((candidate, round(max(0.0, top * (1 - (position + 1) / (2 * max(size, len(rest))))), 3)))
    if need is not None:
        rule = keep or fits
        kept = (p for p in ranked if rule(need, p[0]) and not contradicted(need, p[0]))
        ranked = sorted(kept, key=lambda p: (-p[1], p[0].id))[:size]
    return ranked


def contradicted(need: Need, candidate: Candidate) -> bool:
    """The candidate says the opposite of a condition stated outright (conditions.py: «دور أرضي» to an upper floor,
    «عوائل فقط» to singles…). A request is read the other way round: its own conditions, against the broker's offer."""
    if isinstance(candidate, RequestCandidate):
        return contradicts(candidate.desc, need.free_text, need.property_type)
    return contradicts(need.free_text, candidate.desc, candidate.type)


def _checked(
    answer: Sequence[Ranked], shortlist: Sequence[tuple[Candidate, float]], limit: int
) -> list[dict[str, Any]]:
    """Only candidates, each once, in the model's order."""
    allowed = {c.id for c, _ in shortlist}
    out: list[dict[str, Any]] = []
    for item in answer:
        if item.id in allowed:
            out.append({"id": item.id, "score": round(item.score, 3)})
            allowed.discard(item.id)
        if len(out) == limit:
            break
    return out


def flipped(need: Need, shortlist: Sequence[tuple[Candidate, float]], chars: int = DESC_CHARS) -> list[dict[str, Any]]:
    """The shortlist the other way round as the model reads it: what keeps the wish still first, each part reversed
    — so a list and its reversal are read as the same two orders, and what breaks the wish is never read first."""
    keep = [pair for pair in shortlist if not breaks(need, pair[0])]
    late = [pair for pair in shortlist if breaks(need, pair[0])]
    return _context([*keep[::-1], *late[::-1]], chars)


async def both_orders[T: BaseModel](
    ctx: Ctx, agent: str, payload: dict[str, Any], other: dict[str, Any], schema: type[T]
) -> list[LlmResult[T]]:
    """The model's answers to [payload] and to [other] (its lists the other way round), asked at once; one that
    failed is left out, and both failing is the first one's failure."""
    found = await asyncio.gather(
        ctx.llm.run(agent, payload, schema, "rank"), ctx.llm.run(agent, other, schema, "rank"), return_exceptions=True
    )
    answers = [a for a in found if not isinstance(a, BaseException)]
    if answers:
        return answers
    for failure in found:
        if not isinstance(failure, ModelFailed):
            raise failure  # type: ignore[misc]
    raise found[0]  # type: ignore[misc]


def mean_of(lists: Sequence[list[dict[str, Any]]]) -> list[dict[str, Any]]:
    """Each candidate written in any of [lists] (checked: candidates, once each), with its mean score over all of
    them — 0 in a list that left it out."""
    scores: dict[int, float] = {}
    for written in lists:
        for r in written:
            scores[r["id"]] = scores.get(r["id"], 0.0) + r["score"]
    return [{"id": i, "score": round(total / len(lists), 3)} for i, total in scores.items()]


def canonical(results: list[dict[str, Any]], gap: Callable[[int], float] = lambda _: 0.0) -> list[dict[str, Any]]:
    """What the model wrote, by its score; an exact tie goes to the closest to the budget (`gap`), then the lower
    id — the prompts' own rule and the rule model's, so two equal answers never take the order the candidates came
    in (#119, stability)."""
    return sorted(results, key=lambda r: (-r["score"], gap(r["id"]), r["id"]))


def budget_gap(need: Need, shortlist: Sequence[tuple[Candidate, float]]) -> Callable[[int], float]:
    """How far a candidate's price (a request: its budget) is from the top of the budget (the broker's price)."""
    by_id = {c.id: c for c, _ in shortlist}

    def gap(i: int) -> float:
        c = by_id.get(i)
        value = None if c is None else c.budget_max if isinstance(c, RequestCandidate) else c.price
        return abs(value - need.budget_max) if value is not None and need.budget_max is not None else math.inf

    return gap


def _then_retrieval(
    written: list[dict[str, Any]],
    shortlist: Sequence[tuple[Candidate, float]],
    asked: int,
    limit: int,
    wrote: int | None = None,
) -> list[dict[str, Any]]:
    """The model's results; when it wrote as many as it was asked (or as it read, if fewer), then the rest of the
    shortlist in its order up to `limit`, none scored above the result before it. Fewer than asked: it found no
    more that fit. An answer of which nothing is a candidate (`wrote` something, none of it read) is no answer:
    the shortlist's own order."""
    if (wrote if not written and wrote else len(written)) < min(asked, len(shortlist)):
        return written
    out = list(written)
    taken = {r["id"] for r in out}
    floor = min((r["score"] for r in out), default=1.0)
    for candidate, score in shortlist:
        if len(out) >= limit:
            break
        if candidate.id not in taken:
            floor = min(floor, score)
            out.append({"id": candidate.id, "score": round(floor, 3)})
    return out


def breaks(need: Need, candidate: Candidate) -> bool:
    """The candidate breaks a condition of the need that both state in numbers. A request is read the other way
    round: the need is the broker's offer, and the condition is the request's own budget and rooms."""
    if isinstance(candidate, RequestCandidate):
        price, top, rooms, asked = need.budget_max, candidate.budget_max, need.bedrooms, candidate.rooms_min
    else:
        price, top, rooms, asked = candidate.price, need.budget_max, candidate.bedrooms, need.bedrooms
        if asked and rooms is None:
            return True  # rooms asked for and not stated: not shown to meet them
    over = bool(top) and price is not None and price > (top or 0) * OVER_BUDGET
    few = bool(asked) and rooms is not None and rooms < (asked or 0)
    return over or few


def fits(need: Need, candidate: Candidate) -> bool:
    """A result of the need: within its budget (with OVER_BUDGET's margin) and its rooms."""
    return not breaks(need, candidate)


def fits_broaden(need: Need, candidate: Candidate) -> bool:
    """«عروض أخرى» (Laravel's ListingCandidates::broaden, whose area it checks): within the top of the budget, no
    margin, and not fewer rooms than asked — an offer that does not state its price or rooms is not shown to."""
    if isinstance(candidate, RequestCandidate):
        return fits(need, candidate)
    if need.budget_max is None or candidate.price is None or candidate.price > need.budget_max:
        return False
    return not need.bedrooms or (candidate.bedrooms is not None and candidate.bedrooms >= need.bedrooms)


def _broken(need: Need, shortlist: Sequence[tuple[Candidate, float]]) -> set[int]:
    return {c.id for c, _ in shortlist if breaks(need, c)}


def conditions_first(
    results: list[dict[str, Any]], need: Need, shortlist: Sequence[tuple[Candidate, float]]
) -> list[dict[str, Any]]:
    """What breaks a condition after what does not, each group in the order it had; a result moved down does not
    keep a score above the ones now before it."""
    broken = {c.id for c, _ in shortlist if breaks(need, c)}
    kept = [r for r in results if r["id"] not in broken]
    late = [r for r in results if r["id"] in broken]
    if not kept or not late:
        return results
    lowest = min(r["score"] for r in kept)
    return kept + [{**r, "score": min(r["score"], lowest)} for r in late]


def _plain(shortlist: Sequence[tuple[Candidate, float]], limit: int) -> list[dict[str, Any]]:
    """The degraded answer: the shortlist's order and scores, none above the one before it."""
    floor, out = 1.0, []
    for c, score in shortlist[:limit]:
        floor = min(floor, score)
        out.append({"id": c.id, "score": floor})
    return out


def _context(shortlist: Sequence[tuple[Candidate, float]], chars: int = DESC_CHARS) -> list[dict[str, Any]]:
    """The candidates as the model reads them: their fields, and the description cut at [chars]."""
    return [{**c.model_dump(exclude_none=True), "desc": c.desc[:chars]} for c, _ in shortlist]


async def _ask[T: BaseModel](
    ctx: Ctx,
    agent: str,
    need: Need,
    lists: Mapping[str, Sequence[tuple[Candidate, float]]],
    rest: dict[str, Any],
    schema: type[T],
) -> list[LlmResult[T]]:
    """The model's answers by RANK_MODE: both orders at full descriptions, or one call at compact ones."""
    if RANK_MODE == "compact":
        payload = {
            "need": need.model_dump(exclude_none=True),
            **{n: _context(s, COMPACT_DESC_CHARS) for n, s in lists.items()},
            **rest,
        }
        return [await ctx.llm.run(agent, payload, schema, "rank")]
    payload = {"need": need.model_dump(exclude_none=True), **{n: _context(s) for n, s in lists.items()}, **rest}
    other = {**payload, **{n: flipped(need, s) for n, s in lists.items()}}
    return await both_orders(ctx, agent, payload, other, schema)


async def search_listings(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(SearchListingsInput, data)
    shortlist = await _shortlist(
        ctx, LISTINGS, await _query(ctx, req.need, req.terms), req.candidates, SEARCH_SHORTLIST, req.need
    )
    return await _rank_one(ctx, "search_listings", req.need, shortlist, req.limit)


async def search_requests(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(SearchRequestsInput, data)
    shortlist = await _shortlist(
        ctx, REQUESTS, await _query(ctx, req.need, req.terms), req.candidates, SEARCH_SHORTLIST, req.need
    )
    return await _rank_one(ctx, "search_requests", req.need, shortlist, req.limit)


async def _rank_one(
    ctx: Ctx, agent: str, need: Need, shortlist: Sequence[tuple[Candidate, float]], limit: int
) -> Result:
    if not shortlist:
        return Result({"results": []})
    asked = min(limit, SEARCH_WRITES)
    try:
        answers = await _ask(ctx, agent, need, {"candidates": shortlist}, {"limit": asked}, SearchAnswer)
    except ModelFailed as exc:  # timed out or failed: the same rule, the shortlist's own order
        return Result({"results": _plain(shortlist, limit)}, degraded=True, log={"llm": exc.reason})
    each = [_checked(a.output.results, shortlist, asked) for a in answers]
    written = canonical(mean_of(each), budget_gap(need, shortlist))[: max(len(w) for w in each)]
    wrote = max(len(a.output.results) for a in answers)
    output = {"results": _then_retrieval(written, shortlist, asked, limit, wrote)}
    return Result(output, tokens_in=_sum(answers, "in"), tokens_out=_sum(answers, "out"), log={"orders": len(answers)})


async def matching(ctx: Ctx, data: dict[str, Any]) -> Result:
    """Two listing lists that never overlap («مطابق» · «توسيع الإدراك»: what the budget buys in the whole city, any
    neighbourhood and type — Laravel's ListingCandidates::broaden), and unit models ranked by their own
    descriptions — Laravel shows the winners as project cards (§10.3 path هـ)."""
    req = parse(MatchingInput, data)
    query = await _query(ctx, req.need, req.terms)
    matched_ids = {c.id for c in req.matched}
    matched = await _shortlist(ctx, LISTINGS, query, req.matched, MATCHING_SHORTLIST, req.need)
    others = [c for c in req.broaden if c.id not in matched_ids]
    broaden = await _shortlist(ctx, LISTINGS, query, others, MATCHING_SHORTLIST, req.need, fits_broaden)
    units = await _shortlist(ctx, PROJECT_UNITS, query, req.units)
    unit_scores = [{"id": c.id, "score": score} for c, score in units[: req.limit]]
    if not matched and not broaden:
        return Result({"matched": [], "broaden": [], "units": unit_scores})

    asked = min(req.limit, MATCHING_WRITES)
    try:
        lists = {"matched": matched, "broaden": broaden}
        answers = await _ask(ctx, "matching", req.need, lists, {"limit": asked}, MatchingAnswer)
    except ModelFailed as exc:  # timed out or failed: the same rules, each shortlist's own order
        output = {"matched": _plain(matched, req.limit), "broaden": _plain(broaden, req.limit), "units": unit_scores}
        return Result(output, degraded=True, log={"llm": exc.reason})
    gap = budget_gap(req.need, [*matched, *broaden])
    ranked: dict[str, Any] = {}
    for name, shortlist in (("matched", matched), ("broaden", broaden)):
        each = [_checked(getattr(a.output, name), shortlist, asked) for a in answers]
        written = canonical(mean_of(each), gap)[: max(len(w) for w in each)]
        wrote = max(len(getattr(a.output, name)) for a in answers)
        ranked[name] = _then_retrieval(written, shortlist, asked, req.limit, wrote)
    return Result(
        {**ranked, "units": unit_scores},
        tokens_in=_sum(answers, "in"),
        tokens_out=_sum(answers, "out"),
        log={"orders": len(answers)},
    )


def _sum(answers: Sequence[LlmResult[Any]], side: str) -> int:
    return sum(a.tokens_in if side == "in" else a.tokens_out for a in answers)


def fit(searcher: Searcher, units: Sequence[UnitBrief]) -> tuple[float, int | None]:
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
    """The developer's first tab (§10.3 path ب): one project, and the searchers whose wish fits its available unit
    models. Laravel sends the live wishes of the project's city, neighbourhood and types (and turns them over from
    one run to the next: ProjectAudience); wishes are not indexed, so a budget/rooms fit keeps the closest 15 for the
    model, which reads what each searcher wrote and names the nearest unit model."""
    req = parse(MatchSearchersInput, data)
    if not req.searchers:
        return Result({"results": []})
    fits = sorted(((fit(s, req.units), s) for s in req.searchers), key=lambda pair: pair[0][0])[:SEARCHERS]
    # the unit models those searchers are nearest to first: 100 models of 400 characters with 60 searchers of 500
    # were 52,370 characters, and eight such prompts at once ran past the 15-second budget (lab load, #119)
    nearest = Counter(unit for (_, unit), _ in fits if unit is not None)
    units = sorted(req.units, key=lambda u: -nearest[u.id])[:SEARCHER_UNITS]
    unit_ids = {u.id for u in units}
    asked = min(req.limit, SEARCHERS_WRITES)
    payload = {
        "project": req.project.model_dump(exclude_none=True),
        "units": [{**u.model_dump(exclude_none=True), "desc": u.desc[:SEARCHER_CHARS]} for u in units],
        "searchers": [{**s.model_dump(exclude_none=True), "notes": s.notes[:SEARCHER_CHARS]} for _, s in fits],
        "limit": asked,
    }
    try:
        answer = await ctx.llm.run("match_searchers", payload, SearchersAnswer, "rank")
    except ModelFailed as exc:
        plain = [
            {"id": s.id, "score": round(1 / (1 + distance), 3), "unit_id": unit}
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
        results.append({"id": item.id, "score": round(item.score, 3), "unit_id": unit})
        if len(results) == asked:
            break
    closeness = {s.id: d for (d, _), s in fits}
    results = canonical(results, lambda i: closeness.get(i, math.inf))
    if len(results) == asked:  # all it was asked: the rest by closeness, each with its nearest model
        floor = min((r["score"] for r in results), default=1.0)
        for (distance, unit), s in fits:
            if len(results) >= req.limit:
                break
            if s.id in allowed:
                floor = min(floor, round(1 / (1 + distance), 3))
                results.append({"id": s.id, "score": floor, "unit_id": unit})  # one of the project's own
    return Result({"results": results}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)
