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


_TENS = {"عشرين": 20, "ثلاثين": 30, "اربعين": 40, "خمسين": 50, "ستين": 60, "سبعين": 70, "ثمانين": 80, "تسعين": 90}
_HUNDREDS = {
    "ميه": 100, "مئه": 100, "مائه": 100, "ميتين": 200, "مئتين": 200, "مائتين": 200, "ميتان": 200,
    **{f"{unit}{hundred}": n * 100 for unit, n in (("ثلاث", 3), ("ثلث", 3), ("اربع", 4), ("خمس", 5), ("ست", 6),
                                                      ("سبع", 7), ("ثمان", 8), ("تسع", 9))
       for hundred in ("ميه", "مئه", "مائه")},
}  # fmt: skip
_HUNDRED = {"ميه", "مئه", "مائه"}


def _small_number(word: str) -> float | None:
    for table in (_WORD_NUMBERS, _TENS, _HUNDREDS):
        if word in table:
            return float(table[word])
    return None


def _dual_noun(word: str) -> bool:
    """«حمامين»، «غرفتين»، «شارعين»: two of something (read on the advertiser's side only: it only allows)."""
    return len(word) >= 5 and word.endswith(("ين", "تين")) and _small_number(word) is None and word not in _DUAL


def _tens_after(small: set[float], values: set[float], joined: bool) -> bool:
    """«ست ميه وخمسين»: hundreds then tens or units joined by «و» are one number."""
    return joined and all(s % 100 == 0 and s >= 100 for s in small) and all(v < 100 for v in values)


def stated(text: str, *, figures: bool = True) -> set[float]:
    """Every value [text] states, read the way the market writes amounts: figures and words together
    («2 مليون و200 الف»، «39 الف و412»، «مليون ونص»، «ميتين الف»، «بمليون وست ميه وخمسين»), a bare figure after
    a million in thousands («مليون و800»), and Saudi «مليون 200» with no «و» (1,200,000).

    [figures] (the advertiser's side): each figure alone too, the «مليون 200» reading beside the two figures, and
    two for a dual noun («حمامين») — what his text may be cited as. Without it (a cleaned description's side), each
    amount once, as it reads in full: what it must have said."""
    tokens = _TOKEN.findall(norm(text))
    out: set[float] = set()
    big: set[float] = set()  # the amount read so far at its larger scales
    small: set[float] = set()  # ... and the part below the scale being read
    last_scale = 0.0
    joined = False  # a «و» since the last part

    def close() -> None:
        nonlocal big, small, last_scale
        if big or small:
            for b in big or {0.0}:
                for v in small or {0.0}:
                    # «مليون و800»: a bare part under a thousand after a million is in thousands
                    part = v * 1e3 if last_scale >= 1e6 and 0 < v < 1000 else v
                    out.add(b + part)
        big, small, last_scale = set(), set(), 0.0

    for tok in tokens:
        word = _bare_word(tok)
        if tok == "و":
            joined = True
            continue
        if tok.startswith("و") and len(tok) > 1 and not tok[0].isdigit():
            joined = True
        values: set[float] | None = None
        if tok[0].isdigit():
            values = _figures(tok)
        elif (number := _small_number(word)) is not None:
            values = {number}
        if values is not None:
            if figures:
                out |= values
            if word in _HUNDRED and small and not joined:  # «ست ميه»: six hundred
                small = {v * 100 for v in small if v < 10} or small
            elif small and (not joined or not big) and not _tens_after(small, values, joined):
                # two figures in a row, or «60 و20» with no scale: two amounts («وست ميه وخمسين» is one)
                close()
                small = values
            elif (big and not joined) and not figures:  # «2 مليون، 3 غرف»: not one amount on the cleaned side
                close()
                small = values
            elif big and not joined:  # «مليون 200»: read joined (the advertiser's side), and the parts stay
                out |= {b for b in big}
                small = {s + v for s in small for v in values} if small else values
            else:
                small = {s + v for s in small for v in values} if small else values
            joined = False
            continue
        scale = _SCALE_WORDS.get(word) or (_DUAL[word] / 2 if word in _DUAL else None)
        if scale is not None:
            multiplier = {2.0} if word in _DUAL else (small or {1.0})
            if figures and small:
                out |= small
            if last_scale and scale >= last_scale:  # «الف الف»: a new amount
                close()
            big = {b + m * scale for b in big or {0.0} for m in multiplier}
            if figures:  # «2 مليون» of «2 مليون و200 الف» may be cited alone
                out |= {m * scale for m in multiplier}
            small, last_scale, joined = set(), scale, False
            continue
        if word in _FRACTION and joined and last_scale:
            big = {b + _FRACTION[word] * last_scale for b in big}
            joined = False
            continue
        if figures and _dual_noun(word):
            out.add(2.0)
        close()
        joined = False
    close()
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


