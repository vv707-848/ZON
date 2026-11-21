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


# ------------------------------------------------------------------ amounts an advertiser states
# The market writes one amount in digits and words together («2 مليون و200 الف»، «39 الف و412»، «مليون ونص»،
# «175 الف»، «10.550 متر»). [stated] reads every value such a text can mean, so a cleaned description may say it
# in another form (2.2 مليون · 2,200,000) — and a value it cannot mean (2200200, A40 21 Nov) is caught.

_WORD_NUMBERS = {
    "واحد": 1, "وحده": 1, "اثنين": 2, "اثنان": 2, "ثنتين": 2, "ثلاث": 3, "ثلاثه": 3, "اربع": 4, "اربعه": 4,
    "خمس": 5, "خمسه": 5, "ست": 6, "سته": 6, "سبع": 7, "سبعه": 7, "ثمان": 8, "ثماني": 8, "ثمانيه": 8,
    "تسع": 9, "تسعه": 9, "عشر": 10, "عشره": 10,
}  # fmt: skip
_SCALE_WORDS = {"الف": 1e3, "الاف": 1e3, "مليون": 1e6, "ملايين": 1e6, "مليار": 1e9, "مليارات": 1e9}
_DUAL = {"الفين": 2e3, "مليونين": 2e6, "مليارين": 2e9}
_FRACTION = {"نص": 0.5, "نصف": 0.5, "ربع": 0.25}
_TOKEN = re.compile(r"\d[\d,٬.]*\d|\d|[^\W\d_]+")


def _figures(token: str) -> set[float]:
    """What one written figure means: «10.550» and «1,650,000» in thousands, «11,5» and «2.5» with a decimal mark."""
    out: set[float] = set()
    for sep in (",", "٬", "."):
        if re.fullmatch(rf"\d{{1,3}}(?:{re.escape(sep)}\d{{3}})+", token):
            out.add(float(token.replace(sep, "")))
    if not out and re.fullmatch(r"\d+[.,]\d+", token):  # «11,5»; «10.550» is thousands
        out.add(float(token.replace(",", ".")))
    if re.fullmatch(r"\d+", token):
        out.add(float(token))
    if not out:  # «82.074.89»: each run of digits, and the whole without its marks
        out |= {float(d) for d in re.findall(r"\d+", token)} | {float(re.sub(r"\D", "", token))}
    return out


def _bare_word(word: str) -> str:
    return re.sub(r"^(?:و|ب|وب)(?=\w{2})", "", word)


def stated(text: str, *, figures: bool = True) -> set[float]:
    """Every value [text] states: each figure as written, at the scale a word after it gives («175 الف»), a dual or a
    scale word alone («مليونين»، «مليون»), a fraction of the scale before it («مليون ونص»), small numbers in words
    («خمس غرف»), and the sum of a larger part and a smaller one joined by «و» («2 مليون و200 الف»، «مليون و800»)."""
    tokens = _TOKEN.findall(norm(text))
    out: set[float] = set()
    parts: list[tuple[float, float]] = []  # (value, its scale) of the amount being read, largest first
    i, join = 0, False
    while i < len(tokens):
        tok, word = tokens[i], _bare_word(tokens[i])
        if tok == "و":  # «و 200»: the next figure joins the amount
            join = True
            i += 1
            continue
        joined = join or (tok.startswith("و") and len(tok) > 1 and not tok[0].isdigit())
        join = False
        values: set[float] = set()
        scale = 1.0
        if tok[0].isdigit():
            values = _figures(tok)
            nxt = _bare_word(tokens[i + 1]) if i + 1 < len(tokens) else ""
            if nxt in _SCALE_WORDS:
                scale = _SCALE_WORDS[nxt]
                if figures:  # the text's «175» of «175 الف» may be cited alone; a description's is 175,000
                    out |= values
                values = {v * scale for v in values}
                i += 1
        elif word in _DUAL:
            values, scale = {_DUAL[word]}, _DUAL[word] / 2
        elif word in _SCALE_WORDS:
            values, scale = {_SCALE_WORDS[word]}, _SCALE_WORDS[word]
        elif word in _FRACTION and joined and parts:
            values, scale = {_FRACTION[word] * parts[-1][1]}, 0.0
        elif word in _WORD_NUMBERS:
            values = {float(_WORD_NUMBERS[word])}
        else:
            parts = []
            i += 1
            continue
        out |= values
        if joined and parts:
            big, big_scale = parts[-1]
            for v in values:
                small = v
                # «مليون و800»: a bare smaller figure after a million is in thousands
                if scale == 1.0 and big_scale >= 1e6 and v < 1000:
                    small = v * 1e3
                if small < big_scale or scale < big_scale:
                    out.add(big + small)
            parts = [(big + min(values), big_scale)]
        else:
            parts = [(min(values), scale if scale > 1 else 1.0)]
        i += 1
    return out


def _close(value: float, allowed: set[float]) -> bool:
    return any(abs(value - a) <= max(0.5, abs(a) * 1e-9) or round(a) == value for a in allowed)


def grounded_amounts(desc: str, text: str, fields: dict[str, Any] | None = None) -> bool:
    """Every value the cleaned description states is one the advertiser's text or fields state (`clean_listing`
    keeps no wording that changes an amount: Laravel then publishes his text as he wrote it)."""
    allowed = stated(text)
    for value in (fields or {}).values():
        if (number := _numeric(value)) is not None:
            allowed |= {number, abs(number)}
    return all(_close(v, allowed) for v in stated(desc, figures=False))
