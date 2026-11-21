"""`zon-agents lab sim`: the golden cases through the production pipelines against the simulation's answers, and
every known result checked — before a GPU is paid for.

| run | what answers | what is known before it runs |
|---|---|---|
| `oracle` | the right answer | every case and every variant scores its ceiling (below), no limit is touched |
| `adversary` | what each limit exists to catch | each limit trips on every case that can trip it |
| `positional` | the candidates as they came | asked in both orders (ranking.py), its top three survive the reversal |
| `broken` | never an answer of the schema | every call the model takes fails, and is recorded as failed |
| `rules` | the rule model (rules.py) | it always answers, and twice the same |

In a search or a matching the pipeline itself puts what breaks a numeric condition last (ranking.py), whatever the
model answered: what is known of `adversary` and `positional` there is what that leaves in the first three.

`rules` is also the floor: its scores are what the documented rules reach with no model at all.

A ranking model writes only its best few of what it reads (ranking.py: `SEARCH_WRITES` …), and the list goes on in
the retrieval's order — the order given, in the lab. A case with more fitting candidates than that cannot score 1:
its ceiling is what the right answer scores with the rest in the given order, built here without the pipeline.
"""

import asyncio
import math
from collections import Counter
from collections.abc import Callable, Sequence
from datetime import UTC, datetime
from typing import Any

from pydantic_ai.exceptions import UnexpectedModelBehavior

from zon_agents.lab.cases import Case, ChatExpect, Grades, MatchingExpect, SearchersExpect
from zon_agents.lab.runner import CURRENT, RANKING, Outcome, report, run_cases
from zon_agents.lab.scoring import TOP, score
from zon_agents.lab.variants import variants
from zon_agents.llm import LLM, load_prompts
from zon_agents.pipelines import ranking
from zon_agents.pipelines.ranking import conditions_first
from zon_agents.profiles import SIMULATED
from zon_agents.schemas.agents import ListingCandidate, Need, RequestCandidate, Searcher, UnitBrief
from zon_agents.sim import oracle, rules
from zon_agents.sim.model import Answerer, function_model

RUNS = ("oracle", "adversary", "positional", "broken", "rules")
BUDGET_SECONDS = 15.0


def _from_case(build: Callable[[Case, dict[str, Any]], dict[str, Any]]) -> Answerer:
    def answer(agent: str, payload: dict[str, Any]) -> dict[str, Any]:
        case = CURRENT.get()
        if case is None or case.agent != agent:
            raise RuntimeError("the lab's answers are built from the running case")
        return build(case, payload)

    return answer


def _broken(agent: str, payload: dict[str, Any]) -> dict[str, Any]:
    raise UnexpectedModelBehavior("not an answer of the schema")


ANSWERERS: dict[str, Answerer] = {
    "oracle": _from_case(oracle.perfect),
    "adversary": _from_case(oracle.adversary),
    "positional": _from_case(oracle.positional),
    "broken": _broken,
    "rules": rules.answer,
}


def simulated_llm(run: str) -> LLM:
    prompts = load_prompts()
    return LLM(
        function_model(ANSWERERS[run], prompts),
        prompts,
        profile=SIMULATED,
        max_concurrency=8,
        budget_seconds=BUDGET_SECONDS,
    )


async def run(name: str, cases: Sequence[Case]) -> list[Outcome]:
    return await run_cases(simulated_llm(name), cases)


# ── what is known before a run ──────────────────────────────────────────────────────────────────


def calls_the_model(case: Case) -> bool:
    """A knowledge question with no passage is answered «not covered» by the pipeline itself, and a plan question
    by its pointer to the plans page (no price from the chat, 21 Nov)."""
    if case.agent == "chat" and case.input["intent"] == "packages":
        return False
    return not (case.agent == "chat" and case.input["intent"] == "knowledge" and not case.context.get("passages"))


def _lists(case: Case) -> list[tuple[str, Grades]]:
    """The graded lists of a ranking case, with the suffix of their metrics."""
    if case.agent == "matching":
        matching = case.expected(MatchingExpect)
        return [("_matched", matching.matched)] + ([("_broaden", matching.broaden)] if matching.broaden else [])
    return [("", case.expected(SearchersExpect if case.agent == "match_searchers" else Grades))]


def _first_three(case: Case, name: str, answered: list[dict[str, Any]]) -> list[int]:
    """The first three of an answer once the pipeline has put what breaks a numeric condition last."""
    kind = RequestCandidate if case.agent == "search_requests" else ListingCandidate
    shortlist = [(kind(**candidate), 0.0) for candidate in case.input[name]]
    return [r["id"] for r in conditions_first(answered, Need(**case.input["need"]), shortlist)][:TOP]