# ------------------------------------------------------------------ what each amount is said to be
# «مساحتها 150 م بسعر 880 الف» written «مساحتها 880,000 م بسعر 150 ريال»: both values are the advertiser's, each in
# the other's place. An amount is a price or an area by the unit after it or the word before it.

_AREA_UNITS = {"م", "م2", "م²", "متر", "مترا", "امتار", "مربع", "مترمربع"}
_PRICE_UNITS = {"ريال", "ريالا", "رس", "sar"}
_AREA_WORDS = {"مساحه", "مساحتها", "مساحته", "بمساحه", "المساحه", "مسطح", "مسطحات"}
_PRICE_WORDS = {"سعر", "بسعر", "السعر", "سعرها", "سعره", "المطلوب", "مطلوب", "السوم", "سوم", "ايجار", "الايجار",
                "بايجار", "بقيمه", "قيمه", "القيمه"}  # fmt: skip


def _amount_word(tok: str) -> bool:
    word = _bare_word(tok)
    return bool(tok[0].isdigit() or tok == "و" or word in _SCALE_WORDS or word in _DUAL or word in _FRACTION
                or word in _HUNDREDS)  # fmt: skip


def roles(text: str) -> list[tuple[float, str | None]]:
    """Each amount of [text] (its full value) with what it is said to be: "price", "area", or None."""
    tokens = _TOKEN.findall(norm(text).replace("²", "2"))
    found: list[tuple[float, str | None]] = []
    i = 0
    while i < len(tokens):
        if not (tokens[i][0].isdigit() or _bare_word(tokens[i]) in {**_DUAL, **_HUNDREDS, **_SCALE_WORDS}):
            i += 1
            continue
        j = i
        while j < len(tokens) and _amount_word(tokens[j]):
            j += 1
        while j > i + 1 and tokens[j - 1] == "و":
            j -= 1
        values = stated(" ".join(tokens[i:j]), figures=False)
        after = _bare_word(tokens[j]) if j < len(tokens) else ""
        before = {_bare_word(t) for t in tokens[max(0, i - 3) : i]} | set(tokens[max(0, i - 3) : i])
        role: str | None = None
        if after in _AREA_UNITS or after.rstrip("2") == "م":
            role = "area"
        elif after in _PRICE_UNITS:
            role = "price"
        elif before & _AREA_WORDS:
            role = "area"
        elif before & _PRICE_WORDS or any(_bare_word(t) in _SCALE_WORDS for t in tokens[i:j]):
            role = "price"
        if values:
            found.append((max(values), role))
        i = max(j, i + 1)
    return found


_FIELD_ROLE = {"price": "price", "area": "area", "area_built": "area", "area_land": "area"}


def swapped(desc: str, text: str, fields: dict[str, Any] | None = None) -> bool:
    """An amount of [desc] said to be one thing (a price) that the text or fields say only as the other (an area)."""
    said: dict[float, set[str | None]] = {}
    for value, role in roles(text):
        said.setdefault(value, set()).add(role)
    for name, value in (fields or {}).items():
        if name in _FIELD_ROLE and (number := _numeric(value)) is not None:
            said.setdefault(number, set()).add(_FIELD_ROLE[name])
    for value, role in roles(desc):
        known = next((r for v, r in said.items() if _close(value, {v})), None)
        if role is not None and known and None not in known and role not in known:
            return True
    return False
