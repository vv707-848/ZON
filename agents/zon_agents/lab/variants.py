"""What a good model must not care about, made into cases of its own — the right answer stays the same.

- `reversed` (ranking agents): the candidates in the opposite order. A model that prefers whatever comes first is
  ranking positions, not offers; `stability` in the report is how much of its top three survives.
- `digits` (cleaning, summaries): Arabic-Indic digits instead of Western ones, as many phones type them.
"""

from typing import Any

from zon_agents.lab.cases import Case

_INDIC = str.maketrans({str(d): chr(0x0660 + d) for d in range(10)})
_LISTS = {
    "search_listings": ("candidates",),
    "search_requests": ("candidates",),
    "matching": ("matched", "broaden", "units"),
    "match_searchers": ("searchers",),
}


def _reversed(case: Case) -> Case:
    data: dict[str, Any] = dict(case.input)
    for name in _LISTS[case.agent]:
        if name in data:
            data[name] = list(reversed(data[name]))
    return case.model_copy(update={"input": data})


def _digits(case: Case) -> Case:
    data: dict[str, Any] = dict(case.input)
    if case.agent == "clean_listing":
        data["text"] = data["text"].translate(_INDIC)
    else:
        data["notes"] = [{**note, "text": note["text"].translate(_INDIC)} for note in data["notes"]]
    return case.model_copy(update={"input": data})


def variants(case: Case) -> list[tuple[str, Case]]:
    if case.agent in _LISTS:
        return [("reversed", _reversed(case))]
    if case.agent in ("clean_listing", "summarize"):
        return [("digits", _digits(case))]
    return []
