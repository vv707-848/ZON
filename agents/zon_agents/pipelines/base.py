"""What every pipeline shares: its context, its result, input validation and the text guards."""

import re
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from datetime import date
from typing import Any

from pydantic import BaseModel, ValidationError

from zon_agents.errors import InvalidPayload
from zon_agents.llm import LLM
from zon_agents.tools.retrieval import Retriever


@dataclass(frozen=True)
class Ctx:
    llm: LLM
    retrieval: Retriever
    today: Callable[[], date] = date.today


@dataclass
class Result:
    output: dict[str, Any]
    degraded: bool = False
    tokens_in: int = 0
    tokens_out: int = 0
    log: dict[str, Any] = field(default_factory=dict)


Pipeline = Callable[[Ctx, dict[str, Any]], Awaitable[Result]]


def parse[T: BaseModel](model: type[T], data: dict[str, Any]) -> T:
    try:
        return model.model_validate(data)
    except ValidationError as exc:
        raise InvalidPayload([".".join(str(part) for part in error["loc"]) for error in exc.errors()][:10]) from exc


# Contacts and links never leave the model's text (§10.2 step 4, §10.5 «المحظورات»).
_URL = re.compile(r"(https?://|www\.)\S+|\b[\w.-]+\.(com|net|org|sa|io|me)\b\S*", re.IGNORECASE)
_EMAIL = re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.]+\b")
# Spaces, dashes, dots and brackets between the digits: «055.123.4567», «(055) 123 4567», «+966 55 123 4567».
# The same rules as Laravel's App\Ai\Masking\AiMasking; \d also matches Arabic-Indic digits.
_SEP = r"[\s.\-()]{0,2}"
_PHONE = re.compile(
    rf"(?:\+|00)\s?\(?966\)?{_SEP}\d(?:{_SEP}\d){{7,8}}"
    rf"|(?<!\d)\(?966\)?{_SEP}5(?:{_SEP}\d){{8}}(?!\d)"
    rf"|(?<!\d)\(?0\d(?:{_SEP}\d){{7,8}}(?!\d)"
    rf"|(?<!\d)5\d(?:{_SEP}\d){{7}}(?!\d)"
    rf"|(?<!\d)(?:9200|800)(?:{_SEP}\d){{5,6}}(?!\d)"
    r"|\d{9,}"
)
_SPACES = re.compile(r"\s{2,}")


def scrub(text: str) -> str:
    """Removes links, e-mails and phone numbers; tidies the spaces left behind."""
    for pattern in (_URL, _EMAIL, _PHONE):
        text = pattern.sub("", text)
    return _SPACES.sub(" ", text).strip()
