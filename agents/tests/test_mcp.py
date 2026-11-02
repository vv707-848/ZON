"""The MCP server exposes the same tools, behind the same key."""

from fastapi.testclient import TestClient

from tests.conftest import INTERNAL_KEY

HEADERS = {"Accept": "application/json, text/event-stream", "Content-Type": "application/json"}


def test_the_tools_are_listed_for_the_key_holder_only(client: TestClient) -> None:
    listing = {"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}

    refused = client.post("/mcp/", json=listing, headers={**HEADERS, "X-Internal-Key": "wrong"})
    assert refused.status_code == 401

    initialize = {
        "jsonrpc": "2.0",
        "id": 0,
        "method": "initialize",
        "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "test", "version": "1"}},
    }
    assert client.post("/mcp/", json=initialize, headers={**HEADERS, "X-Internal-Key": INTERNAL_KEY}).status_code == 200
    response = client.post("/mcp/", json=listing, headers={**HEADERS, "X-Internal-Key": INTERNAL_KEY})

    names = {tool["name"] for tool in response.json()["result"]["tools"]}
    assert {"semantic_search_listings", "match_to_units", "search_knowledge", "clean_listing_text"} <= names
    assert "run_report" not in names
