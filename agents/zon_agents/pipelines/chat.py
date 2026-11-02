"""The general chat (architecture §10.5): it informs from approved files, routes to the right service or package,
and answers in the asker's language. It never reads any user's data.

Laravel already did steps 1–2 (block list, 500 characters, language, rule-based intent; «خارج النطاق» never comes
here). For `knowledge` this pipeline retrieves the five closest passages across languages; under the minimum
similarity it says the question is not covered — without calling the model. The model answers from the given
passages (or the package facts Laravel sent) only, and cites the passages it used; the sources come from the
passages themselves, so a citation can never name a document that was not retrieved.
"""

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


def _quote(chunk: KnowledgeChunk, quote: str) -> str:
    """The original Arabic sentence shown under a translated answer: the model's quote when it is really in the
    passage, otherwise the passage's first sentence."""
    quote = quote.strip()
    if quote and quote in chunk.text:
        return quote
    return chunk.text.split(".")[0].split("\n")[0][:300]


async def chat(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ChatInput, data)
    chunks: list[KnowledgeChunk] = []
    if req.intent == "knowledge":
        chunks = await ctx.retrieval.knowledge(req.message, ctx.today())
        if not chunks:
            return Result({"answer": NOT_COVERED[req.lang], "covered": False, "sources": []}, log={"passages": 0})

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
        answer = await ctx.llm.run("chat", payload, ChatAnswer, "chat")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc

    sources: list[dict[str, Any]] = []
    for citation in answer.output.citations:
        if not 1 <= citation.passage <= len(chunks):
            continue
        chunk = chunks[citation.passage - 1]
        if any(s["doc_id"] == chunk.doc_id for s in sources):
            continue
        sources.append(
            {
                "doc_id": chunk.doc_id,
                "title": chunk.title,
                "effective_date": chunk.effective_date,
                "quote_ar": _quote(chunk, citation.quote_ar),
            }
        )
    covered = answer.output.covered and (req.intent != "knowledge" or bool(sources))
    text = scrub(answer.output.answer) if covered else NOT_COVERED[req.lang]
    return Result(
        {"answer": text, "covered": covered, "sources": sources if covered else []},
        tokens_in=answer.tokens_in,
        tokens_out=answer.tokens_out,
        log={"passages": len(chunks)},
    )
