#!/usr/bin/env python3
"""/workspace/serve.py — starts the model of one profile on the AI node, whatever its engine.

The profiles are the agents service's own files (agents/zon_agents/models/*.toml), copied to /workspace/profiles:
the model the service calls and the model the node serves are one file. The engine always serves it as `zon-ai`
on 127.0.0.1:30000 with the key in LLM_API_KEY; the profile adds only the model's own flags.

    python3 /workspace/serve.py <profile>             # exec the engine (entrypoint.sh, step ③)
    python3 /workspace/serve.py <profile> --print     # the command, without running it
    python3 /workspace/serve.py <profile> --weights   # «<repository> <folder>» for install.sh's download

Standard library only: it runs before any environment is chosen. Python 3.11+ (tomllib).
"""

import argparse
import os
import shlex
import sys
import tomllib
from pathlib import Path

WORKSPACE = Path(os.environ.get("ZON_WORKSPACE", "/workspace"))
SERVED_NAME = "zon-ai"
HOST, PORT = "127.0.0.1", "30000"
# The engine's own flags for what this script sets — a profile may not set them again.
RESERVED = {"--host", "--port", "--api-key", "--served-model-name", "--model-path", "--model"}


def weights_dir(profile: dict) -> Path:
    """One folder per repository: two profiles of the same weights (two engines) share one download."""
    return WORKSPACE / "models" / profile["weights"].split("/", 1)[1]


def command(profile: dict, key: str) -> tuple[list[str], dict[str, str]]:
    """The program and arguments to exec, and the environment to add."""
    name, runtime = profile["name"], profile["runtime"]
    model = str(weights_dir(profile))
    serve = profile.get("serve", {})
    args = [str(a) for a in serve.get("args", [])]
    clash = RESERVED.intersection(a.split("=", 1)[0] for a in args)
    if clash:
        raise SystemExit(f"{name}: the profile may not set {', '.join(sorted(clash))}")
    venv = WORKSPACE / f"venv-{runtime}"
    if runtime == "sglang":
        argv = [
            str(venv / "bin/python"),
            "-m",
            "sglang.launch_server",
            "--model-path",
            model,
            "--served-model-name",
            SERVED_NAME,
            "--host",
            HOST,
            "--port",
            PORT,
            "--api-key",
            key,
            *args,
        ]
        return argv, {}
    if runtime == "vllm":
        argv = [
            str(venv / "bin/vllm"),
            "serve",
            model,
            "--served-model-name",
            SERVED_NAME,
            "--host",
            HOST,
            "--port",
            PORT,
            "--api-key",
            key,
            *args,
        ]
        return argv, {}
    if runtime == "escha":
        # The provider's script (escha-runtime-qwen3moe/sglang/serve.sh) reads only variables.
        env = {
            "VENV": str(venv),
            "MODEL": model,
            "SERVED_NAME": SERVED_NAME,
            "HOST": HOST,
            "PORT": PORT,
            "API_KEY": key,
            "TRITON_CACHE_DIR": str(WORKSPACE / "cache/triton"),
            **serve.get("env", {}),
        }
        return ["bash", str(WORKSPACE / "escha/sglang/serve.sh")], env
    raise SystemExit(f"{name}: unknown runtime {runtime!r}")


def main() -> None:
    parser = argparse.ArgumentParser(prog="serve.py")
    parser.add_argument("profile")
    parser.add_argument("--print", action="store_true", help="print the command instead of running it")
    parser.add_argument("--weights", action="store_true", help="print the repository and its folder")
    parser.add_argument("--profiles", type=Path, default=WORKSPACE / "profiles")
    opts = parser.parse_args()

    path = opts.profiles / f"{opts.profile}.toml"
    if not path.is_file():
        known = ", ".join(sorted(p.stem for p in opts.profiles.glob("*.toml"))) or "none"
        raise SystemExit(f"no profile {opts.profile!r} in {opts.profiles} (known: {known})")
    profile = tomllib.loads(path.read_text(encoding="utf-8"))
    if opts.weights:
        print(profile["weights"], weights_dir(profile))
        return
    key = os.environ.get("LLM_API_KEY", "")
    if not key and not opts.print:
        raise SystemExit("LLM_API_KEY is not set (Pod settings)")

    argv, env = command(profile, "<LLM_API_KEY>" if opts.print else key)  # a printed command never shows the key
    if opts.print:
        print(" ".join([*(f"{k}={shlex.quote(v)}" for k, v in env.items()), shlex.join(argv)]))
        return
    if not weights_dir(profile).is_dir():
        raise SystemExit(f"weights missing: {weights_dir(profile)} (install.sh, step ②)")
    sys.stdout.flush()
    os.execvpe(argv[0], argv, {**os.environ, **env})


if __name__ == "__main__":
    main()
