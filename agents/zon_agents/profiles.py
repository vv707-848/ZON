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

Use = Literal["json", "summary", "report", "chat", "rank"]
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
    env: dict[str, str] = {}  # for the provider's own script (runtimes "escha" and "escha-moe")


class ModelProfile(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    name: str = Field(pattern=r"^[a-z0-9][a-z0-9.\-]{2,63}$")
    weights: str = Field(pattern=r"^[\w.\-]+/[\w.\-]+$")  # Hugging Face repository
    # "escha" · "escha-moe": EschaLabs' runtimes for dense and MoE models (escha-runtime-qwen3dense · -qwen3moe), each
    # in its own environment. "sim": no weights, the simulation (zon_agents/sim) · "hosted": the same weights served by
    # someone else (`hosted_by`), for the lab only — never a file of `models/`, never what production calls.
    runtime: Literal["sglang", "vllm", "escha", "escha-moe", "sim", "hosted"]
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

    @property
    def simulated(self) -> bool:
        """Not a model: the deterministic simulation. Its answers are never a model's (Laravel refuses them in
        production unless PRODUCTION_ALLOWS_FAKE names `ai`)."""
        return self.runtime == "sim"

    def settings(self, use: Use, *, temperature: float | None = None) -> ModelSettings:
        """The settings of one call: the use's sampling, the request extras, and a temperature for a retry."""
        # a ranking is the json sampling at temperature 0: the same candidates, the same answer — what the lab's
        # `stability` reads is then the order they came in, not the sample drawn (#119)
        sampling = self.sampling["json" if use == "rank" else use]
        fixed = 0.0 if use == "rank" else sampling.temperature
        settings = ModelSettings(
            temperature=fixed if temperature is None else temperature,
            top_p=sampling.top_p,
            max_tokens=sampling.max_tokens,
            extra_body=dict(self.request),
        )
        if sampling.presence_penalty is not None:
            settings["presence_penalty"] = sampling.presence_penalty
        return settings

    def hosted_by(self, host: str, request: dict[str, Any]) -> "ModelProfile":
        """This model as [host] serves it, for a lab run: the same sampling, the host's own request extras (ours
        are the engine's: a chat template switch means nothing to a host), nothing to serve. What it measures is
        the model as that host runs it — its quantisation and engine are the host's, not this profile's."""
        slug = "".join(ch for ch in host.lower() if ch.isalnum())
        return ModelProfile(
            name=f"{self.name}.{slug}"[:64],
            weights=self.weights,
            runtime="hosted",
            licence=self.licence,
            sampling=self.sampling,
            request=request,
        )


def load_profiles(directory: Path = PROFILES_DIR) -> dict[str, ModelProfile]:
    profiles: dict[str, ModelProfile] = {}
    for path in sorted(directory.glob("*.toml")):
        profile = ModelProfile.model_validate(tomllib.loads(path.read_text(encoding="utf-8")))
        if profile.name != path.stem:
            raise RuntimeError(f"{path.name}: the file is named after its profile ({profile.name}.toml)")
        profiles[profile.name] = profile
    return profiles


# The simulation's profile is built here, not a file of `models/`: that folder is copied to the AI node, where
# there is nothing to serve for it.
SIMULATED = ModelProfile(
    name="sim-rules",
    weights="zon/sim-rules",
    runtime="sim",
    licence="none (rules, no weights)",
    sampling={use: Sampling(temperature=0.1, top_p=1.0, max_tokens=4096) for use in USES},
)


def load_profile(name: str, directory: Path = PROFILES_DIR) -> ModelProfile:
    if name == SIMULATED.name:
        return SIMULATED
    profiles = load_profiles(directory)
    if name not in profiles:
        raise RuntimeError(f"unknown model profile {name!r}: one of {', '.join(profiles)}")
    return profiles[name]
