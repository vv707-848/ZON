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


_SCALE = {
    "الف": 1e3,
    "الاف": 1e3,
    "الفين": 1e3,
    "مليون": 1e6,
    "ملايين": 1e6,
    "مليونين": 1e6,
    "مليار": 1e9,
    "مليارات": 1e9,
}
_SCALED = re.compile(rf"(\d[\d,٬]*(?:\.\d+)?)\s*({'|'.join(_SCALE)})(?!{_LETTER})")


def scaled(text: str, values: Sequence[float]) -> bool:
    """A figure said in thousands, millions or billions is the data's value at that scale: «12 مليون» is 12,000,000,
    never a value of 12 (review of 21 Nov). NumberGuard::scaled, line for line."""
    for figure, word in _SCALED.findall(norm(text)):
        unit = _SCALE[word]
        allowed = {f for v in values if abs(v) >= unit for f in (_round(abs(v) / unit), _round(abs(v) / unit, 1))}
        if plain(figure) not in allowed:
            return False
    return True


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
    values = [n for row in rows for v in row.values() if (n := _numeric(v)) is not None]
    return scaled(text, values) and all(n in permitted for n in numbers(text))
