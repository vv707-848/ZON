"""The MCP server (architecture §8 «دور MCP»): the same tools, one definition, visible to any agent.

Every tool runs the pipeline the HTTP route runs — no second code path. `run_report` is not exposed: reports run
in Laravel, on the read-only `ai_reader` views.
"""

from typing import Any

from mcp.server import MCPServer

from zon_agents.pipelines import ranking, reports, text
from zon_agents.pipelines.base import Ctx
from zon_agents.schemas.agents import (
    BatchInput,
    CleanListingInput,
    MatchingInput,
    MatchSearchersInput,
    SearchListingsInput,
    SearchRequestsInput,
    SummarizeInput,
)


def build_mcp(ctx: Ctx) -> MCPServer:
    mcp: MCPServer = MCPServer(
        name="zon-agents", instructions="Zon real-estate tools. Inputs are masked; ids come from SQL."
    )

    @mcp.tool(name="semantic_search_listings", description="Hybrid search of listings inside SQL candidates.")
    async def semantic_search_listings(request: SearchListingsInput) -> dict[str, Any]:
        return (await ranking.search_listings(ctx, request.model_dump())).output

    @mcp.tool(name="semantic_search_requests", description="Hybrid search of users' requests (brokers).")
    async def semantic_search_requests(request: SearchRequestsInput) -> dict[str, Any]:
        return (await ranking.search_requests(ctx, request.model_dump())).output

    @mcp.tool(name="match_to_listings", description="A wish against listings: matched + broaden, with reasons.")
    async def match_to_listings(request: MatchingInput) -> dict[str, Any]:
        result = await ranking.matching(ctx, {**request.model_dump(), "units": []})
        return {"matched": result.output["matched"], "broaden": result.output["broaden"]}

    @mcp.tool(name="match_to_units", description="A wish against available unit models (shown as projects).")
    async def match_to_units(request: MatchingInput) -> dict[str, Any]:
        result = await ranking.matching(ctx, {**request.model_dump(), "matched": [], "broaden": []})
        return {"units": result.output["units"]}

    @mcp.tool(name="match_searchers", description="A project's unit models against searchers' wishes.")
    async def match_searchers(request: MatchSearchersInput) -> dict[str, Any]:
        return (await ranking.match_searchers(ctx, request.model_dump())).output

    @mcp.tool(name="search_knowledge", description="Passages of the knowledge library (any language).")
    async def search_knowledge(question: str) -> list[dict[str, Any]]:
        return [chunk.model_dump() for chunk in await ctx.retrieval.knowledge(question, ctx.today())]

    @mcp.tool(name="summarize_notes", description="Summary of a client's, an owner's or the tasks' notes.")
    async def summarize_notes(request: SummarizeInput) -> dict[str, Any]:
        return (await text.summarize(ctx, request.model_dump())).output

    @mcp.tool(name="clean_listing_text", description="Suggested cleaned description of a listing.")
    async def clean_listing_text(request: CleanListingInput) -> dict[str, Any]:
        return (await text.clean_listing(ctx, request.model_dump())).output

    @mcp.tool(name="summarize_batch", description="Map/reduce summary of many masked texts (SuperAgent).")
    async def summarize_batch(request: BatchInput) -> dict[str, Any]:
        return (await reports.summarize_batch(ctx, request.model_dump())).output

    return mcp
