"""The thirteen agents, by the name Laravel calls (`POST /agents/{agent}` · App\\Enums\\AiAgent)."""

from zon_agents.pipelines import chat, ranking, reports, text
from zon_agents.pipelines.base import Pipeline

PIPELINES: dict[str, Pipeline] = {
    "search_listings": ranking.search_listings,
    "search_requests": ranking.search_requests,
    "matching": ranking.matching,
    "match_searchers": ranking.match_searchers,
    "chat": chat.chat,
    "summarize": text.summarize,
    "clean_listing": text.clean_listing,
    "clean_project": text.clean_project,
    "clean_units": text.clean_units,
    "report_select": reports.report_select,
    "report_explore": reports.report_explore,
    "report_write": reports.report_write,
    "summarize_batch": reports.summarize_batch,
}
