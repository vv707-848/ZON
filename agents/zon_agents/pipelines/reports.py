"""The SuperAgent's agents (architecture §10.7). The model never writes a query:

- **`report_select`** chooses an approved report and its parameters from the catalog Laravel sends. Laravel
  checks the name and every parameter's range, then runs the template's SQL as `ai_reader` on the views.
- **`report_write`** writes the analysis from the returned rows only. Laravel's NumberGuard rejects any number
  that is not in them.
- **`summarize_batch`** is the map/reduce path for thousands of texts: batches of up to 500, then one reduce
  call over the batch summaries. It runs from Laravel's queue, not in a live request.
"""

from typing import Any, Literal

from pydantic import BaseModel, create_model

from zon_agents.llm import ModelFailed, model_unavailable
from zon_agents.pipelines.base import Ctx, Result, parse, scrub
from zon_agents.schemas.agents import BatchAnswer, BatchInput, ReportSelectInput, ReportWriteAnswer, ReportWriteInput


def _choice_model(names: list[str]) -> type[BaseModel]:
    """The tool's schema: the report must be one of the catalog's names (the constrained decoding enforces it)."""
    report_type: Any = Literal[tuple(names)]
    return create_model("ReportChoice", report=(report_type, ...), params=(dict[str, Any], {}))


async def report_select(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ReportSelectInput, data)
    choice_model = _choice_model([entry.report for entry in req.catalog])
    try:
        answer = await ctx.llm.run("report_select", req, choice_model, "json", tool=True)
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    choice = answer.output.model_dump()
    declared = next(entry.params for entry in req.catalog if entry.report == choice["report"])
    params = {name: value for name, value in choice["params"].items() if name in declared}  # undeclared: dropped
    return Result(
        {"report": choice["report"], "params": params}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out
    )


async def report_write(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ReportWriteInput, data)
    try:
        answer = await ctx.llm.run("report_write", req, ReportWriteAnswer, "report")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    output = {
        "analysis": scrub(answer.output.analysis),
        "recommendations": [scrub(r) for r in answer.output.recommendations if scrub(r)],
    }
    return Result(output, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


async def summarize_batch(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(BatchInput, data)
    if not (req.texts if req.mode == "map" else req.summaries):
        return Result({"summary": "", "themes": []})
    try:
        answer = await ctx.llm.run("summarize_batch", req, BatchAnswer, "summary")
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc
    themes = [t.model_dump() for t in answer.output.themes]
    return Result(
        {"summary": scrub(answer.output.summary), "themes": themes},
        tokens_in=answer.tokens_in,
        tokens_out=answer.tokens_out,
    )
