"""The conditions a wish states outright, and the offers that say the opposite (owner, 21 Nov 2026).

An offer that contradicts one is no candidate at all: it is taken out before the model reads the list, in the
fallback with no model, and in Laravel's own fallback (App\\Ai\\Retrieval\\Conditions, the same rules, kept equal by
docs/contracts/fixtures/conditions.json). Two offers that keep the wish are then two results.

Only what both texts say in so many words counts, and only these:

- the floor: «دور أرضي»، «الدور الأول» — an offer on another floor says the opposite, a whole house does not;
- a whole house: «بيت»، «فيلا» — an apartment says the opposite;
- who may live there: «عزاب» or «عوائل» — an offer for the other only says the opposite («عوائل فقط» to singles),
  and so does one that turns them away («لا تقبل العزاب» to a single man, never to a family);
- furniture: «مفروشة» or «بدون أثاث» — the offer that says the other;
- a lift: «مصعد» — an offer «بدون مصعد».

«أو» joins alternatives: the condition is kept by any of them («دور أرضي أو بيت»: a whole house of two floors keeps
it, an apartment on the second floor does not; «أرضي أو أول»: either floor, never the third). An alternative these
rules do not read leaves the condition to the model. «أفضّل»، «يفضل»، «ياليت»… make what follows them a preference —
it orders, it never takes out — up to a word that makes the rest a must («أفضّل حي الشفا ولازم مصعد»: the lift is a
condition). A category the wish itself denies («مو سكن عزاب») is no condition. An offer that says nothing on a
condition does not contradict it.
"""

import re
from collections.abc import Iterable
from dataclasses import dataclass

from zon_agents.numbers import norm

# what a wish can ask for, and what an offer can say against it (a floor is «floor:<n>», 0 the ground floor)
HOUSE, SINGLES, FAMILIES, FURNISHED, UNFURNISHED, LIFT = (
    "house",
    "singles",
    "families",
    "furnished",
    "unfurnished",
    "lift",
)
FLOOR = "floor:"

_W = r"(?<!\w)"  # a word's start (Arabic letters are \w)
_E = r"(?!\w)"
# a word's prefixes: و · ب · ل · ف · ال (and their joins)
_P = r"(?:و|ف)?(?:ب|ل)?(?:ال)?"

_ORDINALS = {"ارضي": 0, "ارضيه": 0, "اول": 1, "اولي": 1, "ثاني": 2, "ثانيه": 2, "ثالث": 3, "ثالثه": 3, "رابع": 4,
             "خامس": 5, "سادس": 6}  # fmt: skip
_ORDINAL = r"(?:ال)?(?:ارضيه|ارضي|اولي|اول|ثانيه|ثاني|ثالثه|ثالث|رابع|خامس|سادس|\d{1,2})"
_FLOOR_WORD = r"(?:و|ف)?(?:ب|ل|في )?(?:ال)?(?:دور|طابق)"
# «الدور الأول» · «دور ثاني» · «الطابق 3» · «أرضي» alone
_A_FLOOR = re.compile(rf"{_W}{_FLOOR_WORD} ?(?P<n>{_ORDINAL}){_E}|{_W}{_P}(?P<g>ارضيه|ارضي){_E}")
# an ordinal alone: a floor only beside «أو» and a floor («أرضي أو أول»), never «أول ساكن»
_BARE_ORDINAL = re.compile(rf"{_W}(?:و)?(?P<n>{_ORDINAL}){_E}")

_ASKED: dict[str, re.Pattern[str]] = {
    HOUSE: re.compile(rf"{_W}{_P}(?:بيت|فيلا|فيله|فله|دبلكس|دوبلكس){_E}"),
    SINGLES: re.compile(rf"{_W}{_P}(?:عزاب|عزابي|اعزب|عزوبي|عزوبيه){_E}"),
    FAMILIES: re.compile(rf"{_W}{_P}(?:عايله|عايلتي|عايلات|عوايل|عوائل|اسره|اسرتي|عايلي|عايليه){_E}"),
    UNFURNISHED: re.compile(r"(?:غير|بدون|بلا|مو|مب|ما) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)"),
    FURNISHED: re.compile(rf"{_W}{_P}(?:مفروش|مفروشه|موثث|موثثه){_E}"),
    LIFT: re.compile(rf"{_W}{_P}(?:مصعد|اصنصير){_E}"),
}
# a lift the wish does not need («بدون مصعد عادي»، «ما يحتاج مصعد»): no condition
_LIFT_WAIVED = re.compile(r"(?:بدون|بلا|ما يحتاج|مو لازم|ما يهم|مايهم) ?(?:مصعد|اصنصير)")
_PREFER = re.compile(
    rf"{_W}(?:و|ف)?(?:افضل|يفضل|تفضل|نفضل|افضله|يفضله|حبذا|ياليت|يا ليت|يستحسن|ويستحسن"
    rf"|لو امكن|ان امكن|اذا امكن|احسن|الافضل){_E}"
)
# what ends a preference: the rest is a must («أفضّل حي الشفا ولازم مصعد»)
_MUST = re.compile(rf"{_W}(?:و|ف)?(?:لازم|ضروري|ضروريه|شرط|بشرط|لابد|لا بد|مطلوب|اساسي|اكيد){_E}")
# a category turned away or denied: one of these within the two words before it («لا تقبل العزاب»، «مو سكن عزاب»)
_DENY = {"لا", "ما", "مو", "مب", "غير", "ممنوع", "يمنع", "بدون", "بلا", "ليس", "ليست", "مانقبل", "مايقبل", "ماتقبل",
         "مانأجر", "ماناجر"}  # fmt: skip
