"""The general chat (architecture §10.5): it informs from approved files, routes to the right service or package,
and answers in the asker's language. It never reads any user's data.

Laravel already did steps 1–2 (block list, 500 characters, language, rule-based intent; «خارج النطاق» never comes
here). For `knowledge` this pipeline retrieves the five closest passages across languages; under the minimum
similarity it says the question is not covered — without calling the model. The model answers from the given
passages (or the package facts Laravel sent) only, and cites the passages it used; the sources come from the
passages themselves, so a citation can never name a document that was not retrieved.

A message in a language Laravel's rules do not read comes as intent «auto»: the passages are retrieved as for a
knowledge question (bge-m3 matches across languages) and the packages sent, and the model names the intent itself;
«out_of_scope» is one sentence saying what the assistant answers. The answer is in the message's own language.

A citation stands only with its quote: the sentence the model says it relied on must be in that passage word for
word (diacritics and spacing aside). Otherwise it is dropped — a passage's first line is no evidence of an answer
it does not carry — and a knowledge answer with no citation left is «not covered». Knowledge answers are asked
at the profile's `summary` sampling (cooler than the conversation's).
"""

import re
from typing import Any

from zon_agents.llm import ModelFailed, model_unavailable
from zon_agents.pipelines.base import Ctx, Result, parse, scrub
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


_MARKS = re.compile(r"[\u064B-\u0652\u0640]")  # tashkeel and tatweel


def _plain(text: str) -> str:
    return " ".join(_MARKS.sub("", text).split())


def _quote(chunk: KnowledgeChunk, quote: str) -> str | None:
    """The original Arabic sentence shown under the answer: the model's quote, only when the passage carries it."""
    quote = quote.strip()
    return quote if quote and _plain(quote) in _plain(chunk.text) else None


async def chat(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ChatInput, data)
    auto = req.intent == "auto"
    chunks: list[KnowledgeChunk] = []
    if req.intent == "knowledge" or auto:
        chunks = await ctx.retrieval.knowledge(req.message, ctx.today())
        if not chunks and not auto:
            return Result(
                {"answer": NOT_COVERED[req.lang], "covered": False, "sources": [], "intent": "knowledge"},
                log={"passages": 0},
            )

    payload: dict[str, Any] = {
        "lang": req.lang,
        "intent": req.intent,
        "message": req.message,
        "history": [h.model_dump() for h in req.history],
        "passages": [
            {"n": n, "title": c.title, "effective_date": c.effective_date, "text": c.text}
            for n, c in enumerate(chunks, start=1)
        ],
        "packages": [p.model_dump(exclude_none=True) for p in req.packages],
    }
    try:
        cool = req.intent == "knowledge" or auto
        answer = await ctx.llm.run("chat", payload, ChatAnswer, "summary" if cool else "chat")
    except ModelFailed as exc:
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
    if intent == "out_of_scope":  # its one sentence on what it answers, in his language
        covered, text = False, scrub(answer.output.answer)
    else:
        covered = answer.output.covered and (intent != "knowledge" or bool(sources))
        text = scrub(answer.output.answer) if covered else NOT_COVERED[req.lang]
    return Result(
        {"answer": text, "covered": covered, "sources": sources if covered else [], "intent": intent},
        tokens_in=answer.tokens_in,
        tokens_out=answer.tokens_out,
        log={"passages": len(chunks)},
    )
