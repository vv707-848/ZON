"""The service's dependencies, built once from the settings (and replaced by fakes in the tests)."""

from dataclasses import dataclass

from qdrant_client import AsyncQdrantClient

from zon_agents.config import Settings
from zon_agents.embeddings import Embedder, HttpEmbedder
from zon_agents.indexing import VersionStore
from zon_agents.llm import LLM, load_prompts, openai_model
from zon_agents.phash import Hasher, build_hasher
from zon_agents.profiles import load_profile
from zon_agents.store import VectorStore


@dataclass
class Services:
    llm: LLM
    embedder: Embedder
    store: VectorStore
    versions: VersionStore
    hasher: Hasher | None

    async def aclose(self) -> None:
        await self.embedder.aclose()
        await self.store.aclose()
        self.versions.close()


def build_store(settings: Settings) -> VectorStore:
    key = settings.qdrant_api_key.get_secret_value() if settings.qdrant_api_key else None
    client = AsyncQdrantClient(url=settings.qdrant_url, api_key=key, timeout=settings.qdrant_timeout_seconds)
    return VectorStore(
        client,
        colbert=settings.colbert_enabled,
        turbo_bits=settings.turbo_bits,
        prefetch_limit=settings.hybrid_prefetch_limit,
    )


def build_services(settings: Settings) -> Services:
    key = settings.llm_api_key.get_secret_value()
    llm = LLM(
        openai_model(settings.llm_base_url, key, settings.llm_model, settings.request_budget_seconds),
        load_prompts(),
        profile=load_profile(settings.llm_profile),
        max_concurrency=settings.llm_max_concurrency,
        budget_seconds=settings.request_budget_seconds,
        health_url=settings.llm_base_url.rstrip("/") + "/models",
        api_key=key,
    )
    embedder = HttpEmbedder(settings.embed_url, settings.embed_timeout_seconds, settings.embed_max_tokens_per_call)
    return Services(llm, embedder, build_store(settings), VersionStore(settings.index_state_path), build_hasher())
