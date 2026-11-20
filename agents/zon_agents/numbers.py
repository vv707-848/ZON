"""Laravel's NumberGuard (App\\SuperAgent\\NumberGuard), line for line: whether every number in a text is in the
data it was written from. The lab scores reports with it, and report_write keeps no recommendation it rejects."""

import re
from collections.abc import Sequence
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

_MARKS = re.compile(r"[ً-ْٰـ]")  # short vowels, shadda, sukun, dagger alef, tatweel


_LETTERS = str.maketrans(
    {"أ": "ا", "إ": "ا", "آ": "ا", "ٱ": "ا", "ى": "ي", "ة": "ه", "ؤ": "و", "ئ": "ي"}
    | {chr(0x0660 + d): str(d) for d in range(10)}  # Arabic-Indic digits
    | {chr(0x06F0 + d): str(d) for d in range(10)}  # Persian digits
)


_SPACE = re.compile(r"\s+")


def norm(text: str) -> str:
    return _SPACE.sub(" ", _MARKS.sub("", text).translate(_LETTERS).lower()).strip()


_NUMBER = re.compile(r"\d[\d,٬]*(?:\.\d+)?")


def plain(number: str) -> str:
    number = number.replace(",", "").replace("٬", "")
    return number.rstrip("0").rstrip(".") if "." in number else number


def _round(value: float, digits: int = 0) -> str:
    """PHP's round() (half away from zero) as PHP prints it — NumberGuard's own arithmetic."""
    rounded = Decimal(repr(value)).quantize(Decimal(1).scaleb(-digits), rounding=ROUND_HALF_UP)
    return plain(f"{rounded:f}")


def forms(value: float) -> set[str]:
    """How a value may appear in prose (NumberGuard::forms): as is, rounded, as a percentage, in thousands/millions."""
    found = {plain(repr(value)), _round(value), _round(value, 1), _round(value, 2)}
    if abs(value) <= 1:
        found |= {_round(value * 100), _round(value * 100, 1)}
    for unit in (1000, 1_000_000):
        if abs(value) >= unit:
            found |= {_round(value / unit), _round(value / unit, 1)}
    return found


def _numeric(value: Any) -> float | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int | float):
        return float(value)
    if isinstance(value, str):
        try:
            return float(value.replace(",", ""))
        except ValueError:
            return None
    return None


def numbers(text: str) -> list[str]:
    return [plain(n) for n in _NUMBER.findall(norm(text))]


_LETTER = r"[^\W\d_]"


_SHARE = re.compile(
    rf"(?<!{_LETTER})(?<!\d)(?:و?ب?ال)?(?:ضعف{_LETTER}{{0,2}}|اضعاف|تضاعف{_LETTER}{{0,2}}|مضاعف{_LETTER}{{0,2}}"
    rf"|(?:نصف|ربع|ثلث)(?:ي|ين)?)(?!{_LETTER})"
)


_UNIT = r"(?:الف|الاف|الفين|مليون|ملايين|مليونين|مليار|مليارات|%|٪|بالميه|بالمايه|في الميه|في المايه)"


_UNIT_AFTER_FIGURE = re.compile(rf"\d[\d,٬.]*\s*{_UNIT}")


_UNIT_ALONE = re.compile(rf"(?<!{_LETTER}){_UNIT}(?!{_LETTER})")


def number_guard(text: str, rows: Sequence[dict[str, Any]], params: dict[str, Any]) -> bool:
    """App\\SuperAgent\\NumberGuard::passes, line for line: every number, small ones too (no `_free`), and no
    quantity said without digits (a multiple, a share, an amount or a percentage in words)."""
    words = norm(text)
    if _SHARE.search(words) or _UNIT_ALONE.search(_UNIT_AFTER_FIGURE.sub(" ", words)):
        return False
    permitted: set[str] = set()
    for row in rows:
        for value in row.values():
            number = _numeric(value)
            if number is not None:
                permitted |= forms(abs(number))  # read from the text without its sign
            elif isinstance(value, str):  # a month or a date («2026-05»): its parts are data
                for part in re.findall(r"\d+", norm(value)):
                    permitted |= {part, part.lstrip("0") or "0"}
    for value in params.values():
        if _numeric(value) is not None:
            permitted.add(plain(str(value)))
    return all(n in permitted for n in numbers(text))
