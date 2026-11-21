"""For the lab only: answers built from a case's expected answer, so each one's score is known before it runs.

- `perfect` — the right answer. Through the production pipeline it must score 1 on every case: when it does not,
  either the case contradicts itself or the pipeline loses what a right answer needs — no model could do better.
- `adversary` — the answer each limit of «قراءة النتيجة» exists to catch: the injected offer first, then the ones
  that break the budget or the rooms; a feature nobody mentioned and a number nobody gave; «covered» said the
  wrong way; a number the report's rows do not hold.
- `positional` — the candidates in the order they came: a model that ranks positions, not offers (`stability`).
- `broken` — never an answer of the schema: every call fails, as a model too weak for the format would.

None of them is a model, and none is used outside `zon-agents lab sim` and its tests.
"""

from typing import Any

from zon_agents.lab.cases import (
    Case,
    ChatExpect,
    CleanExpect,
    ExploreExpect,
    Grades,
    MatchingExpect,
    SearchersExpect,
    SelectExpect,
    SummaryExpect,
    Terms,
    WriteExpect,
)

NOT_A_UNIT = 999_999_999  # a unit model no project has
NOT_A_NUMBER = "987654"  # a number no case gives
_LEAD = {
    "en": "According to the Zon knowledge library, the answer is as follows:",
    "ur": "زون کی لائبریری کے مطابق جواب یہ ہے:",
    "fr": "Selon la bibliothèque de Zon, voici la réponse pour vous :",
    "tr": "Zon kütüphanesine göre bu paket için aylık fiyatı:",
    "ru": "Согласно библиотеке Zon, ответ такой:",
    "hi": "ज़ोन की लाइब्रेरी के अनुसार उत्तर यह है:",
}
_NOT_HERE = {  # what a right answer says outside the library, in the asker's language
    "hi": "मैं ज़ोन का संपत्ति सहायक हूँ: पैकेज, लाइसेंस, प्रकाशन, खोज और मिलान।",
}


def _latin(word: str) -> bool:
    return all(ord(ch) < 0x0600 for ch in word)


def _words(groups: Terms, lang: str | None = None) -> list[str]:
    """One word of every group: the first, or — for an English answer — one written in Latin letters if any."""
    if lang == "en":
        return [next((w for w in group if _latin(w)), group[0]) for group in groups]
    return [group[0] for group in groups]


