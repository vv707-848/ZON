"""Agents that write text: the summary of notes (§10.6) and the intake cleaning of descriptions (§10.2 · §10.2أ).

These have no degraded answer: when the model fails the call fails (503) and Laravel decides —
- a summary shows the notes as they are;
- a cleaning job waits in its queue and runs again when the AI node is back, so no listing is lost.
"""

from typing import Any

from zon_agents.llm import ModelFailed, model_unavailable
from zon_agents.pipelines.base import Ctx, Result, parse, scrub
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
    output = {"desc_clean": scrub(answer.output.desc_clean), "features": features}
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


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