_BASE: dict[str, Case] = {}  # each case as given, while a run is verified


def _bounds(agent: str) -> tuple[int, int]:
    """What a ranking model reads of a list, and what it writes."""
    if agent == "matching":
        return ranking.MATCHING_SHORTLIST, ranking.MATCHING_WRITES
    if agent == "match_searchers":
        return ranking.SEARCHERS, ranking.SEARCHERS_WRITES
    return ranking.SEARCH_SHORTLIST, ranking.SEARCH_WRITES


def _completed(written: list[dict[str, Any]], read: list[dict[str, Any]], asked: int, limit: int) -> list[Any]:
    """What the model wrote; when it wrote all it was asked, the rest of what it read in the order given."""
    if len(written) < asked:
        return written
    taken = {r["id"] for r in written}
    return [*written, *({"id": c["id"], "score": 0.0} for c in read if c["id"] not in taken)][:limit]


def _as_listed(
    case: Case, name: str, grades: Grades, build: Callable[[Grades, list[dict[str, Any]], int], list[dict[str, Any]]]
) -> list[dict[str, Any]]:
    """A list the way the pipeline makes it of an answer built by `build`: read, written, completed, conditions last."""
    size, writes = _bounds(case.agent)
    limit = int(case.input.get("limit", 10))
    kind = RequestCandidate if case.agent == "search_requests" else ListingCandidate
    need = Need(**case.input["need"])

    rule = ranking.fits_broaden if name == "broaden" else ranking.fits
    # the shortlist (ranking._shortlist): only what keeps the wish (or what «عروض أخرى» asks), in an order fixed by
    # the candidates — the lab's retrieval scores by the base order (variants.base_order) when there is free text,
    # else the price's distance from the top of the budget; then the id
    base = _BASE.get(case.id, case).input.get(name, [])
    given = [c for c in case.input.get(name, []) if rule(need, kind(**c)) and not ranking.contradicted(need, kind(**c))]
    gap = ranking.budget_gap(need, [(kind(**c), 0.0) for c in given])
    if need.free_text.strip():
        index = {c["id"]: n for n, c in enumerate(base)}
        read = sorted(given, key=lambda c: (index.get(c["id"], len(base)), c["id"]))[:size]
    else:
        read = sorted(given, key=lambda c: (gap(c["id"]), c["id"]))[:size]
    asked = min(limit, writes)
    each = [build(grades, read, asked)]
    if ranking.RANK_MODE == "both":  # asked in both orders (ranking.both_orders · flipped · mean_of)
        each.append(build(grades, read[::-1], asked))
    written = ranking.canonical(ranking.mean_of(each), gap)[: max(len(w) for w in each)]
    return _completed(written, read, asked, limit)


def _worst_three(case: Case, suffix: str, grades: Grades) -> list[int]:
    """The worst a model can make a search or a matching show."""
    name = {"": "candidates", "_matched": "matched", "_broaden": "broaden"}[suffix]
    return [r["id"] for r in _as_listed(case, name, grades, oracle.worst_first)][:TOP]


def _ceiling(case: Case) -> float:
    """What the right answer scores through the pipeline: 1 when the model writes every fitting candidate."""
    if case.agent in ("search_listings", "search_requests"):
        return score(case, {"results": _as_listed(case, "candidates", case.expected(Grades), oracle.best_first)}).value
    if case.agent == "matching":
        lists = dict(_lists(case))
        out = {
            name: _as_listed(case, name, lists[f"_{name}"], oracle.best_first) if f"_{name}" in lists else []
            for name in ("matched", "broaden")
        }
        return score(case, out).value
    searchers = case.expected(SearchersExpect)

    def right(read: list[dict[str, Any]], asked: int) -> list[dict[str, Any]]:
        best = oracle.best_first(searchers, read, asked)
        return [{**r, "unit_id": next(iter(searchers.units.get(r["id"], [])), None)} for r in best]

    return score(case, _searchers_listed(case, right)).value


Written = Callable[[list[dict[str, Any]], int], list[dict[str, Any]]]


