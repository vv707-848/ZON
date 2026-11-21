"""Agents that write text: the summary of notes (§10.6) and the intake cleaning of descriptions (§10.2 · §10.2أ).

These have no degraded answer: when the model fails the call fails (503) and Laravel decides —
- a summary shows the notes as they are;
- a cleaning job waits in its queue and runs again when the AI node is back, so no listing is lost.
"""

import re
from contextvars import ContextVar
from typing import Any

from zon_agents.llm import ModelFailed, model_unavailable
from zon_agents.numbers import grounded_amounts, norm, swapped
from zon_agents.pipelines.base import _EMAIL, _PHONE, _URL, Ctx, Result, parse, scrub
from zon_agents.schemas.agents import (
    CleanListingAnswer,
    CleanListingInput,
    CleanProjectAnswer,
    CleanProjectInput,
    CleanUnitsAnswer,
    CleanUnitsInput,
    SummarizeInput,
    SummaryAnswer,
)


async def summarize(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(SummarizeInput, data)
    try:
        answer = await ctx.llm.run("summarize", req, SummaryAnswer, "summary")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    out = answer.output
    output = {
        "summary": scrub(out.summary),
        "signals": [scrub(s) for s in out.signals if scrub(s)],
        "recommended_action": scrub(out.recommended_action),
        "confidence": out.confidence,
    }
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


async def clean_listing(ctx: Ctx, data: dict[str, Any]) -> Result:
    """A suggestion, not an approval: the advertiser approves, edits or rejects it before anything is indexed."""
    req = parse(CleanListingInput, data)
    try:
        answer = await ctx.llm.run("clean_listing", req, CleanListingAnswer, "json")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    features = [scrub(f) for f in answer.output.features if scrub(f)]
    desc = scrub(answer.output.desc_clean)
    output = {"desc_clean": desc, "features": features}
    # a wording that changes an amount is no wording: «2 مليون و200 الف» written 2200200 (A40, 21 Nov). Laravel then
    # publishes his text as he wrote it, so no amount he did not write reaches a buyer
    if not grounded_amounts(desc, req.text, req.fields):
        raise model_unavailable(ModelFailed("amount"))
    # …or puts an amount in another's place (the price as the area), or a deed he did not state: a buyer relies on both
    if swapped(desc, req.text, req.fields):
        raise model_unavailable(ModelFailed("role"))
    if not _deed_kept(desc, features, req.text, req.fields.get("deed")):
        if (kept := DEED_REFUSALS.get()) is not None:  # the lab's record of why (never set in production)
            kept.append(deed_refusal(desc, features, req.text, req.fields.get("deed")))
        raise model_unavailable(ModelFailed("deed"))
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


_DEED = re.compile(r"(?<!\w)(?:و|ب|وب|ل|فب)?(?:ال)?صك(?:وك)?(?!\w)")
# what says a deed is not there: before it («بدون صك»، «لا يوجد صك»، «ما فيه صك») or after it («الصك غير جاهز»)
_BEFORE = {
    "بدون",
    "بلا",
    "لا",
    "ما",
    "مافي",
    "مافيه",
    "ليس",
    "ليست",
    "غير",
    "يوجد",
    "فيه",
    "فيها",
    "عليه",
    "عليها",
    "مو",
}
_NOT_BEFORE = {"بدون", "بلا", "لا", "ما", "مافي", "مافيه", "ليس", "ليست", "مو"}
_NOT_AFTER = {"غير", "مافي", "ما", "لا", "مو"}
_BETWEEN = {"يوجد", "فيه", "فيها", "عليه", "عليها", "له", "لها"}


def _deeds(text: str) -> set[bool]:
    """What [text] says of a deed, each time it names one: True (there is one), False (there is none)."""
    words = re.findall(r"\w+", norm(text))
    said: set[bool] = set()
    for n, word in enumerate(words):
        if not _DEED.fullmatch(word):
            continue
        one = words[n - 1] if n else ""
        two = words[n - 2] if n > 1 else ""
        # «بدون صك»، or «لا يوجد صك» · «ما فيه صك» — never a «بدون» of something else before it («بدون مصعد، الصك جاهز»)
        before = one in _NOT_BEFORE or (one in _BETWEEN and two in _NOT_BEFORE)
        after = words[n + 1] if n + 1 < len(words) else ""
        said.add(not (before or after in _NOT_AFTER))
    return said


def _deed_kept(desc: str, features: list[str], text: str, field: object) -> bool:
    """A deed the wording or the features name — that there is one, or that there is none — is one the advertiser's
    text or the deed field says the same way. «صك» as a feature says there is one."""
    claims = _deeds(desc) | {True for f in features if _DEED.search(norm(f)) and False not in _deeds(f)}
    claims |= {False for f in features if False in _deeds(f)}
    stated = _deeds(text) | ({field} if isinstance(field, bool) else set())
    return claims <= stated


# ── why a wording was refused for its deed: the lab's record only (owner, 21 Nov 2026) ───────────────
# The lab sets DEED_REFUSALS for the case it runs; production never does, so nothing of a refused wording is kept
# there. The reports go to a public repository: the excerpt is the fewest words that show what was said of the deed,
# with names, contacts and document numbers taken out first (redact).
DEED_REFUSALS: ContextVar[list[dict[str, str]] | None] = ContextVar("deed_refusals", default=None)

_AROUND = 2  # words on each side of «صك»: its negation is the word next to it (_deeds)
_NAME_AFTER = re.compile(
    r"(?<!\w)(?:الاسم|اسمي|اسم|المالك|المسوق|الوسيط|الوكيل|المكتب|مكتب|مؤسسة|شركة|أبو|ابو|أم|ام|أخوك|اخوك|معك)"
    r"[\s:：-]+(?:[^\s\d،,.؛]+)(?:\s+(?!صك|الصك)[^\s\d،,.؛]+)?"
)
_HANDLE = re.compile(r"@\w+")
_DOCUMENT = re.compile(r"\d[\d\s\-/]{4,}\d|\d{5,}")  # a deed, licence or ID number: five digits or more


def redact(text: str) -> str:
    """Names, contacts and document numbers out of a text the lab keeps: «[اسم]» · «[رابط]» · «[بريد]» · «[حساب]» ·
    «[رقم]»."""
    for pattern, mark in (
        (_URL, "[رابط]"),
        (_EMAIL, "[بريد]"),
        (_HANDLE, "[حساب]"),
        (_PHONE, "[رقم]"),
        (_DOCUMENT, "[رقم]"),
    ):
        text = pattern.sub(mark, text)
    text = _NAME_AFTER.sub(lambda m: m.group(0).split()[0].rstrip(":：-") + " [اسم]", text)
    return " ".join(text.split())


def _deed_excerpt(text: str) -> str:
    """The fewest words of [text] around its first «صك»: what was said of the deed."""
    words = text.split()
    for n, word in enumerate(words):
        if _DEED.fullmatch(re.sub(r"[^\w]", "", norm(word))):
            return " ".join(words[max(0, n - _AROUND) : n + _AROUND + 1])
    return " ".join(words[: 2 * _AROUND + 1])


def deed_refusal(desc: str, features: list[str], text: str, field: object) -> dict[str, str]:
    """Why a wording was refused for its deed: where («desc» or «features»), what it claimed against what the text
    and the field say, and the excerpt — redacted."""
    stated = _deeds(text) | ({field} if isinstance(field, bool) else set())
    said = [("desc", desc, _deeds(desc))] + [
        ("features", f, {False} if False in _deeds(f) else {True}) for f in features if _DEED.search(norm(f))
    ]
    for source, where, claims in said:
        for claim in sorted(claims - stated):
            against = "the text says the opposite" if (not claim) in stated else "the text says nothing of a deed"
            reason = f"{'a deed' if claim else 'no deed'} where {against}"
            return {"reason": reason, "source": source, "excerpt": redact(_deed_excerpt(where))}
    return {"reason": "deed", "source": "desc", "excerpt": redact(_deed_excerpt(desc))}


async def clean_project(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(CleanProjectInput, data)
    try:
        answer = await ctx.llm.run("clean_project", req, CleanProjectAnswer, "json")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    return Result(
        {"desc_clean": scrub(answer.output.desc_clean)}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out
    )


async def clean_units(ctx: Ctx, data: dict[str, Any]) -> Result:
    """One call for all the unit models of a project. A model the answer leaves out (or an id that is not one of
    them) is simply absent — Laravel keeps that unit's original text."""
    req = parse(CleanUnitsInput, data)
    try:
        answer = await ctx.llm.run("clean_units", req, CleanUnitsAnswer, "json")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    asked = {u.id for u in req.units}
    units: list[dict[str, Any]] = []
    for unit in answer.output.units:
        text = scrub(unit.desc_clean)
        if unit.id in asked and text:
            units.append({"id": unit.id, "desc_clean": text})
            asked.discard(unit.id)
    return Result({"units": units}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)
