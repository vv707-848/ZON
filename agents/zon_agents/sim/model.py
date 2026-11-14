"""A function of (agent, payload) as a PydanticAI model: what LLM.run sends is decoded back into the agent it is
for (by its prompt) and the JSON payload, and the function's dict goes back the way a model's answer would."""

import json
from collections.abc import Callable
from typing import Any

from pydantic_ai.messages import ModelMessage, ModelRequest, ModelResponse, TextPart, ToolCallPart, UserPromptPart
from pydantic_ai.models.function import AgentInfo, FunctionModel

from zon_agents.llm import Prompt

Answerer = Callable[[str, dict[str, Any]], dict[str, Any]]


def by_text(prompts: dict[str, Prompt]) -> dict[str, str]:
    """Prompt text → agent: how a call says which agent it is for."""
    return {prompt.text: name for name, prompt in prompts.items()}


def decode(messages: list[ModelMessage], agents: dict[str, str]) -> tuple[str, dict[str, Any]]:
    request = next(m for m in messages if isinstance(m, ModelRequest))
    prompt = next(p for p in request.parts if isinstance(p, UserPromptPart))
    return agents[request.instructions or ""], dict(json.loads(str(prompt.content)))


def encode(answer: dict[str, Any], info: AgentInfo) -> ModelResponse:
    if info.output_tools:
        return ModelResponse(parts=[ToolCallPart(info.output_tools[0].name, answer)])
    return ModelResponse(parts=[TextPart(json.dumps(answer, ensure_ascii=False))])


def function_model(answer: Answerer, prompts: dict[str, Prompt]) -> FunctionModel:
    agents = by_text(prompts)

    async def respond(messages: list[ModelMessage], info: AgentInfo) -> ModelResponse:
        # async: it runs in the caller's own task, so a context variable set there (the lab's case) is readable
        agent, payload = decode(messages, agents)
        return encode(answer(agent, payload), info)

    return FunctionModel(respond)
