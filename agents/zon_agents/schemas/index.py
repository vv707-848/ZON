"""Index signals (contract §4) — built by Laravel's App\\Indexing\\IndexPayloads."""

from datetime import date, datetime
from typing import Annotated, Any, Literal

from pydantic import BeforeValidator, Field

from zon_agents.schemas.common import Id, Payload

Entity = Literal["listing", "project_unit", "request", "kb_document"]


class IndexSignal(Payload):
    entity: Entity
    entity_id: Id
    action: Literal["create", "update", "delete"]
    version: int = Field(ge=0)
    occurred_at: datetime | None = None
    payload: dict[str, Any] | None = None  # absent for delete


class ListingPayload(Payload):
    city: str
    neighborhood: str
    property_type: str
    purpose: str | None = None
    price: float | None = None
    area_land: float | None = None
    area_built: float | None = None
    bedrooms: int | None = None
    bathrooms: int | None = None
    age: int | None = None
    deed: bool | None = None
    bank: bool | None = None
    desc_clean: str = Field(min_length=1)
    status: str = "published"


class ProjectUnitPayload(Payload):
    project_id: Id
    city: str
    neighborhood: str
    property_type: str
    model: str
    area: float | None = None
    bedrooms: int | None = None
    bathrooms: int | None = None
    halls: int | None = None
    price: float | None = None
    desc_clean: str = Field(min_length=1)
    project_context: str | None = None  # the project's description: context, never mixed into the unit's text


class RequestPayload(Payload):
    city: str
    neighborhoods: list[str] = Field(min_length=1)
    property_types: list[str] = Field(min_length=1)
    purpose: str | None = None
    budget_min: float | None = None
    budget_max: float | None = None
    area_min: float | None = None
    rooms_min: int | None = None
    urgent: bool = False
    desc: str = ""


class KbDocumentPayload(Payload):
    title: str
    category: str
    lang: str = "ar"
    version: Annotated[str, BeforeValidator(str)]  # kb_documents.version is a string(20)
    effective_date: date
    source: str
    review_owner: str | None = None  # who keeps the document current (every passage carries it)
    next_review: date
    body: str = Field(min_length=1)
