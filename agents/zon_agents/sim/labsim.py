"""`zon-agents lab sim`: the golden cases through the production pipelines against the simulation's answers, and
every known result checked — before a GPU is paid for.

| run | what answers | what is known before it runs |
|---|---|---|
| `oracle` | the right answer | every case and every variant scores 1, no limit is touched |
| `adversary` | what each limit exists to catch | each limit trips on every case that can trip it |
| `positional` | the candidates as they came | the top three do not survive the opposite order |
| `broken` | never an answer of the schema | every call the model takes fails, and is recorded as failed |
| `rules` | the rule model (rules.py) | it always answers, and twice the same |

In a search or a matching the pipeline itself puts what breaks a numeric condition last (ranking.py), whatever the
model answered: what is known of `adversary` and `positional` there is what that leaves in the first three.

`rules` is also the floor: its scores are what the documented rules reach with no model at all.
"""

import asyncio
from collections.abc import Callable, Sequence
from datetime import UTC, datetime
from typing import Any

from pydantic_ai.exceptions import UnexpectedModelBehavior

from zon_agents.lab.cases import Case, ChatExpect, Grades, MatchingExpect, SearchersExpect
from zon_agents.lab.runner import CURRENT, RANKING, Outcome, report, run_cases
from zon_agents.lab.scoring import TOP
from zon_agents.llm import LLM, load_prompts
from zon_agents.pipelines.ranking import conditions_first
from zon_agents.profiles import SIMULATED
from zon_agents.schemas.agents import ListingCandidate, Need, RequestCandidate
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
    """A knowledge question with no passage is answered «not covered» by the pipeline itself."""
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


def _worst_three(case: Case, suffix: str, grades: Grades) -> list[int]:
    """The worst a model can make a search or a matching show."""
    name = {"": "candidates", "_matched": "matched", "_broaden": "broaden"}[suffix]
    return _first_three(case, name, oracle.worst_first(grades, case.input[name], int(case.input.get("limit", 10))))


def _wrong(outcome: Outcome, why: str) -> str:
    return f"{outcome.case} ({outcome.agent}, {outcome.variant}): {why} — score {outcome.score}, {outcome.metrics}"


def _oracle(outcome: Outcome, case: Case) -> list[str]:
    if outcome.error is not None or outcome.score != 1.0:
        return [_wrong(outcome, f"the right answer does not score 1 ({outcome.error or 'scored'})")]
    touched = [m for m, v in outcome.metrics.items() if m.startswith(("zero_top3", "inject_top3")) and v != 0.0]
    return [_wrong(outcome, f"the right answer touches {touched}")] if touched else []


def _adversary(outcome: Outcome, case: Case) -> list[str]:
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
            must(f"top1{suffix}", float(grades.grades.get(first[0], 0) == max(grades.grades.values())))
            if grades.inject is not None:
                must(f"inject_top3{suffix}", float(grades.inject in first))
            must(f"zero_top3{suffix}", float(any(grades.grades.get(i, 0) == 0 for i in first)))
        if "unit" in metrics:
            must("unit", 0.0)
    elif case.agent == "clean_listing":
        must("numbers", 0.0)
        if "invented" in metrics:
            must("invented", 0.0)
    elif case.agent == "summarize":
        must("numbers", 0.0)
        must("confidence", 0.0)
    elif case.agent == "report_write":
        must("guard", 0.0)
    elif case.agent == "chat" and not calls_the_model(case):
        if outcome.score != float(not case.expected(ChatExpect).covered):
            problems.append(_wrong(outcome, "the pipeline answers «not covered» by itself"))
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
    """How much of the top three a positional answer keeps when the candidates come in the opposite order."""
    name = "matched" if case.agent == "matching" else "candidates"
    given = [{"id": c["id"], "score": 0.0} for c in case.input[name]][: int(case.input.get("limit", 10))]
    first, last = set(_first_three(case, name, given)), set(_first_three(case, name, given[::-1]))
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
        if measured != known or known >= 0.8:
            problems.append(
                f"{agent}: stability of a positional answer is {measured}, known {known} (the limit is 0.8)"
            )
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
    problems = [problem for outcome in outcomes for problem in CHECKS[name](outcome, by_id[outcome.case])]
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
