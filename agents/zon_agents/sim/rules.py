"""The rule model: what the documented rules alone answer, for every agent — deterministic, and not a model.

- **Ranking** follows the grading rule of docs/model-lab.md («من أين الأسئلة»): an offer over the budget by more
  than 10%, or with fewer rooms than asked, breaks a condition; the rest are ordered by the share of the wishes
  they meet (all · half or more · fewer), one step down when over the budget. A wish is one of the things people
  ask for by name (`CONCEPTS`), read in the free text and looked for in the offer's fields and description — said
  in its usual words only: a rule knows no paraphrase, which is what a model is for.
- **Text** is extractive: a cleaned description is the advertiser's own words without contacts, emoji and
  exaggerations; a summary is the notes' own first sentences; the chat quotes the closest passage. Nothing is
  written that was not given, so no number can be invented.

It never reads a case's expected answer. Its order does not depend on the order the candidates came in.
"""

import re
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass
from typing import Any

from zon_agents.lab.scoring import norm
from zon_agents.pipelines.base import scrub
from zon_agents.pipelines.ranking import OVER_BUDGET

# ── what people ask for by name ─────────────────────────────────────────────────────────────────


@dataclass(frozen=True)
class Concept:
    key: str
    label: str  # how a feature is written
    said: re.Pattern[str]  # on normalised text (lab.scoring.norm)
    unsaid: re.Pattern[str] | None = None  # «بدون مصعد»: named, and not there


def _concept(key: str, label: str, said: Sequence[str], unsaid: Sequence[str] = ()) -> Concept:
    def pattern(words: Sequence[str]) -> re.Pattern[str]:
        return re.compile("|".join(norm(w) for w in words))

    return Concept(key, label, pattern(said), pattern(unsaid) if unsaid else None)


_READY = r"(?:راكب|مركب|جاهز|مجهز)"
CONCEPTS: tuple[Concept, ...] = (
    _concept("private_entrance", "مدخل خاص", [r"مدخل(?:ها)? (?:خاص|مستقل)"]),
    _concept("two_entrances", "مدخلان", ["مدخلين", "مدخلان", r"مدخل (?:لل)?رجال"]),
    _concept("kitchen", "مطبخ راكب", [rf"مطبخ (?:\w+ ){{0,2}}{_READY}"]),
    _concept("schools", "قريب من المدارس", ["مدارس", "مدرسة"]),
    _concept("mosque", "قريب من المسجد", ["مسجد", "جامع"]),
    _concept("ground", "دور أرضي", ["ارضي"]),
    _concept("elevator", "مصعد", ["مصعد", "اصنصير"], [r"(?:بدون|بلا|ما فيها|لا يوجد) (?:مصعد|اصنصير)"]),
    _concept("parking", "موقف خاص", ["موقف", r"مظلة سيار", r"مكان للسيار", "كراج"]),
    _concept("pool", "مسبح", ["مسبح"], [r"(?:بدون|بلا|ما فيها) مسبح"]),
    _concept("annex", "ملحق", ["ملحق"]),
    _concept("driver", "غرفة سائق", ["سائق", "سواق"]),
    _concept("yard", "حوش", ["حوش", "فناء"]),
    _concept("north", "واجهة شمالية", ["شمالي", r"واجهة شمال", r"الواجهة شمال"]),
    _concept(
        "furnished",
        "مفروشة",
        ["مفروش", "مؤثث", r"(?:مع|ب)\s?(?:ال)?اثاث"],
        [r"غير (?:مفروش|مؤثث)", r"(?:بدون|بلا) اثاث"],
    ),
    _concept("deed", "صك", ["صك"]),
    _concept("bank", "يقبل البنك", ["بنك", "تمويل"]),
    _concept("new", "جديد", ["جديد", r"اول ساكن", r"لم تسكن"]),
    _concept("garden", "حديقة", ["حديقة"]),
    _concept("maid", "غرفة خادمة", ["خادمة", "شغالة"]),
    _concept("basement", "قبو", ["قبو"]),
)
# A wish the fields answer before the description does.
_FIELDS: dict[str, Callable[[dict[str, Any]], bool | None]] = {
    "deed": lambda c: c.get("deed"),
    "bank": lambda c: c.get("bank"),
    "new": lambda c: None if c.get("age") is None else c["age"] <= 1,
}
SCORES = {3: 0.95, 2: 0.7, 1: 0.45, 0: 0.05}