def _searchers_listed(case: Case, written: Written) -> dict[str, Any]:
    """A developer's list the way the pipeline makes it: the closest searchers read, the model's few written (a unit
    model it was not shown becomes none), then the rest by closeness, each with its nearest model."""
    size, writes = _bounds(case.agent)
    limit, units = int(case.input.get("limit", 10)), [UnitBrief(**u) for u in case.input["units"]]
    pairs = ((ranking.fit(Searcher(**s), units), s) for s in case.input["searchers"])
    fits = sorted(pairs, key=lambda f: f[0][0])[:size]
    nearest = Counter(unit for (_, unit), _ in fits if unit is not None)
    shown = {u.id for u in sorted(units, key=lambda u: -nearest[u.id])[: ranking.SEARCHER_UNITS]}
    asked = min(limit, writes)
    distance = {s["id"]: d for (d, _), s in fits}
    picked = ranking.canonical(written([s for _, s in fits], asked), lambda i: distance.get(i, math.inf))
    given = [{**r, "unit_id": r["unit_id"] if r["unit_id"] in shown else None} for r in picked]
    if len(given) < asked:
        return {"results": given}
    taken = {r["id"] for r in given}
    rest = [{"id": s["id"], "score": 0.0, "unit_id": unit} for (_, unit), s in fits if s["id"] not in taken]
    return {"results": [*given, *rest][:limit]}


def _worst_units(case: Case) -> float:
    """The unit models of the worst answer, every one invented (none), once the rest of the list follows them."""
    searchers = case.expected(SearchersExpect)

    def worst(read: list[dict[str, Any]], asked: int) -> list[dict[str, Any]]:
        return [{**r, "unit_id": None} for r in oracle.worst_first(searchers, read, asked)]

    return score(case, _searchers_listed(case, worst)).metrics["unit"]


def _wrong(outcome: Outcome, why: str) -> str:
    return f"{outcome.case} ({outcome.agent}, {outcome.variant}): {why} — score {outcome.score}, {outcome.metrics}"


def _oracle(outcome: Outcome, case: Case) -> list[str]:
    known = round(_ceiling(case), 4) if case.agent in RANKING else 1.0
    if outcome.error is not None or outcome.score != known:
        return [_wrong(outcome, f"the right answer does not score {known} ({outcome.error or 'scored'})")]
    touched = [m for m, v in outcome.metrics.items() if m.startswith(("zero_top3", "inject_top3")) and v != 0.0]
    return [_wrong(outcome, f"the right answer touches {touched}")] if touched else []


def _adversary(outcome: Outcome, case: Case) -> list[str]:
    if case.agent == "clean_listing":  # its invented price is refused by the pipeline itself (grounded_amounts)
        refused = outcome.error is not None and "amount" in outcome.error
        return [] if refused else [_wrong(outcome, "an amount the text does not state must be refused (amount)")]
    if outcome.error is not None:
        return [_wrong(outcome, outcome.error)]
    metrics, problems = outcome.metrics, []

    def must(name: str, value: float) -> None:
        if metrics.get(name) != value:
            problems.append(_wrong(outcome, f"{name} should be {value}"))

    if case.agent in RANKING:
        for suffix, grades in _lists(case):
            if case.agent == "match_searchers":  # no condition is the pipeline's there: the worst three as answered
                must(f"top1{suffix}", float(len(set(grades.grades.values())) == 1))
                if any(g == 0 for g in grades.grades.values()):
                    must(f"zero_top3{suffix}", 1.0)
                continue
            first = _worst_three(case, suffix, grades)
            if grades.empty:  # rightly empty: nothing is left to show after the rules, whatever the model wrote
                must(f"top1{suffix}", float(not first))
                continue
            must(f"top1{suffix}", float(bool(first) and grades.grades.get(first[0], 0) == max(grades.grades.values())))
            if grades.inject is not None:
                must(f"inject_top3{suffix}", float(grades.inject in first))
            must(f"zero_top3{suffix}", float(any(grades.grades.get(i, 0) == 0 for i in first)))
        if "unit" in metrics:  # 0 for what it wrote; the rest of the list has each searcher's nearest model
            must("unit", round(_worst_units(case), 4))
    elif case.agent == "summarize":
        must("numbers", 0.0)
        must("confidence", 0.0)
    elif case.agent == "report_write":
        must("guard", 0.0)
    elif case.agent == "chat" and not calls_the_model(case):
        right = case.input["intent"] == "packages" or not case.expected(ChatExpect).covered
        if outcome.score != float(right):
            problems.append(_wrong(outcome, "the pipeline answers by itself («not covered», or the plans pointer)"))
    elif case.agent == "chat" and case.input["intent"] == "knowledge" and not case.expected(ChatExpect).covered:
        # it claims a passage covers the question but quotes none of it: the pipeline keeps no source, so «not covered»
        if outcome.score != 1.0:
            problems.append(_wrong(outcome, "a citation that quotes nothing is no source"))
    elif outcome.score != 0.0:  # chat · report_select · report_explore
        problems.append(_wrong(outcome, "a wrong answer scores above 0"))
    return problems


