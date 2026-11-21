"""The general chat (architecture §10.5): it informs from approved files, routes to the right service or package,
and answers in the asker's language. It never reads any user's data.

Laravel already did steps 1–2 (block list, 500 characters, language, rule-based intent; «خارج النطاق» never comes
here). For `knowledge` this pipeline retrieves the five closest passages across languages; under the minimum
similarity it says the question is not covered — without calling the model. The model answers from the given
passages only, and cites the passages it used; the sources come from the
passages themselves, so a citation can never name a document that was not retrieved.

A message in a language Laravel's rules do not read comes as intent «auto»: the passages are retrieved as for a
knowledge question (bge-m3 matches across languages), and the model names the intent itself;
«out_of_scope» is one sentence saying what the assistant answers. The answer is in the message's own language.

A plan question gets no price from here: a fixed pointer to the plans page in the asker's language (PLANS_GUIDANCE),
with no model call — and when the model named the intent «packages» itself, its answer is replaced by it.

A citation stands only with its quote: the sentence the model says it relied on must be in that passage word for
word (diacritics and spacing aside). Otherwise it is dropped — a passage's first line is no evidence of an answer
it does not carry — and a knowledge answer with no citation left is «not covered». Knowledge answers are asked
at the profile's `summary` sampling (cooler than the conversation's).
"""

import re
import time
from typing import Any

from zon_agents.llm import ModelFailed, model_unavailable
from zon_agents.pipelines.base import Ctx, Result, parse, scrub
from zon_agents.profiles import Use
from zon_agents.schemas.agents import ChatAnswer, ChatInput
from zon_agents.store import KnowledgeChunk

NOT_COVERED = {
    "ar": "لا تغطي مكتبة زون هذا السؤال بعد. للجواب الرسمي راجع الهيئة العامة للعقار أو الجهة المختصة.",
    "en": "Zon's library does not cover this question yet. For an official answer, please contact the Real Estate "
    "General Authority or the competent body.",
    "ur": "زون کی معلوماتی لائبریری ابھی اس سوال کا احاطہ نہیں کرتی۔ "
    "سرکاری جواب کے لیے رئیل اسٹیٹ جنرل اتھارٹی سے رجوع کریں۔",
}
NOT_COVERED["xx"] = NOT_COVERED["en"]  # another language: the fixed text in the most widely read one
# …or in its own, where its script says which it is
_NOT_COVERED_SCRIPT = {
    "cyrillic": "Библиотека Zon пока не охватывает этот вопрос. За официальным ответом обратитесь в Главное "
    "управление по недвижимости (REGA) или в компетентный орган.",
    "devanagari": "ज़ोन की लाइब्रेरी अभी इस सवाल को कवर नहीं करती। आधिकारिक जवाब के लिए रियल एस्टेट जनरल अथॉरिटी "
    "(REGA) या संबंधित विभाग से संपर्क करें।",
}

# The script a text is written in, by its letters: what the answer's language can be checked by without a model.
_SCRIPTS = {
    "arabic": re.compile(r"[\u0600-\u06FF\u0750-\u077F]"),
    "latin": re.compile(r"[A-Za-z\u00C0-\u024F]"),
    "cyrillic": re.compile(r"[\u0400-\u04FF]"),
    "devanagari": re.compile(r"[\u0900-\u097F]"),
}
# What `reply_in` says: Laravel's language, or for another one («xx») the script the message is written in
_REPLY_IN = {"ar": "العربية", "en": "English", "ur": "اردو"}
_REPLY_IN_SCRIPT = {
    "latin": "the language of `message` (not Arabic)",
    "cyrillic": "русский",
    "devanagari": "हिन्दी",
    "arabic": "لغة `message`",
}


def script(text: str) -> str:
    """The script most of [text]'s letters are in ("" without letters)."""
    counts = {name: len(pattern.findall(text)) for name, pattern in _SCRIPTS.items()}
    best = max(counts, key=lambda name: counts[name])
    return best if counts[best] else ""


