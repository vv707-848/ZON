"""What each agent receives from Laravel and returns to it (contract §3).

`…Input` models validate the request (422 on failure). `…Answer` models are the schema the model must follow
(NativeOutput: SGLang constrains generation to it); pipelines check them again before answering.
"""

from typing import Annotated, Any, Literal

from pydantic import BaseModel, Field

from zon_agents.schemas.common import Id, Payload, Place, PType, Score

MAX_RESULTS = 10  # architecture §7.5: ten results with a reason, not twenty-five
Reason = Annotated[str, Field(max_length=160)]

# ── ranking ─────────────────────────────────────────────────────────────────────────────────────


class Need(Payload):
    """The masked need: mandatory city · neighbourhood · type, then wishes the model judges."""

    city: Place
    neighborhood: Place
    property_type: PType
    purpose: Literal["sale", "rent"] | None = None
    budget_min: float | None = None
    budget_max: float | None = None
    bedrooms: int | None = None
    free_text: Annotated[str, Field(max_length=1000)] = ""


class ListingCandidate(Payload):
    id: Id
    type: str | None = None
    neighborhood: str | None = None
    price: float | None = None
    area: float | None = None
    bedrooms: int | None = None
    bathrooms: int | None = None
    age: int | None = None
    deed: bool | None = None
    bank: bool | None = None
    desc: str = ""


class UnitCandidate(ListingCandidate):
    model: str | None = None
    project_id: Id | None = None


class RequestCandidate(Payload):
    id: Id
    types: list[str] = []
    neighborhoods: list[str] = []
    budget_min: float | None = None
    budget_max: float | None = None
    rooms_min: int | None = None
    area_min: float | None = None
    urgent: bool = False
    desc: str = ""


Terms = Annotated[list[Annotated[str, Field(max_length=60)]], Field(max_length=20)]
Limit = Annotated[int, Field(ge=1, le=MAX_RESULTS)]


class SearchListingsInput(Payload):
    need: Need
    terms: Terms = []
    candidates: Annotated[list[ListingCandidate], Field(max_length=300)]
    limit: Limit = MAX_RESULTS


class SearchRequestsInput(Payload):
    need: Need
    terms: Terms = []
    candidates: Annotated[list[RequestCandidate], Field(max_length=300)]
    limit: Limit = MAX_RESULTS


class MatchingInput(Payload):
    need: Need
    terms: Terms = []
    matched: Annotated[list[ListingCandidate], Field(max_length=300)] = []
    broaden: Annotated[list[ListingCandidate], Field(max_length=300)] = []
    units: Annotated[list[UnitCandidate], Field(max_length=300)] = []
    limit: Limit = MAX_RESULTS


class ProjectBrief(Payload):
    type: str | None = None
    neighborhood: str | None = None
    context: str = ""


class UnitBrief(Payload):
    id: Id
    model: str | None = None
    type: str | None = None
    area: float | None = None
    bedrooms: int | None = None
    price: float | None = None
    desc: str = ""


class Searcher(Payload):
    id: Id
    type: str | None = None
    budget_min: float | None = None
    budget_max: float | None = None
    bedrooms: int | None = None
    notes: str = ""


class MatchSearchersInput(Payload):
    project: ProjectBrief
    units: Annotated[list[UnitBrief], Field(min_length=1, max_length=100)]
    searchers: Annotated[list[Searcher], Field(max_length=200)]
    limit: Limit = MAX_RESULTS


class Ranked(BaseModel):
    id: int
    score: Score
    reason: Reason = ""


class SearchAnswer(BaseModel):
    results: list[Ranked]
    query_understood: Annotated[str, Field(max_length=160)] = ""


class MatchingAnswer(BaseModel):
    matched: list[Ranked]
    broaden: list[Ranked]


class RankedSearcher(Ranked):
    unit_id: int | None = None


class SearchersAnswer(BaseModel):
    results: list[RankedSearcher]


# ── chat ────────────────────────────────────────────────────────────────────────────────────────

ChatIntent = Literal["knowledge", "packages", "semantic_search", "search_request", "matching"]


class Exchange(Payload):
    q: Annotated[str, Field(max_length=1000)]
    a: Annotated[str, Field(max_length=2000)]


class PackageFact(Payload):
    name: str
    price: float | None = None
    period: str | None = None
    features: list[str] = []


