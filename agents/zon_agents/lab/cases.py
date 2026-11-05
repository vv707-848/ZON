"""The golden cases (`cases/<agent>.jsonl`): one JSON object per line, checked before any run.

    {"id": "...", "agent": "...", "tags": [...], "input": {what Laravel sends}, "context": {what the fixed retrieval
     returns}, "expect": {what a right answer satisfies}, "note": "..."}

`report_select` cases carry only the question: the catalog is `cases/catalog.json`, the one Laravel builds (a backend
test keeps the two identical).
"""

import hashlib
import json
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, ValidationError

from zon_agents.schemas import agents as schemas

CASES_DIR = Path(__file__).parent / "cases"
CATALOG_FILE = CASES_DIR / "catalog.json"

Agent = Literal[
    "search_listings",
    "search_requests",
    "matching",
    "match_searchers",
    "clean_listing",
    "summarize",
    "chat",
    "report_select",
    "report_write",
]
Terms = list[list[str]]  # groups of synonyms: a group is met when any of its words is in the text

INPUTS: dict[str, type[BaseModel]] = {
    "search_listings": schemas.SearchListingsInput,
    "search_requests": schemas.SearchRequestsInput,
    "matching": schemas.MatchingInput,
    "match_searchers": schemas.MatchSearchersInput,
    "clean_listing": schemas.CleanListingInput,
    "summarize": schemas.SummarizeInput,
    "chat": schemas.ChatInput,
    "report_select": schemas.ReportSelectInput,
    "report_write": schemas.ReportWriteInput,
}


class _Strict(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)


class Grades(_Strict):
    """0 = must not be offered (breaks a condition) · 1 = fits, none of the wishes · 2 = some · 3 = all."""

    grades: dict[int, int]
    inject: int | None = None  # the candidate whose text tries to order the model


class MatchingExpect(_Strict):
    matched: Grades
    broaden: Grades | None = None


class SearchersExpect(Grades):
    units: dict[int, list[int]] = {}  # searcher → the unit models that fit them best (any of them is right)


class CleanExpect(_Strict):
    features: Terms
    absent: list[str] = []  # never in the answer: what the advertiser did not say, an exaggeration
    not_features: list[str] = []  # never a feature: the advertiser said the opposite («بدون مصعد»)
    keep: Terms = []  # in the clean description


class SummaryExpect(_Strict):
    signals: Terms = []
    keep: Terms = []
    confidence: list[Literal["high", "medium", "low"]]


class ChatExpect(_Strict):
    covered: bool
    cite: list[int] = []  # documents any of which a covered answer cites
    mention: Terms = []
    lang: Literal["en", "ur"] | None = None  # the answer is in the asker's language


class SelectExpect(_Strict):
    report: str | list[str]  # a list when two reports answer the question equally
    params: dict[str, Any] = {}  # None: the parameter stays unset

    @property
    def reports(self) -> list[str]:
        return [self.report] if isinstance(self.report, str) else self.report


class WriteExpect(_Strict):
    mention: Terms = []


EXPECTS: dict[str, type[BaseModel]] = {
    "search_listings": Grades,
    "search_requests": Grades,
    "matching": MatchingExpect,
    "match_searchers": SearchersExpect,
    "clean_listing": CleanExpect,
    "summarize": SummaryExpect,
    "chat": ChatExpect,
    "report_select": SelectExpect,
    "report_write": WriteExpect,
}


class Case(_Strict):
    id: str = Field(pattern=r"^[a-z0-9][a-z0-9\-]{2,60}$")
    agent: Agent
    tags: list[str] = []
    input: dict[str, Any]
    context: dict[str, Any] = {}
    expect: dict[str, Any]
    note: str = ""

    def expected[T: BaseModel](self, model: type[T]) -> T:
        return model.model_validate(self.expect)


def catalog() -> list[dict[str, Any]]:
    return list(json.loads(CATALOG_FILE.read_text(encoding="utf-8")))


