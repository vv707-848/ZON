"""The conditions a wish states outright, and the offers that say the opposite (owner, 21 Nov 2026).

An offer that contradicts one is no candidate at all: it is taken out before the model reads the list, in the
fallback with no model, and in Laravel's own fallback (App\\Ai\\Retrieval\\Conditions, the same rules, kept equal by
docs/contracts/fixtures/conditions.json). Two offers that keep the wish are then two results.

Only what both texts say in so many words counts, and only these:

- the floor: «دور أرضي» — an offer on an upper floor says the opposite, a whole house does not;
- a whole house: «بيت»، «فيلا» — an apartment says the opposite;
- who may live there: «عزاب» or «عوائل» — an offer for the other only says the opposite («عوائل فقط» to singles);
- furniture: «مفروشة» or «بدون أثاث» — the offer that says the other;
- a lift: «مصعد» — an offer «بدون مصعد».

«أو» joins alternatives: the condition is kept by any of them («دور أرضي أو بيت»: a whole house of two floors keeps
it, an apartment on the second floor does not). An alternative these rules do not read («دور أرضي أو أول») leaves
the condition to the model. «أفضّل»، «يفضل»، «ياليت»… make what follows them a preference, not a condition: it orders,
it never takes out. An offer that says nothing on a condition does not contradict it.
"""

import re
from collections.abc import Iterable
from dataclasses import dataclass

from zon_agents.numbers import norm

# what a wish can ask for, and what an offer can say against it
GROUND, HOUSE, SINGLES, FAMILIES, FURNISHED, UNFURNISHED, LIFT = (
    "ground",
    "house",
    "singles",
    "families",
    "furnished",
    "unfurnished",
    "lift",
)

_W = r"(?<!\w)"  # a word's start (Arabic letters are \w)
_E = r"(?!\w)"
# a word's prefixes: و · ب · ل · ف · ال (and their joins)
_P = r"(?:و|ف)?(?:ب|ل)?(?:ال)?"