_OR = re.compile(rf"{_W}(?:او|ولا|أو){_E}")
_CLAUSE = re.compile(r"[،,.؛;!؟?\n]+|(?<!\w)(?:لكن|بس|مع)(?!\w)")

# what an offer says
_UPPER = re.compile(rf"{_W}(?:علوي|روف|سطح){_E}")
_WHOLE = re.compile(rf"{_W}{_P}(?:فيلا|فيله|فله|بيت|دبلكس|دوبلكس|دورين|ثلاث ادوار|عماره){_E}")
_FLAT = re.compile(rf"{_W}{_P}(?:شقه|شقق|استديو|ستوديو|ملحق){_E}")
_FOR_FAMILIES = re.compile(rf"{_W}{_P}(?:عوايل|عوائل|عايلات|عايله|عايلي|عايليه|اسر){_E}")
_FOR_SINGLES = re.compile(rf"{_W}{_P}(?:عزاب|عزابي|اعزب|عزوبي){_E}")
_NOT_FURNISHED = re.compile(r"(?:غير|بدون|بلا|مو) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)")
_IS_FURNISHED = re.compile(rf"{_W}{_P}(?:مفروش|مفروشه|موثث|موثثه){_E}")
_NO_LIFT = re.compile(r"(?:بدون|بلا|لا يوجد|ما فيه|مافيه|ما فيها|مافيها|ولا يوجد) ?(?:مصعد|اصنصير)")


def _floor(word: str) -> int:
    word = word.removeprefix("ال")
    return int(word) if word.isdigit() else _ORDINALS[word]


def _denied(text: str, start: int) -> bool:
    """One of _DENY within the two words before [start]."""
    return any(w in _DENY for w in text[:start].split()[-2:])


def _said(pattern: re.Pattern[str], text: str) -> tuple[bool, bool]:
    """Whether [text] names the category of [pattern] as it is, and whether it turns it away."""
    found = [_denied(text, m.start()) for m in pattern.finditer(text)]
    return (False in found), (True in found)


_HOUSE_TYPES = {"villa", "house", "building", "resthouse"}  # «floor» (a whole floor): its text says which
_FLAT_TYPES = {"apartment", "studio"}


@dataclass(frozen=True)
class Offer:
    """What an offer says, in so many words."""

    floors: frozenset[int]  # the floors it says it is on
    upper: bool  # «علوي»، «روف»: above the ground floor, its number unsaid
    whole: bool
    flat: bool
    families_only: bool
    singles_only: bool
    no_singles: bool  # «لا تقبل العزاب»
    no_families: bool
    furnished: bool
    unfurnished: bool
    no_lift: bool


def offer(text: str, kind: str | None = None) -> Offer:
    said = norm(text)
    families, no_families = _said(_FOR_FAMILIES, said)
    singles, no_singles = _said(_FOR_SINGLES, said)
    whole = (kind in _HOUSE_TYPES) or bool(_WHOLE.search(said))
    floors = frozenset(_floor(m["n"] or m["g"]) for m in _A_FLOOR.finditer(said))
    return Offer(
        floors=floors,
        upper=bool(_UPPER.search(said)),
        whole=whole,
        flat=(kind in _FLAT_TYPES) or (bool(_FLAT.search(said)) and not whole),
        families_only=(families or no_singles) and not singles,
        singles_only=(singles or no_families) and not families,
        no_singles=no_singles,
        no_families=no_families,
        furnished=bool(_IS_FURNISHED.search(_NOT_FURNISHED.sub(" ", said))),
        unfurnished=bool(_NOT_FURNISHED.search(said)),
        no_lift=bool(_NO_LIFT.search(said)),
    )


