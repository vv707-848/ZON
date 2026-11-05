"""Model profiles: the model is a setting, not code (`models/<name>.toml`, one file per build of a model).

A profile says which weights the AI node serves and how (`runtime`, `serve`), and how every call to it is made:
the sampling of each use (`json` · `summary` · `report` · `chat`) and what goes with every request (`request`, the
OpenAI `extra_body`: top_k, the switch that turns thinking off). The service calls the one named by `LLM_PROFILE`;
the model lab measures any of them on the same cases, so changing the model is changing that name.
"""

import tomllib
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator
from pydantic_ai.settings import ModelSettings

PROFILES_DIR = Path(__file__).parent / "models"

Use = Literal["json", "summary", "report", "chat"]
USES: tuple[Use, ...] = ("json", "summary", "report", "chat")


class Sampling(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    temperature: float = Field(gt=0, le=2)  # never greedy: the model cards of both families advise against it
    top_p: float = Field(gt=0, le=1)
    max_tokens: int = Field(ge=64, le=8192)  # includes the thinking tokens of a reasoning model
    presence_penalty: float | None = Field(default=None, ge=-2, le=2)


class Serve(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    args: list[str] = []  # engine flags after the model path (deploy/ai-node/serve.py adds host, port, key, name)
    env: dict[str, str] = {}  # for the provider's own script (runtime "escha")


class ModelProfile(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    name: str = Field(pattern=r"^[a-z0-9][a-z0-9.\-]{2,63}$")
    weights: str = Field(pattern=r"^[\w.\-]+/[\w.\-]+$")  # Hugging Face repository
    runtime: Literal["sglang", "vllm", "escha"]
    licence: str
    sampling: dict[Use, Sampling]
    request: dict[str, Any] = {}
    serve: Serve = Serve()

    @field_validator("sampling")
    @classmethod
    def _every_use(cls, sampling: dict[Use, Sampling]) -> dict[Use, Sampling]:
        missing = [use for use in USES if use not in sampling]
        if missing:
            raise ValueError(f"sampling is missing {', '.join(missing)}")
        return sampling

    def settings(self, use: Use, *, temperature: float | None = None) -> ModelSettings:
        """The settings of one call: the use's sampling, the request extras, and a temperature for a retry."""
        sampling = self.sampling[use]
        settings = ModelSettings(
            temperature=sampling.temperature if temperature is None else temperature,
            top_p=sampling.top_p,
            max_tokens=sampling.max_tokens,
            extra_body=dict(self.request),
        )
        if sampling.presence_penalty is not None:
            settings["presence_penalty"] = sampling.presence_penalty
        return settings


def load_profiles(directory: Path = PROFILES_DIR) -> dict[str, ModelProfile]:
    profiles: dict[str, ModelProfile] = {}
    for path in sorted(directory.glob("*.toml")):
        profile = ModelProfile.model_validate(tomllib.loads(path.read_text(encoding="utf-8")))
        if profile.name != path.stem:
            raise RuntimeError(f"{path.name}: the file is named after its profile ({profile.name}.toml)")
        profiles[profile.name] = profile
    return profiles


def load_profile(name: str, directory: Path = PROFILES_DIR) -> ModelProfile:
    profiles = load_profiles(directory)
    if name not in profiles:
        raise RuntimeError(f"unknown model profile {name!r}: one of {', '.join(profiles)}")
    return profiles[name]
