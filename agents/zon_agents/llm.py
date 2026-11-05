"""PydanticAI → the AI node's OpenAI-compatible server (`zon-ai`) — the only place the model is called.

- **Output:** NativeOutput for every agent: a JSON schema constrains generation inside the engine, the same way on
  SGLang and vLLM and for every model family (no tool-call parser to match per model). The `zon-ai` name needs an
  explicit profile, or PydanticAI refuses NativeOutput.
- **Parameters:** the active model profile (`profiles.py`): the sampling of each use and the request extras.
- **Failure policy:**
  - an output that breaks the schema is retried ONCE at temperature 0.1;
  - a timeout is never retried;
  - eight calls at most reach the GPU at once (MAXREQ) — the rest wait here;
  - one budget bounds both attempts.
"""

import asyncio
import json
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import httpx
from openai import AsyncOpenAI
from pydantic import BaseModel
from pydantic_ai import Agent, NativeOutput
from pydantic_ai.exceptions import ModelAPIError, UnexpectedModelBehavior
from pydantic_ai.models import Model
from pydantic_ai.models.openai import OpenAIChatModel
from pydantic_ai.profiles import InlineDefsJsonSchemaTransformer
from pydantic_ai.profiles.openai import OpenAIModelProfile
from pydantic_ai.providers.openai import OpenAIProvider

from zon_agents.errors import DependencyUnavailable
from zon_agents.profiles import ModelProfile, Use

PROMPTS_DIR = Path(__file__).parent / "prompts"

RETRY_TEMPERATURE = 0.1


class ModelFailed(Exception):
    """The model did not give a usable answer: unreachable, too slow, or twice outside the schema."""

    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason  # unavailable · timeout · invalid


@dataclass(frozen=True)
class Prompt:
    name: str
    version: str
    text: str


@dataclass(frozen=True)
class LlmResult[T]:
    output: T
    tokens_in: int
    tokens_out: int


def load_prompts(directory: Path = PROMPTS_DIR) -> dict[str, Prompt]:
    """One Arabic prompt per agent, `prompts/<agent>.md`, whose first line is `version: N` (reviewed like code)."""
    prompts: dict[str, Prompt] = {}
    for path in sorted(directory.glob("*.md")):
        head, _, body = path.read_text(encoding="utf-8").partition("\n")
        if not head.startswith("version:"):
            raise RuntimeError(f"{path.name}: the first line must be 'version: N'")
        prompts[path.stem] = Prompt(path.stem, head.removeprefix("version:").strip(), body.strip())
    return prompts


def openai_model(base_url: str, api_key: str, name: str, timeout: float) -> OpenAIChatModel:
    """The engine's OpenAI-compatible endpoint; `name` is the name it serves the model under (zon-ai)."""
    client = AsyncOpenAI(
        base_url=base_url,
        api_key=api_key,
        max_retries=0,  # no retry after a timeout: under load a retry doubles the load
        timeout=timeout,
    )
    # PydanticAI's own «profile» of the endpoint (what it supports), not a Zon model profile.
    capabilities = OpenAIModelProfile(
        supports_json_schema_output=True, json_schema_transformer=InlineDefsJsonSchemaTransformer
    )
    return OpenAIChatModel(name, provider=OpenAIProvider(openai_client=client), profile=capabilities)


class LLM:
    def __init__(
        self,
        model: Model,
        prompts: dict[str, Prompt],
        *,
        profile: ModelProfile,
        max_concurrency: int,
        budget_seconds: float,
        health_url: str | None = None,
        api_key: str = "",
    ) -> None:
        self._model = model
        self.prompts = prompts
        self.profile = profile
        self._slots = asyncio.Semaphore(max_concurrency)
        self._budget = budget_seconds
        self._health_url = health_url
        self._api_key = api_key

    async def run[T: BaseModel](
        self, agent: str, payload: BaseModel | dict[str, Any], output: type[T], use: Use
    ) -> LlmResult[T]:
        prompt = self.prompts[agent]
        runner = Agent(self._model, output_type=NativeOutput(output), instructions=prompt.text, retries=0)
        message = (
            payload.model_dump_json() if isinstance(payload, BaseModel) else json.dumps(payload, ensure_ascii=False)
        )
        settings = self.profile.settings(use)
        deadline = time.monotonic() + self._budget
        async with self._slots:
            for attempt in (1, 2):
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise ModelFailed("timeout")
                try:
                    async with asyncio.timeout(remaining):
                        result = await runner.run(message, model_settings=settings)
                except UnexpectedModelBehavior as exc:
                    if attempt == 2:
                        raise ModelFailed("invalid") from exc
                    settings = self.profile.settings(use, temperature=RETRY_TEMPERATURE)
                    continue
                except TimeoutError as exc:
                    raise ModelFailed("timeout") from exc
                except (ModelAPIError, httpx.HTTPError, ConnectionError) as exc:
                    raise ModelFailed("unavailable") from exc
                usage = result.usage
                return LlmResult(result.output, usage.input_tokens or 0, usage.output_tokens or 0)
        raise ModelFailed("invalid")  # pragma: no cover — the loop always returns or raises

    async def healthy(self) -> bool:
        if self._health_url is None:
            return True
        async with httpx.AsyncClient(timeout=2.0) as client:
            response = await client.get(self._health_url, headers={"Authorization": f"Bearer {self._api_key}"})
        return response.status_code == 200


def model_unavailable(exc: ModelFailed) -> DependencyUnavailable:
    """For agents that have no degraded answer (cleaning, summaries, chat, reports): Laravel takes its fallback."""
    return DependencyUnavailable(f"llm ({exc.reason})")