def _against(asked: str, o: Offer) -> bool:
    """The offer says the opposite of [asked]."""
    if asked.startswith(FLOOR):  # on another floor it states; a whole house has them all; «علوي» is not the ground
        n = int(asked.removeprefix(FLOOR))
        if o.whole:
            return False
        if o.floors:
            return n not in o.floors
        return o.upper and n == 0
    match asked:
        case "house":
            return o.flat and not o.whole
        case "singles":
            return o.families_only or o.no_singles
        case "families":
            return o.singles_only or o.no_families
        case "furnished":
            return o.unfurnished
        case "unfurnished":
            return o.furnished and not o.unfurnished
        case "lift":
            return o.no_lift
    return False


def _terms(text: str) -> list[tuple[int, int, str]]:
    """Each condition named in [text]: its place and what it asks. A bare ordinal is «floor?:<n>» until an «أو»
    beside a floor makes it one (conditions)."""
    found: list[tuple[int, int, str]] = []
    covered: list[range] = []

    def add(start: int, end: int, name: str) -> None:
        if not any(start in r or r.start in range(start, end) for r in covered):
            found.append((start, end, name))
            covered.append(range(start, end))

    for m in _A_FLOOR.finditer(text):
        add(m.start(), m.end(), f"{FLOOR}{_floor(m['n'] or m['g'])}")
    for m in _BARE_ORDINAL.finditer(text):
        add(m.start(), m.end(), f"floor?:{_floor(m['n'])}")
    for name in (UNFURNISHED, HOUSE, SINGLES, FAMILIES, FURNISHED, LIFT):
        for m in _ASKED[name].finditer(text):
            if name == LIFT and _LIFT_WAIVED.search(text[max(0, m.start() - 12) : m.end()]):
                continue
            if name in (SINGLES, FAMILIES) and _denied(text, m.start()):
                continue  # «مو سكن عزاب»: the wish denies it, no condition
            add(m.start(), m.end(), name)  # «غير مفروشة» is unfurnished, not furnished: the first match keeps it
    return sorted(found)


def conditions(wish: str) -> list[frozenset[str]]:
    """The conditions [wish] states outright, each the alternatives any one of which keeps it."""
    out: list[frozenset[str]] = []
    for clause in _CLAUSE.split(norm(wish)):
        terms = _terms(clause)
        if not any(not t[2].startswith("floor?") for t in terms):
            continue
        # a preference covers what follows it up to a must («أفضّل حي الشفا ولازم مصعد»: the lift is a condition)
        soft: set[tuple[int, int, str]] = set()
        for prefer in _PREFER.finditer(clause):
            must = _MUST.search(clause, prefer.end())
            soft |= {t for t in terms if prefer.start() < t[0] and (must is None or t[0] < must.start())}
        # «أو» joins the condition right before it to the one right after: a group kept by any of them. A side that
        # names no condition here leaves the group to the model; an «أو» between other words («شقة أو استديو …
        # عزاب») leaves the conditions around it alone. An ordinal beside «أو» and a floor is a floor
        group = {t: n for n, t in enumerate(terms)}
        unknown: set[int] = set()
        floors_ok: set[tuple[int, int, str]] = set()
        for joint in _OR.finditer(clause):
            before = [t for t in terms if t[1] <= joint.start() and joint.start() - t[1] <= 3]
            after = [t for t in terms if t[0] >= joint.end() and t[0] - joint.end() <= 3]
            if before and after:
                left, right = before[-1], after[0]
                if left[2].startswith("floor") and right[2].startswith("floor"):
                    floors_ok |= {left, right}
                elif left[2].startswith("floor?") or right[2].startswith("floor?"):
                    unknown.add(group[left if not left[2].startswith("floor?") else right])
                    continue
                old, new = group[right], group[left]
                group = {t: new if g == old else g for t, g in group.items()}
            elif before or after:
                side = (before or after)[0]
                if not side[2].startswith("floor?"):
                    unknown.add(group[side])
        for g in sorted(set(group.values()) - unknown):
            members = [t for t in terms if group[t] == g]
            members = [t for t in members if not t[2].startswith("floor?") or t in floors_ok]
            if not members or any(t in soft for t in members):
                continue  # an ordinal alone («أول ساكن»), or preferred: it orders, it never takes out
            names = frozenset(name.replace("floor?:", FLOOR) for _, _, name in members)
            if names == {HOUSE}:
                continue  # «بيت» alone is the type the form asks (and «البيت» a home): an alternative only, after «أو»
            out.append(names)
    return out


def contradicts(wish: str, offer_text: str, offer_kind: str | None = None) -> bool:
    """[offer_text] says the opposite of a condition [wish] states outright: every alternative of one of them."""
    said = offer(offer_text, offer_kind)
    return any(all(_against(asked, said) for asked in alternatives) for alternatives in conditions(wish))


def kept(wish: str, offers: Iterable[tuple[str, str | None]]) -> list[bool]:
    """For each (text, type) of [offers]: whether it keeps [wish]'s conditions."""
    return [not contradicts(wish, text, kind) for text, kind in offers]