# Plans (owner, 21 Nov 2026): the chat says no price. A plan question gets this short pointer in the asker's language,
# and Laravel shows the button to /account/plans under it (ChatAssistant::ACTIONS) — the page shows the prices as
# they are today. No model is asked for it, and a plan answer a model wrote («199 ليرة تركية», A40 21 Nov) is
# replaced by it. The same texts as Laravel's (docs/contracts/fixtures/chat/plans_guidance.json).
PLANS_GUIDANCE = {
    "ar": "أسعار الباقات وتفاصيلها في صفحة «الباقات»: اضغط الزر تحت هذه الرسالة وتشوفها محدّثة.",
    "en": "Plan prices and details are on the Plans page: tap the button below this message to see them up to date.",
    "ur": "پیکجز کی قیمتیں اور تفصیل «پیکجز» کے صفحے پر ہیں: تازہ ترین دیکھنے کے لیے اس پیغام کے نیچے بٹن دبائیں۔",
    "tr": "Paketlerin fiyatları ve ayrıntıları «Paketler» sayfasında: güncel hâlini görmek için bu mesajın altındaki "
    "düğmeye dokunun.",
    "ru": "Цены и условия тарифов — на странице «Тарифы»: нажмите кнопку под этим сообщением, чтобы увидеть "
    "актуальные.",
    "hi": "पैकेज की कीमतें और विवरण «पैकेज» पेज पर हैं: ताज़ा जानकारी के लिए इस संदेश के नीचे दिया बटन दबाएँ।",
}
# Turkish among the Latin-script languages: letters only it uses, or a word of its own («paketi», «aylık»)
# (the letters without IGNORECASE: «ı» folds to «i» there, and every French «i» read as Turkish)
_TURKISH = re.compile(
    r"[ğĞışŞİ]|\b(?:paket\w*|fiyat\w*|ücret\w*|aylık|yıllık|kadar|nedir|nasıl|için|abonelik|üyelik)\b"
)


def guidance_lang(lang: str, message: str) -> str:
    """The language of the plans pointer: Laravel's (ar · en · ur), or for another one its script, Turkish by its
    letters and words, else English."""
    if lang in ("ar", "en", "ur"):
        return lang
    written = script(message)
    if written == "cyrillic":
        return "ru"
    if written == "devanagari":
        return "hi"
    return "tr" if written == "latin" and _TURKISH.search(message.lower()) else "en"


def _plans(lang: str, message: str, log: dict[str, Any] | None = None, tokens: tuple[int, int] = (0, 0)) -> Result:
    text = PLANS_GUIDANCE[guidance_lang(lang, message)]
    output = {"answer": text, "covered": True, "sources": [], "intent": "packages"}
    return Result(output, tokens_in=tokens[0], tokens_out=tokens[1], log=log or {"plans": "guidance"})


_FIRST = re.compile(r"[^.!؟?\n]+[.!؟?]?")
_ACCORDING = {
    "ar": "بحسب «{title}» ({date}): {quote}",
    "ur": "«{title}» ({date}) کے مطابق، سرکاری عربی متن: {quote}",
    "en": "According to «{title}» ({date}), in its official Arabic text: {quote}",
}


def _excerpt(lang: str, chunk: KnowledgeChunk, reason: str) -> Result:
    """The model out of time on a knowledge question (both chat timeouts of 21 Nov, A40, were): the closest
    passage's first sentence word for word, with its source — the documented excerpt, no wording of a model's.
    Degraded: the lab counts it among the failures, and Laravel's guard judges it as any knowledge answer."""
    first = _FIRST.match(chunk.text.strip())
    quote = (first.group(0) if first else chunk.text).strip()[:200]
    text = _ACCORDING.get(lang, _ACCORDING["en"]).format(title=chunk.title, date=chunk.effective_date, quote=quote)
    source = {"doc_id": chunk.doc_id, "title": chunk.title, "effective_date": chunk.effective_date, "quote_ar": quote}
    output = {"answer": text, "covered": True, "sources": [source], "intent": "knowledge"}
    return Result(output, degraded=True, log={"llm": reason, "passages": 1})


def _not_covered(lang: str, message: str) -> str:
    return _NOT_COVERED_SCRIPT.get(script(message), NOT_COVERED[lang]) if lang == "xx" else NOT_COVERED[lang]


def _expected(lang: str, message: str) -> str:
    return {"ar": "arabic", "ur": "arabic", "en": "latin"}.get(lang) or script(message)