class ChatInput(Payload):
    lang: Literal["ar", "en", "ur"] = "ar"
    intent: ChatIntent
    message: Annotated[str, Field(min_length=1, max_length=1000)]
    history: Annotated[list[Exchange], Field(max_length=1)] = []
    packages: list[PackageFact] = []


class Citation(BaseModel):
    passage: Annotated[int, Field(ge=1, description="number of the cited passage, as given in the context")]
    quote_ar: Annotated[str, Field(max_length=400)] = ""


class ChatAnswer(BaseModel):
    answer: Annotated[str, Field(min_length=1, max_length=1500)]
    covered: bool
    citations: list[Citation] = []


# ── summaries ───────────────────────────────────────────────────────────────────────────────────


class Note(Payload):
    category: str = ""
    date: str = ""
    text: Annotated[str, Field(min_length=1, max_length=2000)]


class SummarizeInput(Payload):
    scope: Literal["client", "owner", "tasks"]
    notes: Annotated[list[Note], Field(min_length=1, max_length=60)]


class SummaryAnswer(BaseModel):
    summary: Annotated[str, Field(min_length=1, max_length=600)]
    signals: Annotated[list[Annotated[str, Field(max_length=60)]], Field(max_length=6)] = []
    recommended_action: Annotated[str, Field(max_length=300)] = ""
    confidence: Literal["high", "medium", "low"]


# ── text cleaning (intake) ──────────────────────────────────────────────────────────────────────


class CleanListingInput(Payload):
    type: str
    city: str = ""
    neighborhood: str = ""
    fields: dict[str, Any] = {}
    text: Annotated[str, Field(min_length=1, max_length=4000)]


class CleanListingAnswer(BaseModel):
    desc_clean: Annotated[str, Field(min_length=1, max_length=2000)]
    features: Annotated[list[Annotated[str, Field(max_length=40)]], Field(max_length=12)] = []


class CleanProjectInput(Payload):
    type: str = ""
    text: Annotated[str, Field(min_length=1, max_length=4000)]


class CleanProjectAnswer(BaseModel):
    desc_clean: Annotated[str, Field(min_length=1, max_length=2000)]


class UnitText(Payload):
    id: Id
    model: str = ""
    type: str = ""
    area: float | None = None
    bedrooms: int | None = None
    bathrooms: int | None = None
    halls: int | None = None
    text: Annotated[str, Field(min_length=1, max_length=2000)]


class CleanUnitsInput(Payload):
    project_type: str = ""
    units: Annotated[list[UnitText], Field(min_length=1, max_length=40)]


class CleanedUnit(BaseModel):
    id: int
    desc_clean: Annotated[str, Field(min_length=1, max_length=1200)]


class CleanUnitsAnswer(BaseModel):
    units: list[CleanedUnit]


# ── SuperAgent ──────────────────────────────────────────────────────────────────────────────────


class CatalogEntry(Payload):
    report: Annotated[str, Field(pattern=r"^[a-z_]{3,60}$")]
    description: str
    params: dict[str, dict[str, Any]] = {}  # JSON schema of every parameter of the template


class ReportSelectInput(Payload):
    question: Annotated[str, Field(min_length=3, max_length=500)]
    catalog: Annotated[list[CatalogEntry], Field(min_length=1, max_length=40)]


class ReportWriteInput(Payload):
    question: Annotated[str, Field(max_length=500)]
    report: str
    columns: dict[str, str] = {}
    rows: Annotated[list[dict[str, Any]], Field(max_length=200)]


class ReportWriteAnswer(BaseModel):
    analysis: Annotated[str, Field(min_length=1, max_length=2500)]
    recommendations: Annotated[list[Annotated[str, Field(max_length=300)]], Field(max_length=5)] = []


class BatchInput(Payload):
    mode: Literal["map", "reduce"]
    purpose: Annotated[str, Field(max_length=200)]
    texts: Annotated[list[Annotated[str, Field(max_length=1000)]], Field(max_length=500)] = []
    summaries: Annotated[list[Annotated[str, Field(max_length=4000)]], Field(max_length=100)] = []


class Theme(BaseModel):
    theme: Annotated[str, Field(max_length=120)]
    share: Literal["high", "medium", "low"]


class BatchAnswer(BaseModel):
    summary: Annotated[str, Field(min_length=1, max_length=2000)]
    themes: Annotated[list[Theme], Field(max_length=10)] = []
