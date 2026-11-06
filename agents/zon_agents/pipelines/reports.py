"""The SuperAgent's agents (architecture §10.7). The model never writes a query:

- **`report_select`** chooses, for a message of one question or a few, the approved reports that answer it (three at
  most, none when no report does) and their parameters, from the catalog Laravel sends. Laravel checks every name
  and parameter range, then runs each template's SQL as `ai_reader` on the views.
- **`report_explore`** chooses the next report of an exploration from what the earlier ones found, or none when the
  picture is clear. Laravel runs it and calls again, four reports at most.
- **`report_write`** writes one analysis from the rows of every report that ran, and may attach a proposal of the
  catalog Laravel sends to a recommendation (a screen of the panel to open — it never acts). Laravel's NumberGuard
  rejects any number that is not in the rows.
- **`summarize_batch`** is the map/reduce path for thousands of texts: batches of up to 500, then one reduce
  call over the batch summaries. It runs from Laravel's queue, not in a live request.

The schemas are built per request: a report or a proposal can only be one of the names Laravel sent (constrained
decoding enforces it), and a parameter the catalog does not declare is dropped.
"""

from typing import Annotated, Any, Literal

from pydantic import BaseModel, Field, create_model

from zon_agents.llm import LlmResult, ModelFailed, model_unavailable
from zon_agents.pipelines.base import Ctx, Result, parse, scrub
from zon_agents.profiles import Use
from zon_agents.schemas.agents import (
    MAX_QUESTIONS,
    BatchAnswer,
    BatchInput,
    ReportExploreInput,
    ReportSelectInput,
    ReportWriteInput,
)


def _one_of(names: list[str]) -> Any:
    return Literal[tuple(names)]


def _choices_model(names: list[str]) -> type[BaseModel]:
    choice = create_model("ReportChoice", report=(_one_of(names), ...), params=(dict[str, Any], {}))
    return create_model("ReportChoices", reports=(Annotated[list[choice], Field(max_length=MAX_QUESTIONS)], ...))


def _next_model(names: list[str]) -> type[BaseModel]:
    return create_model("NextReport", report=(_one_of(names) | None, ...), params=(dict[str, Any], {}))


def _written_model(actions: list[str]) -> type[BaseModel]:
    action: Any = _one_of(actions) | None if actions else None
    recommendation = create_model(
        "Recommendation",
        text=(Annotated[str, Field(max_length=300)], ...),
        action=(action, None),
        params=(dict[str, Any], {}),
    )
    return create_model(
        "ReportWritten",
        analysis=(Annotated[str, Field(min_length=1, max_length=2500)], ...),
        recommendations=(Annotated[list[recommendation], Field(max_length=5)], []),
    )


def _declared(params: dict[str, Any], declared: dict[str, Any]) -> dict[str, Any]:
    return {name: value for name, value in params.items() if name in declared}  # undeclared: dropped


async def _answer(ctx: Ctx, agent: str, req: BaseModel, output: type[BaseModel], use: Use) -> LlmResult[BaseModel]:
    try:
        return await ctx.llm.run(agent, req, output, use)
    except ModelFailed as exc:
        raise model_unavailable(exc) from exc


async def report_select(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ReportSelectInput, data)
    declared = {entry.report: entry.params for entry in req.catalog}
    answer = await _answer(ctx, "report_select", req, _choices_model(list(declared)), "json")
    reports: list[dict[str, Any]] = []
    for choice in answer.output.model_dump()["reports"]:
        item = {"report": choice["report"], "params": _declared(choice["params"], declared[choice["report"]])}
        if item not in reports:  # the same report twice runs once
            reports.append(item)
    return Result({"reports": reports}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out)


async def report_explore(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ReportExploreInput, data)
    declared = {entry.report: entry.params for entry in req.catalog}
    answer = await _answer(ctx, "report_explore", req, _next_model(list(declared)), "json")
    choice = answer.output.model_dump()
    params = {} if choice["report"] is None else _declared(choice["params"], declared[choice["report"]])
    return Result(
        {"report": choice["report"], "params": params}, tokens_in=answer.tokens_in, tokens_out=answer.tokens_out
    )


async def report_write(ctx: Ctx, data: dict[str, Any]) -> Result:
    req = parse(ReportWriteInput, data)
    declared = {entry.action: entry.params for entry in req.proposals}
    answer = await _answer(ctx, "report_write", req, _written_model(list(declared)), "report")
    written = answer.output.model_dump()
    recommendations = []
    for item in written["recommendations"]:
        text = scrub(item["text"])
        if text:
            action = item["action"]
            params = _declared(item["params"], declared[action]) if action else {}
            recommendations.append({"text": text, "action": action, "params": params})
    output = {"analysis": scrub(written["analysis"]), "recommendations": recommendations}
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