_MARKS = re.compile(r"[\u064B-\u0652\u0640]")  # tashkeel and tatweel


def _plain(text: str) -> str:
    return " ".join(_MARKS.sub("", text).split())


def _quote(chunk: KnowledgeChunk, quote: str) -> str | None:
    """The original Arabic sentence shown under the answer: the model's quote, only when the passage carries it."""
    quote = quote.strip()
    return quote if quote and _plain(quote) in _plain(chunk.text) else None


async def chat(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ChatInput, data)
    if req.intent == "packages":  # the pointer to the plans page, no model: the chat says no price
        return _plans(req.lang, req.message)
    auto = req.intent == "auto"
    chunks: list[KnowledgeChunk] = []
    if req.intent == "knowledge" or auto:
        chunks = await ctx.retrieval.knowledge(req.message, ctx.today())
        if not chunks and not auto:
            return Result(
                {"answer": _not_covered(req.lang, req.message), "covered": False, "sources": [], "intent": "knowledge"},
                log={"passages": 0},
            )

    payload: dict[str, Any] = {
        "lang": req.lang,
        "role": req.role,
        "intent": req.intent,
        "message": req.message,
        "history": [h.model_dump() for h in req.history],
        "passages": [
            {"n": n, "title": c.title, "effective_date": c.effective_date, "text": c.text}
            for n, c in enumerate(chunks, start=1)
        ],
    }
    wanted = _expected(req.lang, req.message)
    payload["reply_in"] = _REPLY_IN.get(req.lang) or _REPLY_IN_SCRIPT.get(wanted, "the language of `message`")
    cool = req.intent == "knowledge" or auto
    use: Use = "summary" if cool else "chat"
    started = time.monotonic()
    try:
        answer = await ctx.llm.run("chat", payload, ChatAnswer, use)
        # an answer in another script than the message's is asked once more, when the first came quickly enough that
        # a second still fits the budget (A40, 21 Nov: English, Turkish and Russian answered in Arabic)
        written = script(answer.output.answer)
        if wanted and written and written != wanted and time.monotonic() - started < ctx.llm.budget_seconds / 2:
            again = {**payload, "reply_in": f"{payload['reply_in']} — your last answer was in another language"}
            retry = await ctx.llm.run("chat", again, ChatAnswer, use)
            if script(retry.output.answer) == wanted:
                answer = retry
    except ModelFailed as exc:
        if req.intent == "knowledge" and chunks:  # out of time: the documented excerpt itself, said to be degraded
            return _excerpt(req.lang, chunks[0], exc.reason)
        raise model_unavailable(exc) from exc

    sources: list[dict[str, Any]] = []
    for citation in answer.output.citations:
        if not 1 <= citation.passage <= len(chunks):
            continue
        chunk = chunks[citation.passage - 1]
        quote = _quote(chunk, citation.quote_ar)
        if quote is None or any(s["doc_id"] == chunk.doc_id for s in sources):
            continue
        sources.append(
            {"doc_id": chunk.doc_id, "title": chunk.title, "effective_date": chunk.effective_date, "quote_ar": quote}
        )
    intent = (answer.output.intent or "out_of_scope") if auto else req.intent
    if intent == "packages":  # named by the model («auto»): whatever it wrote, the pointer in his language
        return _plans(
            req.lang, req.message, {"passages": len(chunks), "plans": "replaced"}, (answer.tokens_in, answer.tokens_out)
        )
    if intent == "out_of_scope":  # its one sentence on what it answers, in his language
        covered, text = False, scrub(answer.output.answer)
    else:
        # only a knowledge answer is «not covered» (by the passages it quotes): any other intent's answer is the
        # service it points to, and a model that said otherwise would put «not in the library» over a right answer
        covered = intent != "knowledge" or (answer.output.covered and bool(sources))
        text = scrub(answer.output.answer) if covered else _not_covered(req.lang, req.message)
    return Result(
        {"answer": text, "covered": covered, "sources": sources if covered else [], "intent": intent},
        tokens_in=answer.tokens_in,
        tokens_out=answer.tokens_out,
        log={"passages": len(chunks)},
    )