def concepts(text: str) -> set[str]:
    flat = norm(text)
    return {c.key for c in CONCEPTS if c.said.search(c.unsaid.sub(" ", flat) if c.unsaid else flat)}


def _has(candidate: dict[str, Any]) -> set[str]:
    found = concepts(str(candidate.get("desc", "")))
    for key, read in _FIELDS.items():
        field = read(candidate)
        if field is True:
            found.add(key)
        elif field is False:
            found.discard(key)
    return found


def _by_share(share: float, over: bool) -> int:
    grade = 3 if share == 1 else 2 if share >= 0.5 else 1
    return max(1, grade - 1) if over else grade


def _share(wishes: set[str], has: set[str]) -> float:
    return len(wishes & has) / len(wishes) if wishes else 1.0


def _ranked(graded: Iterable[tuple[int, float, int]], limit: int, *, drop_zero: bool = False) -> list[dict[str, Any]]:
    """(grade, distance, id) → the best first, the closest to the budget among equals, then the lower id."""
    ordered = sorted(graded, key=lambda g: (-g[0], g[1], g[2]))
    return [{"id": i, "score": SCORES[grade]} for grade, _, i in ordered if not (drop_zero and grade == 0)][:limit]


# ── ranking ─────────────────────────────────────────────────────────────────────────────────────


def _listing(need: dict[str, Any], wishes: set[str], candidate: dict[str, Any]) -> tuple[int, float, int]:
    top, price = need.get("budget_max"), candidate.get("price")
    distance = abs(price - top) / top if top and price is not None else 0.0
    rooms = need.get("bedrooms")
    if (top and price is not None and price > top * OVER_BUDGET) or (
        rooms and (candidate.get("bedrooms") or 0) < rooms
    ):
        return 0, distance, candidate["id"]
    over = bool(top and price is not None and price > top)
    return _by_share(_share(wishes, _has(candidate)), over), distance, candidate["id"]


