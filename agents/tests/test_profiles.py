"""Model profiles: the model is a file — the service calls with its settings, the AI node serves it with its flags."""

import os
import subprocess
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from tests.conftest import ScriptedModel, call
from zon_agents.config import Settings
from zon_agents.profiles import PROFILES_DIR, USES, ModelProfile, load_profile, load_profiles

SERVE = Path(__file__).resolve().parents[2] / "deploy" / "ai-node" / "serve.py"


def test_every_profile_file_loads_with_a_sampling_for_every_use() -> None:
    profiles = load_profiles()

    assert Settings.model_fields["llm_profile"].default in profiles, "the default profile exists"
    assert len(profiles) >= 5
    for profile in profiles.values():
        assert set(profile.sampling) == set(USES), profile.name


def test_a_call_carries_the_profiles_sampling_and_request_extras(client: TestClient, model: ScriptedModel) -> None:
    model.answers["search_listings"] = {"results": [{"id": 7, "score": 0.9}]}

    call(
        client,
        "search_listings",
        {
            "need": {"city": "الرياض", "neighborhood": "النرجس", "property_type": "apartment"},
            "candidates": [{"id": 7, "desc": "شقة"}],
        },
    )

    assert model.settings[0] == {"temperature": 0.3, "top_p": 0.8, "max_tokens": 700, "extra_body": {"top_k": 20}}


def test_a_retry_keeps_the_extras_and_lowers_only_the_temperature() -> None:
    profile = load_profile("qwen3.6-35b-a3b-fp8")

    first, retry = profile.settings("chat"), profile.settings("chat", temperature=0.1)

    assert first["extra_body"] == {"top_k": 20, "chat_template_kwargs": {"enable_thinking": False}}
    assert first.get("presence_penalty") == 1.5
    assert {**first, "temperature": 0.1} == retry


def test_a_profile_without_every_use_or_with_a_greedy_temperature_is_refused(tmp_path: Path) -> None:
    good = load_profile("qwen3.6-35b-a3b-fp8").model_dump()

    with pytest.raises(ValidationError, match="sampling is missing chat"):
        ModelProfile.model_validate({**good, "sampling": {k: v for k, v in good["sampling"].items() if k != "chat"}})
    with pytest.raises(ValidationError):
        ModelProfile.model_validate(
            {**good, "sampling": {**good["sampling"], "json": {**good["sampling"]["json"], "temperature": 0}}}
        )
    (tmp_path / "other-name.toml").write_text((PROFILES_DIR / "qwen3.6-35b-a3b-fp8.toml").read_text())
    with pytest.raises(RuntimeError, match="named after its profile"):
        load_profiles(tmp_path)


@pytest.mark.parametrize("name", sorted(load_profiles()))
def test_the_ai_node_serves_every_profile_as_zon_ai_on_the_loopback(name: str, tmp_path: Path) -> None:
    env = {**os.environ, "ZON_WORKSPACE": str(tmp_path), "LLM_API_KEY": "secret-key"}
    printed = subprocess.run(
        [sys.executable, str(SERVE), name, "--print", "--profiles", str(PROFILES_DIR)],
        env=env,
        capture_output=True,
        text=True,
        check=True,
    ).stdout
    weights = subprocess.run(
        [sys.executable, str(SERVE), name, "--weights", "--profiles", str(PROFILES_DIR)],
        env=env,
        capture_output=True,
        text=True,
        check=True,
    ).stdout.split()

    profile = load_profile(name)
    assert weights == [profile.weights, str(tmp_path / "models" / profile.weights.split("/")[1])]
    assert "secret-key" not in printed, "a printed command never shows the key"
    if profile.runtime == "escha":
        assert "SERVED_NAME=zon-ai HOST=127.0.0.1 PORT=30000" in printed and printed.rstrip().endswith("serve.sh")
    else:
        assert "--served-model-name zon-ai --host 127.0.0.1 --port 30000" in printed
        assert printed.rstrip().endswith(" ".join(profile.serve.args))


def test_a_profile_cannot_move_the_engine_off_the_loopback(tmp_path: Path) -> None:
    text = (PROFILES_DIR / "qwen3.6-35b-a3b-fp8.toml").read_text().replace('args = ["', 'args = ["--host=0.0.0.0", "')
    (tmp_path / "qwen3.6-35b-a3b-fp8.toml").write_text(text)

    done = subprocess.run(
        [sys.executable, str(SERVE), "qwen3.6-35b-a3b-fp8", "--print", "--profiles", str(tmp_path)],
        capture_output=True,
        text=True,
    )

    assert done.returncode != 0 and "may not set --host" in done.stderr