def load_cases(directory: Path = CASES_DIR) -> list[Case]:
    cases: list[Case] = []
    report_catalog = catalog() if (directory / CATALOG_FILE.name).exists() else []
    for path in sorted(directory.glob("*.jsonl")):
        for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
            if not line.strip():
                continue
            try:
                case = Case.model_validate_json(line)
            except ValidationError as exc:
                raise ValueError(f"{path.name}:{number}: {exc.errors()[0]['msg']}") from exc
            if case.agent == "report_select" and "catalog" not in case.input:
                case = case.model_copy(update={"input": {**case.input, "catalog": report_catalog}})
            cases.append(case)
    return cases


def digest(cases: list[Case]) -> str:
    """The dataset's fingerprint: two runs compare only when it is the same."""
    lines = sorted(case.model_dump_json() for case in cases)
    return hashlib.sha256("\n".join(lines).encode()).hexdigest()[:16]


def _candidate_ids(case: Case) -> dict[str, set[int]]:
    data = case.input
    if case.agent == "matching":
        return {"matched": {c["id"] for c in data["matched"]}, "broaden": {c["id"] for c in data.get("broaden", [])}}
    if case.agent == "match_searchers":
        return {"searchers": {s["id"] for s in data["searchers"]}, "units": {u["id"] for u in data["units"]}}
    return {"candidates": {c["id"] for c in data["candidates"]}}


def _graded(name: str, grades: Grades, ids: set[int]) -> list[str]:
    problems = []
    if set(grades.grades) != ids:
        problems.append(f"{name}: every candidate is graded, and only candidates")
    if not any(g > 0 for g in grades.grades.values()):
        problems.append(f"{name}: at least one right answer")
    if any(not 0 <= g <= 3 for g in grades.grades.values()):
        problems.append(f"{name}: grades are 0..3")
    if grades.inject is not None and grades.grades.get(grades.inject) != 0:
        problems.append(f"{name}: the injected candidate is graded 0")
    return problems


def check(cases: list[Case]) -> list[str]:
    """Everything wrong with the cases (empty = valid): inputs Laravel could send, expectations that fit them."""
    problems: list[str] = []
    seen: set[str] = set()
    for case in cases:
        where = f"{case.id} ({case.agent})"
        if case.id in seen:
            problems.append(f"{where}: duplicate id")
        seen.add(case.id)
        try:
            INPUTS[case.agent].model_validate(case.input)
            expect = case.expected(EXPECTS[case.agent])
        except ValidationError as exc:
            problems.append(f"{where}: {exc.errors()[0]['loc']} {exc.errors()[0]['msg']}")
            continue
        found: list[str] = []
        if isinstance(expect, MatchingExpect):
            ids = _candidate_ids(case)
            found += _graded("matched", expect.matched, ids["matched"])
            if expect.broaden is not None:
                found += _graded("broaden", expect.broaden, ids["broaden"])
            elif ids["broaden"]:
                found.append("broaden: graded when given")
        elif isinstance(expect, SearchersExpect):
            ids = _candidate_ids(case)
            found += _graded("searchers", expect, ids["searchers"])
            named = {unit for units in expect.units.values() for unit in units}
            if not set(expect.units) <= ids["searchers"] or not named <= ids["units"]:
                found.append("units: a searcher and a unit model of the case")
        elif isinstance(expect, Grades):
            found += _graded("candidates", expect, _candidate_ids(case)["candidates"])
        elif isinstance(expect, ChatExpect):
            docs = {p["doc_id"] for p in case.context.get("passages", [])}
            if not set(expect.cite) <= docs:
                found.append("cite: documents of the case's passages")
            if expect.covered and case.input["intent"] == "knowledge" and not expect.cite:
                found.append("cite: a covered knowledge answer names its documents")
        elif isinstance(expect, SelectExpect):
            names = {entry["report"]: entry for entry in case.input["catalog"]}
            for report in expect.reports:
                if report not in names:
                    found.append(f"report {report!r} is not in the catalog")
                elif not set(expect.params) <= set(names[report]["params"]):
                    found.append(f"params: declared parameters of {report}")
        problems += [f"{where}: {p}" for p in found]
    return problems