def _listings(need: dict[str, Any], candidates: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    wishes = concepts(str(need.get("free_text", "")))
    return _ranked((_listing(need, wishes, c) for c in candidates), limit)


def _request(offer: dict[str, Any], features: set[str], request: dict[str, Any]) -> tuple[int, float, int]:
    """A request an offer could answer: the offer is the need (its price is the budget's top)."""
    price, top = offer.get("budget_max") or offer.get("budget_min"), request.get("budget_max")
    distance = abs(price - top) / top if top and price else 0.0
    rooms = request.get("rooms_min")
    known = offer.get("bedrooms")  # an offer whose rooms were not given is not short of any
    if (top and price and price > top * OVER_BUDGET) or (rooms and known is not None and known < rooms):
        return 0, distance, request["id"]
    share = _share(concepts(str(request.get("desc", ""))), features)
    return _by_share(share, bool(top and price and price > top)), distance, request["id"]


def _requests(need: dict[str, Any], candidates: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    features = concepts(str(need.get("free_text", "")))
    return _ranked((_request(need, features, c) for c in candidates), limit)


def _searcher(searcher: dict[str, Any], units: list[dict[str, Any]]) -> tuple[int, int | None]:
    """The grade of a searcher and the unit model nearest to him: within 5% of his budget with enough rooms, then
    by the wishes it meets; within 15% only is a weak fit of no model; farther is none."""
    top, rooms = searcher.get("budget_max") or searcher.get("budget_min"), searcher.get("bedrooms")

    def within(unit: dict[str, Any], factor: float) -> bool:
        priced = top is None or unit.get("price") is None or unit["price"] <= top * factor
        return priced and (rooms is None or (unit.get("bedrooms") or 0) >= rooms)

    fits = [u for u in units if within(u, 1.05)]
    if not fits:
        return (1 if any(within(u, 1.15) for u in units) else 0), None
    wishes = concepts(str(searcher.get("notes", "")))

    def fit(unit: dict[str, Any]) -> tuple[float, int, int]:
        gap = abs((unit.get("bedrooms") or 0) - rooms) if rooms is not None else 0
        return _share(wishes, concepts(str(unit.get("desc", "")))), -gap, -unit["id"]

    best = max(fits, key=fit)
    return _by_share(fit(best)[0], over=False), best["id"]


def _searchers(payload: dict[str, Any]) -> dict[str, Any]:
    fitted = {s["id"]: _searcher(s, payload["units"]) for s in payload["searchers"]}
    ranked = _ranked(
        ((grade, 0.0, i) for i, (grade, _) in fitted.items()), int(payload.get("limit", 10)), drop_zero=True
    )
    return {"results": [{**r, "unit_id": fitted[r["id"]][1]} for r in ranked]}


# ── text ────────────────────────────────────────────────────────────────────────────────────────

_EMOJI = re.compile("[\U0001f000-\U0001faff☀-➿️‍]")
_HYPE = re.compile(
    r"فرص[ةه] لا تعو[ضظ]|فرص[ةه] ذهبي[ةه]|[أا]فضل سعر|[أا]رخص سعر|سعر مغري|لا يفوتك|لا تفوتك|لقط[ةه]|عرض لا يتكرر"
    r"|للجادين فقط|سارع بالاتصال|استثمار مضمون"
)
_HYPED = re.compile(rf"(?:{_HYPE.pattern})[\s!.،]*")
_TIDY = re.compile(r"\s*([،,.!؟])(?:\s*[،,.!؟])+|\s{2,}")
_SENTENCE = re.compile(r"(?<=[.!؟\n])\s+")


def clean(text: str) -> str:
    """The advertiser's own words without contacts, emoji and exaggerations; no word and no number is added."""
    cleaned = _HYPED.sub(" ", _EMOJI.sub(" ", scrub(text)))  # with the marks that followed the exaggeration
    cleaned = _TIDY.sub(lambda m: f"{m.group(1)} " if m.group(1) else " ", cleaned).strip(" ،,.")
    return cleaned or text.strip()


def _features(text: str, fields: dict[str, Any]) -> list[str]:
    found = concepts(text) | {key for key in ("deed", "bank") if fields.get(key) is True}
    return [c.label for c in CONCEPTS if c.key in found][:12]


def _sentences(text: str) -> list[str]:
    return [s.strip() for s in _SENTENCE.split(text.strip()) if s.strip()]


_SIGNALS: tuple[tuple[str, re.Pattern[str]], ...] = tuple(
    (label, re.compile("|".join(norm(w) for w in words)))
    for label, words in (
        ("كاش", ["كاش", "نقد"]),
        ("تمويل بنكي", ["تمويل", "بنك"]),
        ("مستعجل", ["مستعجل", "عاجل", "باسرع"]),
        ("حساس للسعر", ["غالي", r"السعر مرتفع", "تخفيض", "يفاصل", r"اعلى من ميزانيت"]),
        ("يفضل الشمال", ["شمال"]),
        ("يريد مساحة أكبر", [r"المساحة صغيرة", r"مساحة اكبر"]),
    )
)
_NEXT = {"مهتم": "اعرض عليه ما يطابق ما طلبه", "رفض": "اسأله عن سبب الرفض", "تم العرض": "اطلب رأيه في ما عُرض عليه"}


def _summary(payload: dict[str, Any]) -> dict[str, Any]:
    notes: list[dict[str, Any]] = payload["notes"]
    first, last = _sentences(str(notes[0]["text"]))[:1], _sentences(str(notes[-1]["text"]))[:1]
    everything = norm(" ".join(str(n["text"]) for n in notes))
    return {
        "summary": " ".join(dict.fromkeys(first + last))[:600] or str(notes[-1]["text"])[:600],
        "signals": [label for label, said in _SIGNALS if said.search(everything)][:6],
        "recommended_action": _NEXT.get(str(notes[-1].get("category", "")), "متابعة"),
        "confidence": "high" if len(notes) >= 4 else "medium" if len(notes) >= 2 else "low",
    }


_STOP_WORDS = "في من على عن الى هل ما وش كيف متى كم هو هي او ان اذا مع لا نعم هذا هذه التي الذي"
_STOP = frozenset(norm(w) for w in _STOP_WORDS.split())
_GUIDE = {
    "semantic_search": {
        "ar": "ابدأ بالمدينة ثم الحي ثم نوع العقار، ثم اكتب ما تريده بكلماتك في «البحث الدلالي».",
        "en": "Start with the city, then the neighbourhood and the property type, then describe what you want.",
        "ur": "پہلے شہر، پھر محلہ اور جائیداد کی قسم چنیں، پھر اپنی ضرورت لکھیں۔",
    },
    "search_request": {
        "ar": "ارفع طلب بحث من «إضافة»: الطلب العادي مجاني، والعاجل برسوم أو من رصيد الباقة.",
        "en": "Post a search request from Add: a regular one is free, an urgent one is paid or from your plan.",
        "ur": "«اضافہ» سے تلاش کی درخواست دیں: عام درخواست مفت ہے، فوری درخواست کی فیس ہے۔",
    },
    "matching": {
        "ar": "مساعد المطابقة للباحث والمطوّر: أكمل رغبتك بالمدينة والحي والنوع والميزانية والوصف.",
        "en": "The matching assistant is for seekers and developers: complete your wish (city, type, budget).",
        "ur": "میچنگ اسسٹنٹ تلاش کرنے والوں اور ڈویلپرز کے لیے ہے: اپنی خواہش مکمل کریں۔",
    },
}
_UNCOVERED = {
    "ar": "لا تغطي مكتبة زون هذا السؤال.",
    "en": "Zon's library does not cover this question.",
    "ur": "زون کی لائبریری اس سوال کا احاطہ نہیں کرتی۔",
}
_UNCOVERED["xx"] = _UNCOVERED["en"]


def _tokens(text: str) -> set[str]:
    """Content words, without the article and the one-letter prefixes Arabic joins to a word."""
    words = (re.sub(r"^(?:وال|بال|لل|ال|و|ب|ل)(?=\w{3})", "", w) for w in re.findall(r"\w+", norm(text)))
    return {w for w in words if len(w) > 2 and w not in _STOP}


def _number(value: float) -> str:
    return str(int(value)) if value == int(value) else str(value)


def _chat(payload: dict[str, Any]) -> dict[str, Any]:
    lang, intent = str(payload.get("lang", "ar")), str(payload["intent"])
    if intent == "auto":  # no rule reads its language: the library if its words meet a passage, else outside it
        known = _chat({**payload, "intent": "knowledge"})
        if known["covered"]:
            return {**known, "intent": "knowledge"}
        return {"answer": _UNCOVERED[lang], "covered": False, "citations": [], "intent": "out_of_scope"}
    if intent == "packages":
        priced = [p for p in payload.get("packages", []) if p.get("price") is not None]
        if not priced:
            return {"answer": _UNCOVERED[lang], "covered": False, "citations": []}
        listed = "، ".join(f"{p['name']} بـ{_number(float(p['price']))} ريال" for p in priced)
        lead = {"ar": "باقات زون الحالية: ", "en": "Zon's current plans: ", "ur": "زون کے موجودہ پیکجز: "}[lang]
        return {"answer": f"{lead}{listed}.", "covered": True, "citations": []}
    if intent != "knowledge":
        return {"answer": _GUIDE[intent][lang], "covered": True, "citations": []}
    asked = _tokens(str(payload["message"]))
    passages: list[dict[str, Any]] = payload.get("passages", [])
    overlap = [(len(asked & _tokens(f"{p['title']} {p['text']}")), -int(p["n"])) for p in passages]
    if not overlap or max(overlap)[0] == 0:
        return {"answer": _UNCOVERED[lang], "covered": False, "citations": []}
    best = passages[overlap.index(max(overlap))]
    quoted = _sentences(str(best["text"]))
    answer = {
        "ar": " ".join(quoted[:2]),
        "en": f"Zon's library answers this under «{best['title']}»; the original Arabic text is quoted below.",
        "ur": f"زون کی لائبریری میں اس کا جواب «{best['title']}» کے تحت ہے؛ اصل عربی متن نیچے ہے۔",
    }["en" if lang == "xx" else lang]
    return {
        "answer": answer[:1500],
        "covered": True,
        "citations": [{"passage": int(best["n"]), "quote_ar": quoted[0][:400]}],
    }


# ── the SuperAgent ──────────────────────────────────────────────────────────────────────────────

_DAYS = re.compile(norm(r"(?:اخر|خلال) (\d+) (?:يوم|ايام)"))
_SPANS = {norm("اسبوع"): 7, norm("شهر"): 30, norm("سنة"): 365}


def _closest_report(question: str, catalog: list[dict[str, Any]], skip: Sequence[str] = ()) -> dict[str, Any] | None:
    asked = _tokens(question)
    scored = [
        (len(asked & _tokens(f"{entry['description']} {entry['report'].replace('_', ' ')}")), -n, entry)
        for n, entry in enumerate(catalog)
        if entry["report"] not in skip
    ]
    best = max(scored, key=lambda s: s[:2], default=None)
    return best[2] if best and best[0] > 0 else None


def _params(question: str, declared: dict[str, Any]) -> dict[str, Any]:
    flat = norm(question)
    params: dict[str, Any] = {}
    if "days" in declared:
        counted = _DAYS.search(flat)
        days = int(counted.group(1)) if counted else next((n for word, n in _SPANS.items() if word in flat), None)
        if days is not None:
            params["days"] = days
    cities = declared.get("city", {}).get("enum") or []
    city = next((c for c in cities if isinstance(c, str) and norm(c) in flat), None)
    if city is not None:
        params["city"] = city
    return params


def _select(payload: dict[str, Any]) -> dict[str, Any]:
    entry = _closest_report(str(payload["question"]), payload["catalog"])
    if entry is None:
        return {"reports": []}
    return {
        "reports": [{"report": entry["report"], "params": _params(str(payload["question"]), entry.get("params", {}))}]
    }


def _explore(payload: dict[str, Any]) -> dict[str, Any]:
    found = [str(step["report"]) for step in payload.get("found", [])]
    entry = None if len(found) >= 2 else _closest_report(str(payload.get("focus", "")), payload["catalog"], found)
    if entry is None:
        return {"report": None, "params": {}}
    return {"report": entry["report"], "params": _params(str(payload.get("focus", "")), entry.get("params", {}))}


def _write(payload: dict[str, Any]) -> dict[str, Any]:
    """The first row of each report, in its own columns' names and its own values: no number of its own."""
    lines = []
    for part in payload["parts"]:
        rows = part.get("rows") or []
        if not rows:
            lines.append("لا صفوف في هذا التقرير.")
            continue
        columns = part.get("columns", {})
        lines.append(
            "الأعلى: " + "، ".join(f"{columns.get(name, name)} {value}" for name, value in rows[0].items()) + "."
        )
    return {"analysis": " ".join(lines)[:2500], "recommendations": []}


def _batch(payload: dict[str, Any]) -> dict[str, Any]:
    texts = payload.get("texts") if payload.get("mode") == "map" else payload.get("summaries")
    return {"summary": " ".join(str(t) for t in (texts or [])[:3])[:2000] or "لا نصوص.", "themes": []}


# ── every agent ─────────────────────────────────────────────────────────────────────────────────


def answer(agent: str, payload: dict[str, Any]) -> dict[str, Any]:
    """What the rules answer for `agent`, in the schema its model must follow (schemas/agents.py `…Answer`)."""
    limit = int(payload.get("limit", 10))
    match agent:
        case "search_listings":
            return {"results": _listings(payload["need"], payload["candidates"], limit)}
        case "search_requests":
            return {"results": _requests(payload["need"], payload["candidates"], limit)}
        case "matching":
            need = payload["need"]
            matched = _listings(need, payload.get("matched", []), limit)
            return {"matched": matched, "broaden": _listings(need, payload.get("broaden", []), limit)}
        case "match_searchers":
            return _searchers(payload)
        case "clean_listing":
            text = str(payload["text"])
            return {"desc_clean": clean(text)[:2000], "features": _features(text, payload.get("fields", {}))}
        case "clean_project":
            return {"desc_clean": clean(str(payload["text"]))[:2000]}
        case "clean_units":
            return {"units": [{"id": u["id"], "desc_clean": clean(str(u["text"]))[:1200]} for u in payload["units"]]}
        case "summarize":
            return _summary(payload)
        case "chat":
            return _chat(payload)
        case "report_select":
            return _select(payload)
        case "report_explore":
            return _explore(payload)
        case "report_write":
            return _write(payload)
        case "summarize_batch":
            return _batch(payload)
        case _:
            raise KeyError(agent)
