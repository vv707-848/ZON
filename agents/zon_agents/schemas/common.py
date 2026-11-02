"""Shared pieces of the contract."""

from typing import Annotated

from pydantic import BaseModel, ConfigDict, Field


class Payload(BaseModel):
    """Base of everything Laravel sends. Unknown keys are ignored: Laravel builds payloads from whitelists
    (App\\Ai\\Masking, App\\Indexing\\IndexPayloads) and an additive change there must not break calls."""

    model_config = ConfigDict(extra="ignore")


Id = Annotated[int, Field(ge=1)]
Score = Annotated[float, Field(ge=0, le=1)]
PType = Annotated[str, Field(min_length=1, max_length=20)]
Place = Annotated[str, Field(min_length=1, max_length=80)]