def best_first(grades: Grades, offered: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    """The offered candidates that fit, the best first; one that breaks a condition is not offered at all."""
    ids = sorted((c["id"] for c in offered if grades.grades.get(c["id"], 0) > 0), key=lambda i: (-grades.grades[i], i))
    return [{"id": i, "score": round(grades.grades[i] / 3, 3)} for i in ids[:limit]]


def worst_first(grades: Grades, offered: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    """The injected candidate first, then the ones that break a condition, then the rest from the worst."""
    ids = sorted((c["id"] for c in offered), key=lambda i: (i != grades.inject, grades.grades.get(i, 0), i))
    return [{"id": i, "score": round(1 - n / 100, 3)} for n, i in enumerate(ids[:limit])]


def as_given(offered: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    return [{"id": c["id"], "score": round(1 - n / 100, 3)} for n, c in enumerate(offered[:limit])]


# ── the right answer ────────────────────────────────────────────────────────────────────────────


def perfect(case: Case, payload: dict[str, Any]) -> dict[str, Any]:
    limit = int(payload.get("limit", 10))
    match case.agent:
        case "search_listings" | "search_requests":
            return {"results": best_first(case.expected(Grades), payload["candidates"], limit)}
        case "matching":
            matching = case.expected(MatchingExpect)
            broaden = best_first(matching.broaden, payload["broaden"], limit) if matching.broaden else []
            return {"matched": best_first(matching.matched, payload["matched"], limit), "broaden": broaden}
        case "match_searchers":
            searchers = case.expected(SearchersExpect)
            ranked = best_first(searchers, payload["searchers"], limit)
            return {"results": [{**r, "unit_id": next(iter(searchers.units.get(r["id"], [])), None)} for r in ranked]}
        case "clean_listing":
            clean = case.expected(CleanExpect)
            return {"desc_clean": " ، ".join(_words(clean.keep)) or "وصف العقار", "features": _words(clean.features)}
        case "summarize":
            summary = case.expected(SummaryExpect)
            signals = _words(summary.signals)
            return {
                "summary": " ، ".join([*signals, *_words(summary.keep)]) or "ملخص الملاحظات",
                "signals": [s for s in signals if len(s) <= 60][:6],
                "recommended_action": "متابعة",
                "confidence": summary.confidence[0],
            }
        case "chat":
            chat = case.expected(ChatExpect)
            named = {"intent": chat.intent} if chat.intent else {}
            if not chat.covered:
                said = _NOT_HERE.get(chat.lang or "", "لا أملك معلومة موثقة عن هذا.")
                return {"answer": said, "covered": False, "citations": [], **named}
            passages = case.context.get("passages", [])
            cited = [  # a right answer quotes the passage it relies on, word for word
                {"passage": n, "quote_ar": str(p["text"]).split(".")[0].strip()}
                for n, p in enumerate(passages, start=1)
                if p["doc_id"] in chat.cite
            ][:1]
            lead = _LEAD.get(chat.lang or "", "")
            answer = " ".join([lead, " ، ".join(_words(chat.mention, chat.lang))]).strip() or "نعم."
            return {"answer": answer, "covered": True, "citations": cited, **named}
        case "report_select":
            select = case.expected(SelectExpect)
            return {"reports": [{"report": item.reports[0], "params": _given(item.params)} for item in select.reports]}
        case "report_explore":
            explore = case.expected(ExploreExpect)
            report = explore.report[0]
            return {"report": report, "params": {} if report is None else _given(explore.params)}
        case _:  # report_write
            write = case.expected(WriteExpect)
            actions = [{"text": "توصية", "action": write.actions[0], "params": {}}] if write.actions else []
            return {"analysis": " ، ".join(_words(write.mention)) or "تحليل الصفوف", "recommendations": actions}


def _given(params: dict[str, Any]) -> dict[str, Any]:
    return {name: value for name, value in params.items() if value is not None}  # None: the parameter stays unset


# ── the answer every limit exists to catch ──────────────────────────────────────────────────────


def adversary(case: Case, payload: dict[str, Any]) -> dict[str, Any]:
    limit = int(payload.get("limit", 10))
    match case.agent:
        case "search_listings" | "search_requests":
            return {"results": worst_first(case.expected(Grades), payload["candidates"], limit)}
        case "matching":
            matching = case.expected(MatchingExpect)
            broaden = worst_first(matching.broaden, payload["broaden"], limit) if matching.broaden else []
            return {"matched": worst_first(matching.matched, payload["matched"], limit), "broaden": broaden}
        case "match_searchers":
            ranked = worst_first(case.expected(SearchersExpect), payload["searchers"], limit)
            return {"results": [{**r, "unit_id": NOT_A_UNIT} for r in ranked]}
        case "clean_listing":
            clean = case.expected(CleanExpect)
            never = [*clean.absent, *clean.not_features][:1] or ["مسبح أولمبي"]
            return {"desc_clean": f"{never[0]} بسعر {NOT_A_NUMBER} ريال", "features": never}
        case "summarize":
            summary = case.expected(SummaryExpect)
            wrong = next(c for c in ("high", "medium", "low") if c not in summary.confidence)
            return {
                "summary": f"ميزانيته {NOT_A_NUMBER} ريال",
                "signals": [],
                "recommended_action": "",
                "confidence": wrong,
            }
        case "chat":
            chat = case.expected(ChatExpect)
            if chat.covered:  # and, asked «auto», a message it says is not about property
                return {"answer": "لا أعرف.", "covered": False, "citations": [], "intent": "out_of_scope"}
            return {
                "answer": "نعم، هذا مؤكد.",
                "covered": True,
                "citations": [{"passage": 1, "quote_ar": ""}],
                "intent": "knowledge",
            }
        case "report_select":
            select = case.expected(SelectExpect)
            right = {name for item in select.reports for name in item.reports}
            other = next(entry["report"] for entry in payload["catalog"] if entry["report"] not in right)
            return {"reports": [{"report": other, "params": {}}]}
        case "report_explore":
            explore = case.expected(ExploreExpect)
            other = next(e["report"] for e in payload["catalog"] if e["report"] not in explore.report)
            return {"report": other, "params": {}}  # a step that deepens nothing — wrong even where stopping is right
        case _:  # report_write
            return {"analysis": f"ارتفع الطلب {NOT_A_NUMBER} مرة.", "recommendations": []}


# ── the model that ranks positions ──────────────────────────────────────────────────────────────


def positional(case: Case, payload: dict[str, Any]) -> dict[str, Any]:
    """Whatever came first is first. The other agents answer rightly: only the order is under test."""
    limit = int(payload.get("limit", 10))
    match case.agent:
        case "search_listings" | "search_requests":
            return {"results": as_given(payload["candidates"], limit)}
        case "matching":
            return {"matched": as_given(payload["matched"], limit), "broaden": as_given(payload["broaden"], limit)}
        case "match_searchers":
            return {"results": [{**r, "unit_id": None} for r in as_given(payload["searchers"], limit)]}
        case _:
            return perfect(case, payload)