def _broken_known(outcome: Outcome, case: Case) -> list[str]:
    failed = outcome.error is not None
    if failed != calls_the_model(case):
        return [_wrong(outcome, f"failed is {failed}: a call the model takes fails, one it does not take does not")]
    if failed and not (outcome.score == 0.0 and "invalid" in str(outcome.error)):
        return [_wrong(outcome, f"recorded as {outcome.error!r}")]
    return []


def _answered(outcome: Outcome, case: Case) -> list[str]:
    return [_wrong(outcome, outcome.error)] if outcome.error is not None else []


def _swapped_top(case: Case) -> float:
    """How much of the top three a positional answer keeps when the candidates come in the opposite order: asked in
    both orders (ranking.both_orders), the two answers are the same two whichever order came, so all of it."""
    name = "matched" if case.agent == "matching" else "candidates"

    def listed(of: Case) -> list[int]:
        given = _as_listed(of, name, Grades(grades={}), lambda _, read, asked: oracle.as_given(read, asked))
        return [r["id"] for r in given]

    first, last = set(listed(case)[:TOP]), set(listed(dict(variants(case))["reversed"])[:TOP])
    return len(first & last) / max(len(first), len(last), 1)


def _positional(outcomes: Sequence[Outcome], cases: Sequence[Case]) -> list[str]:
    """`stability` of every agent whose shortlist keeps the order it was given is the cases' own arithmetic."""
    by_id = {case.id: case for case in cases}
    summary = report(
        outcomes, cases, simulated_llm("positional"), started=datetime.now(UTC), seconds=0.0, gpu=None, notes=None
    )
    problems = []
    for agent in ("search_listings", "search_requests", "matching"):
        measured = summary["agents"][agent]["variants"]["reversed"]["stability"]
        base = [by_id[o.case] for o in outcomes if o.agent == agent and o.variant == "reversed"]
        known = round(sum(_swapped_top(case) for case in base) / len(base), 4)
        if measured != known:
            problems.append(f"{agent}: stability of a positional answer is {measured}, known {known}")
    return problems


CHECKS: dict[str, Callable[[Outcome, Case], list[str]]] = {
    "oracle": _oracle,
    "adversary": _adversary,
    "positional": _answered,
    "broken": _broken_known,
    "rules": _answered,
}


def verify(name: str, outcomes: Sequence[Outcome], cases: Sequence[Case]) -> list[str]:
    """Everything that is not as known before the run (empty = every result as known)."""
    by_id = {case.id: case for case in cases}
    _BASE.clear()
    _BASE.update(by_id)

    def asked(outcome: Outcome) -> Case:  # the variant's own case: its candidates in its own order
        base = by_id[outcome.case]
        return dict(variants(base)).get(outcome.variant, base)

    problems = [problem for outcome in outcomes for problem in CHECKS[name](outcome, asked(outcome))]
    if name == "positional":
        problems += _positional(outcomes, cases)
    return problems


def same_twice(first: Sequence[Outcome], second: Sequence[Outcome]) -> list[str]:
    def key(outcome: Outcome) -> tuple[str, str]:
        return outcome.case, outcome.variant

    again = {key(o): o.output for o in second}
    return [f"{o.case} ({o.variant}): another answer the second time" for o in first if again.get(key(o)) != o.output]


def summary(
    name: str, outcomes: Sequence[Outcome], cases: Sequence[Case], floor: dict[str, float] | None = None
) -> dict[str, Any]:
    """The report without what differs between two machines (times), and without each case's answer."""
    started = datetime(2026, 11, 5, tzinfo=UTC)
    full = report(outcomes, cases, simulated_llm(name), started=started, seconds=0.0, gpu=None, notes=None, floor=floor)
    for entry in full["agents"].values():
        entry["latency_ms"] = {"p50": 0, "p95": 0}
    return {
        "lab": full["lab"],
        "simulation": name,
        "dataset": full["dataset"],
        "overall": full["overall"],
        "agents": full["agents"],
    }


def run_all(cases: Sequence[Case], names: Sequence[str] = RUNS) -> dict[str, tuple[list[Outcome], list[str]]]:
    """Each run's outcomes and what is not as known."""

    async def every() -> dict[str, tuple[list[Outcome], list[str]]]:
        results = {}
        for name in names:
            outcomes = await run(name, cases)
            problems = verify(name, outcomes, cases)
            if name == "rules":
                problems += same_twice(outcomes, await run(name, cases))
            results[name] = (outcomes, problems)
        return results

    return asyncio.run(every())
