"""The bge-m3 client (zon_agents/embeddings.py): batches bounded by tokens, a long text alone, and any fault of the
server is DependencyUnavailable, so the caller answers with its fallback."""

import json
from collections.abc import Callable

import httpx
import pytest

from zon_agents.embeddings import HttpEmbedder
from zon_agents.errors import DependencyUnavailable

Answer = Callable[[list[str]], httpx.Response]


def embed_server(answer: Answer) -> tuple[httpx.MockTransport, list[list[str]]]:
    """A stand-in for deploy/ai-node/embed: each call's texts are kept, and `answer` decides the response."""
    calls: list[list[str]] = []

    def handle(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/health":
            return httpx.Response(200, json={"status": "ok"})
        texts = json.loads(request.content)["texts"]
        calls.append(texts)
        return answer(texts)

    return httpx.MockTransport(handle), calls


def rows(texts: list[str]) -> httpx.Response:
    return httpx.Response(
        200,
        json=[
            {"dense": [0.5] * 4, "sparse": {"indices": [i], "values": [1.0]}, "colbert": [[0.1, 0.2]]}
            for i in range(len(texts))
        ],
    )


async def test_texts_go_in_batches_bounded_by_tokens_and_a_long_one_goes_alone() -> None:
    transport, calls = embed_server(rows)
    embedder = HttpEmbedder("http://embed.test", 5.0, 10, transport=transport)
    short, long = "شقة" * 3, "ف" * 60  # about 3 and 20 tokens: three shorts fill a call, the long one is over it alone

    out = await embedder.embed([short, short, short, long, short])

    assert [len(texts) for texts in calls] == [3, 1, 1]
    assert len(out) == 5
    assert (out[3].sparse_indices, out[3].sparse_values, out[0].colbert) == ([0], [1.0], [[0.1, 0.2]])
    assert await embedder.healthy()
    await embedder.aclose()


def refused(texts: list[str]) -> httpx.Response:
    raise httpx.ConnectError("connection refused")


@pytest.mark.parametrize(
    "answer",
    [
        refused,
        lambda texts: httpx.Response(503, json={"detail": "loading the model"}),
        lambda texts: httpx.Response(200, content=b"<html>proxy error</html>"),
        lambda texts: httpx.Response(200, json=[]),  # fewer rows than texts
    ],
    ids=["no connection", "server error", "not json", "rows missing"],
)
async def test_any_fault_of_the_embedding_server_is_dependency_unavailable(answer: Answer) -> None:
    transport, _ = embed_server(answer)
    embedder = HttpEmbedder("http://embed.test", 5.0, 100, transport=transport)

    with pytest.raises(DependencyUnavailable) as caught:
        await embedder.embed(["شقة للبيع في النرجس"])

    assert caught.value.dependency == "embed"
    await embedder.aclose()