_ASKED: dict[str, re.Pattern[str]] = {
    GROUND: re.compile(rf"{_W}{_P}(?:ارضي|ارضيه){_E}"),
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
_OR = re.compile(rf"{_W}(?:او|ولا|أو){_E}")
_CLAUSE = re.compile(r"[،,.؛;!؟?\n]+|(?<!\w)(?:لكن|بس|مع)(?!\w)")

# what an offer says
_FLOOR = r"(?:دور|الدور|طابق|الطابق|بالدور|بالطابق|في الدور)"
_UPPER = re.compile(
    rf"{_FLOOR} ?(?:ال)?(?:اول|اولي|ثاني|ثالث|رابع|خامس|سادس|علوي|عالي|اخير|\d+){_E}|{_W}(?:علوي|روف|سطح){_E}"
)
_ON_GROUND = re.compile(rf"{_W}{_P}(?:ارضي|ارضيه){_E}")
_WHOLE = re.compile(rf"{_W}{_P}(?:فيلا|فيله|فله|بيت|دبلكس|دوبلكس|دورين|ثلاث ادوار|عماره){_E}")
_FLAT = re.compile(rf"{_W}{_P}(?:شقه|شقق|استديو|ستوديو|ملحق){_E}")
_FOR_FAMILIES = re.compile(rf"{_W}{_P}(?:عوايل|عوائل|عايلات|عايله|عايلي|عايليه|اسر){_E}")
_FOR_SINGLES = re.compile(rf"{_W}{_P}(?:عزاب|عزابي|اعزب|عزوبي){_E}")
_NOT_FURNISHED = re.compile(r"(?:غير|بدون|بلا|مو) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)")
_IS_FURNISHED = re.compile(rf"{_W}{_P}(?:مفروش|مفروشه|موثث|موثثه){_E}")
_NO_LIFT = re.compile(r"(?:بدون|بلا|لا يوجد|ما فيه|مافيه|ما فيها|مافيها|ولا يوجد) ?(?:مصعد|اصنصير)")

_HOUSE_TYPES = {"villa", "house", "building", "resthouse"}  # «floor» (a whole floor): its text says which
_FLAT_TYPES = {"apartment", "studio"}


@dataclass(frozen=True)
class Offer:
    """What an offer says, in so many words."""

    upper: bool
    ground: bool
    whole: bool
    flat: bool
    families_only: bool
    singles_only: bool
    furnished: bool
    unfurnished: bool
    no_lift: bool


def offer(text: str, kind: str | None = None) -> Offer:
    said = norm(text)
    families, singles = bool(_FOR_FAMILIES.search(said)), bool(_FOR_SINGLES.search(said))
    unfurnished = bool(_NOT_FURNISHED.search(said))
    whole = (kind in _HOUSE_TYPES) or bool(_WHOLE.search(said))
    return Offer(
        upper=bool(_UPPER.search(said)),
        ground=bool(_ON_GROUND.search(said)),
        whole=whole,
        flat=(kind in _FLAT_TYPES) or (bool(_FLAT.search(said)) and not whole),
        families_only=families and not singles,
        singles_only=singles and not families,
        furnished=bool(_IS_FURNISHED.search(_NOT_FURNISHED.sub(" ", said))),
        unfurnished=unfurnished,
        no_lift=bool(_NO_LIFT.search(said)),
    )


def _against(asked: str, o: Offer) -> bool:
    """The offer says the opposite of [asked]."""
    match asked:
        case "ground":
            return o.upper and not o.ground and not o.whole
        case "house":
            return o.flat and not o.whole
        case "singles":
            return o.families_only
        case "families":
            return o.singles_only
        case "furnished":
            return o.unfurnished
        case "unfurnished":
            return o.furnished and not o.unfurnished
        case "lift":
            return o.no_lift
    return False


def _terms(text: str) -> list[tuple[int, int, str]]:
    """Each condition named in [text]: its place and what it asks."""
    found: list[tuple[int, int, str]] = []
    covered: list[range] = []
    for name in (UNFURNISHED, GROUND, HOUSE, SINGLES, FAMILIES, FURNISHED, LIFT):
        for m in _ASKED[name].finditer(text):
            if any(m.start() in r for r in covered):
                continue  # «غير مفروشة» is unfurnished, not furnished
            if name == LIFT and _LIFT_WAIVED.search(text[max(0, m.start() - 12) : m.end()]):
                continue
            found.append((m.start(), m.end(), name))
            covered.append(range(m.start(), m.end()))
    return sorted(found)


def conditions(wish: str) -> list[frozenset[str]]:
    """The conditions [wish] states outright, each the alternatives any one of which keeps it."""
    out: list[frozenset[str]] = []
    for clause in _CLAUSE.split(norm(wish)):
        terms = _terms(clause)
        if not terms:
            continue
        prefer = _PREFER.search(clause)
        soft = {t for t in terms if prefer and prefer.start() < t[0]}
        # «أو» joins the condition right before it to the one right after: a group kept by any of them. A side that
        # names no condition here («دور أرضي أو أول») leaves the group to the model; an «أو» between other words
        # («شقة أو استديو … عزاب») leaves the conditions around it alone
        group = {t: n for n, t in enumerate(terms)}
        unknown: set[int] = set()
        for joint in _OR.finditer(clause):
            before = [t for t in terms if t[1] <= joint.start() and joint.start() - t[1] <= 3]
            after = [t for t in terms if t[0] >= joint.end() and t[0] - joint.end() <= 3]
            if before and after:
                old, new = group[after[0]], group[before[-1]]
                group = {t: new if g == old else g for t, g in group.items()}
            elif before or after:
                unknown.add(group[(before or after)[0]])
        for g in sorted(set(group.values()) - unknown):
            members = [t for t in terms if group[t] == g]
            if any(t in soft for t in members):
                continue  # preferred: it orders, it never takes out
            names = frozenset(name for _, _, name in members)
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
