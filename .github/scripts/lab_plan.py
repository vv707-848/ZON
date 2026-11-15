"""Before a hosted lab run (.github/workflows/lab.yml): what the key allows and what the model supports.

Reads the host's answers to GET /key and GET /models (OpenRouter's shapes), prints them for the log — never the
key itself — and writes the run's plan as step outputs:

- the model must be listed and must take a JSON schema (`structured_outputs` or `response_format`): the service
  asks for nothing else, so a model without it would only measure failures;
- a key on the free tier has a small number of requests a day: the run is then the base cases of as many agents
  as fit under it (smallest first), not the whole set cut off halfway. Asked-for agents are taken as asked.
"""

import json
import sys
from pathlib import Path

from zon_agents.lab.cases import load_cases

FREE_DAILY = 50  # requests a day on a ":free" model without purchased credits (OpenRouter's limits page)
KEPT_BACK = 8  # a second try of a malformed answer is a request too


def main(key_file: str, models_file: str, model: str, asked: str) -> int:
    key = json.loads(Path(key_file).read_text())["data"]
    listed = {m["id"]: m for m in json.loads(Path(models_file).read_text())["data"]}
    free_tier = bool(key.get("is_free_tier"))
    print(
        f"key: free tier {free_tier} · limit {key.get('limit')} · remaining {key.get('limit_remaining')} · "
        f"usage {key.get('usage')}",
        file=sys.stderr,
    )
    entry = listed.get(model)
    if entry is None:
        near = sorted(i for i in listed if i.split("/")[0] == model.split("/")[0] and i.endswith(":free"))
        print(f"::error::{model} is not listed by the host. Free models of the same maker: {', '.join(near) or 'none'}")
        return 1
    supported = set(entry.get("supported_parameters") or [])
    print(
        f"model: {model} · context {entry.get('context_length')} · reasoning {entry.get('reasoning')} · "
        f"parameters {sorted(supported)}",
        file=sys.stderr,
    )
    if not supported & {"structured_outputs", "response_format"}:
        print(f"::error::{model} does not take a JSON schema at this host: the lab would measure only failures.")
        return 1

    args, note = "", "every case and its variants"
    if asked:
        args, note = f"--agents {asked}", f"agents {asked}"
    elif free_tier and model.endswith(":free"):
        sizes: dict[str, int] = {}
        for case in load_cases():
            sizes[case.agent] = sizes.get(case.agent, 0) + 1
        chosen, total = [], 0
        for agent, size in sorted(sizes.items(), key=lambda item: (item[1], item[0])):
            if total + size <= FREE_DAILY - KEPT_BACK:
                chosen.append(agent)
                total += size
        args = f"--agents {','.join(chosen)} --no-variants"
        note = f"free tier ({FREE_DAILY} requests a day): {total} base cases of {', '.join(chosen)}"
    print(f"plan: {note}", file=sys.stderr)
    print(f"args={args}")
    print(f"note={note}")
    return 0


if __name__ == "__main__":
    sys.exit(main(*sys.argv[1:5]))
