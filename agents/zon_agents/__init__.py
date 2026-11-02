"""Zon agents service — the AI party of the platform (Laravel ↔ agents ↔ SGLang / bge-m3 / Qdrant)."""

import os

# PydanticAI prints a welcome banner on its first run; the service's output is one JSON line per event.
os.environ.setdefault("PYDANTIC_AI_NO_BANNER", "1")

__version__ = "1.0.0"
