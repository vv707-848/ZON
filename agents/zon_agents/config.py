"""Service settings, read from the environment (and `.env`). See `.env.example` for each variable."""

from pathlib import Path
from typing import Literal

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # HTTP server — bound to the loopback: only Laravel on the same node calls it.
    host: str = "127.0.0.1"
    port: int = 8100
    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"

    # Shared with Laravel (same variable names as backend/.env); production values: `openssl rand -hex 32`.
    agents_internal_key: SecretStr = Field(min_length=1)
    index_shared_secret: SecretStr = Field(min_length=1)
    index_max_skew_seconds: int = 300
    index_state_path: Path = Path("var/index_state.sqlite3")

    # LLM: the AI node's OpenAI-compatible server (SGLang or vLLM) reached through the SSH tunnel. The profile is the
    # model it serves (zon_agents/models/<profile>.toml); "zon-ai" is the name it serves it under, whatever the model.
    llm_base_url: str = "http://127.0.0.1:30000/v1"
    llm_api_key: SecretStr
    llm_model: str = "zon-ai"
    llm_profile: str = "qwen3.6-35b-a3b-escha-w2"
    llm_max_concurrency: int = Field(default=8, ge=1)  # = MAXREQ of the GPU node (architecture §7.5)
    # Budget of one agent call: the model step gives up at it, the whole call 2 s later; Laravel cuts at 20 s.
    request_budget_seconds: float = Field(default=15.0, gt=0, le=17)

    # Embedding server (bge-m3) reached through the tunnel.
    embed_url: str = "http://127.0.0.1:18080"
    embed_timeout_seconds: float = 5.0
    embed_max_tokens_per_call: int = 1024  # bounds the ColBERT payload of one /embed answer

    # Qdrant (data node, private address).
    qdrant_url: str = "http://127.0.0.1:6333"
    qdrant_api_key: SecretStr | None = None
    qdrant_timeout_seconds: int = 5
    colbert_enabled: bool = True  # may be turned off under 50k listings (re-bootstrap + re-index to change)
    turbo_bits: Literal["bits1", "bits1_5", "bits2", "bits4"] = "bits4"
    hybrid_prefetch_limit: int = Field(default=100, ge=10, le=300)

    # Knowledge retrieval (chat).
    knowledge_top_k: int = 5
    knowledge_min_score: float = Field(default=0.45, ge=0, le=1)

    health_cache_seconds: float = 5.0

    # Error tracking (zon_agents/monitoring.py): an empty DSN sends nothing. The release is the deployed commit.
    sentry_dsn: SecretStr | None = None
    sentry_environment: str = "production"
    sentry_release: str | None = None
    sentry_traces_sample_rate: float = Field(default=0.0, ge=0, le=1)
    phash_max_bytes: int = 20 * 1024 * 1024
