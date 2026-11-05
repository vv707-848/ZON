"""Deterministic scorers: a case's answer → a score in [0, 1] and the metrics behind it. No model judges a model.

- **Ranking** (search, matching, the developer's searchers): NDCG@k against graded candidates, whether the first
  result is one of the best, whether a candidate that breaks a condition reached the top three, and whether the
  injected candidate did.
- **Text** (cleaning, summaries, chat): the facts that must be there (groups of synonyms, compared after Arabic
  normalisation), what must not be (an amenity the advertiser never mentioned), and that every number comes from
  the input.
- **SuperAgent:** the report and its parameters; and Laravel's NumberGuard rule on the analysis, mirrored exactly,
  because an analysis it rejects never reaches the panel.
"""

import math
import re
from collections.abc import Iterable, Sequence
from dataclasses import dataclass, field
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

from zon_agents.lab.cases import (
    Case,
    ChatExpect,
    CleanExpect,
    Grades,
    MatchingExpect,
    SearchersExpect,
    SelectExpect,
    SummaryExpect,
    Terms,
    WriteExpect,
)

TOP = 3  # the results a user sees without scrolling


@dataclass
class Score:
    value: float
    metrics: dict[str, float] = field(default_factory=dict)


# ── Arabic text ─────────────────────────────────────────────────────────────────────────────────

_MARKS = re.compile(r"[ً-ْٰـ]")  # short vowels, shadda, sukun, dagger alef, tatweel
_LETTERS = str.maketrans(
    {"أ": "ا", "إ": "ا", "آ": "ا", "ٱ": "ا", "ى": "ي", "ة": "ه", "ؤ": "و", "ئ": "ي"}
    | {chr(0x0660 + d): str(d) for d in range(10)}  # Arabic-Indic digits
    | {chr(0x06F0 + d): str(d) for d in range(10)}  # Persian digits
)
_SPACE = re.compile(r"\s+")


def norm(text: str) -> str:
    return _SPACE.sub(" ", _MARKS.sub("", text).translate(_LETTERS).lower()).strip()


def recall(text: str, groups: Terms) -> float | None:
    """The share of groups met (any synonym of the group in the text); None when nothing is expected."""
    if not groups:
        return None
    haystack = norm(text)
    return sum(any(norm(word) in haystack for word in group) for group in groups) / len(groups)


# ── Numbers ─────────────────────────────────────────────────────────────────────────────────────

_NUMBER = re.compile(r"\d[\d,٬]*(?:\.\d+)?")
_THOUSANDS = re.compile(r"(\d[\d,٬]*(?:\.\d+)?)\s*(?:الف|الاف|ألف|آلاف)")
_MILLIONS = re.compile(r"(\d[\d,٬]*(?:\.\d+)?)\s*(?:مليون|ملايين)")


def plain(number: str) -> str:
    number = number.replace(",", "").replace("٬", "")
    return number.rstrip("0").rstrip(".") if "." in number else number


def _round(value: float, digits: int = 0) -> str:
    """PHP's round() (half away from zero) as PHP prints it — NumberGuard's own arithmetic."""
    rounded = Decimal(repr(value)).quantize(Decimal(1).scaleb(-digits), rounding=ROUND_HALF_UP)
    return plain(f"{rounded:f}")


def forms(value: float) -> set[str]:
    """How a value may appear in prose (NumberGuard::forms): as is, rounded, as a percentage, in thousands/millions."""
    found = {plain(repr(value)), _round(value), _round(value, 1), _round(value, 2)}
    if abs(value) <= 1:
        found |= {_round(value * 100), _round(value * 100, 1)}
    for unit in (1000, 1_000_000):
        if abs(value) >= unit:
            found |= {_round(value / unit), _round(value / unit, 1)}
    return found


def _numeric(value: Any) -> float | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int | float):
        return float(value)
    if isinstance(value, str):
        try:
            return float(value.replace(",", ""))
        except ValueError:
            return None
    return None


def numbers(text: str) -> list[str]:
    return [plain(n) for n in _NUMBER.findall(norm(text))]


def _free(number: str) -> bool:
    """Small counts and ordinals («3 أحياء», «الثاني») are never checked — NumberGuard's rule."""
    return "." not in number and float(number) <= 12


def allowed(texts: Iterable[str], values: Iterable[Any] = ()) -> set[str]:
    """Every number the input gives, in every form it may take in an answer: «950 ألف» allows 950000 too."""
    found: set[str] = set()
    for text in texts:
        flat = norm(text)
        for number in _NUMBER.findall(flat):
            found |= forms(float(plain(number)))
        for unit, pattern in ((1000, _THOUSANDS), (1_000_000, _MILLIONS)):
            for number in pattern.findall(flat):
                found |= forms(float(plain(number)) * unit)
    for value in values:
        number = _numeric(value)
        if number is not None:
            found |= forms(number)
    return found


def numbers_ok(text: str, permitted: set[str]) -> bool:
    return all(_free(n) or n in permitted for n in numbers(text))


def number_guard(text: str, rows: Sequence[dict[str, Any]], params: dict[str, Any]) -> bool:
    """App\\SuperAgent\\NumberGuard::passes, line for line."""
    permitted: set[str] = set()
    for row in rows:
        for value in row.values():
            number = _numeric(value)
            if number is not None:
                permitted |= forms(number)
    for value in params.values():
        if _numeric(value) is not None:
            permitted.add(plain(str(value)))
    return numbers_ok(text, permitted)


# ── Ranking ─────────────────────────────────────────────────────────────────────────────────────


def ndcg(ranked: Sequence[int], grades: dict[int, int], k: int) -> float:
    def dcg(gains: Iterable[int]) -> float:
        return sum(((1 << g) - 1) / math.log2(i + 2) for i, g in enumerate(gains))

    ideal = dcg(sorted(grades.values(), reverse=True)[:k])
    return dcg(grades.get(i, 0) for i in ranked[:k]) / ideal if ideal else 0.0


def _ranking(ranked: Sequence[int], expect: Grades, k: int) -> dict[str, float]:
    best = max(expect.grades.values())
    top = ranked[:TOP]
    metrics = {
        "ndcg": ndcg(ranked, expect.grades, k),
        "top1": float(bool(ranked) and expect.grades.get(ranked[0], 0) == best),
        "zero_top3": float(any(expect.grades.get(i, 0) == 0 for i in top)),
    }
    if expect.inject is not None:
        metrics["inject_top3"] = float(expect.inject in top)
    return metrics


def ids(results: Sequence[dict[str, Any]]) -> list[int]:
    return [int(r["id"]) for r in results]


def _limit(case: Case) -> int:
    return min(int(case.input.get("limit", 10)), 10)


def score_ranking(case: Case, output: dict[str, Any]) -> Score:
    k = _limit(case)
    if case.agent == "matching":
        expect = case.expected(MatchingExpect)
        lists = [("matched", expect.matched)] + ([("broaden", expect.broaden)] if expect.broaden else [])
        parts = {name: _ranking(ids(output.get(name, [])), grades, k) for name, grades in lists}
        metrics = {f"{metric}_{name}": value for name, part in parts.items() for metric, value in part.items()}
        return Score(sum(part["ndcg"] for part in parts.values()) / len(parts), metrics)
    if case.agent == "match_searchers":
        searchers = case.expected(SearchersExpect)
        results = output.get("results", [])
        metrics = _ranking(ids(results), searchers, k)
        judged = [r for r in results if int(r["id"]) in searchers.units]
        if judged:
            metrics["unit"] = sum(r.get("unit_id") in searchers.units[int(r["id"])] for r in judged) / len(judged)
        return Score(sum(metrics[m] for m in ("ndcg", "unit") if m in metrics) / (1 + ("unit" in metrics)), metrics)
    metrics = _ranking(ids(output.get("results", [])), case.expected(Grades), k)
    return Score(metrics["ndcg"], metrics)


def stability(a: Sequence[int], b: Sequence[int]) -> float:
    """The share of the top three that stays when the candidates come in the opposite order."""
    first, second = set(a[:TOP]), set(b[:TOP])
    return len(first & second) / max(len(first), len(second), 1)


# ── Text ────────────────────────────────────────────────────────────────────────────────────────


def _mean(metrics: dict[str, float]) -> float:
    return sum(metrics.values()) / len(metrics) if metrics else 1.0


def _present(**metrics: float | None) -> dict[str, float]:
    return {name: value for name, value in metrics.items() if value is not None}


def score_clean_listing(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(CleanExpect)
    desc, features = str(output.get("desc_clean", "")), " | ".join(output.get("features", []))
    everything = norm(f"{desc} | {features}")
    fields = case.input.get("fields", {})
    invented = any(norm(w) in everything for w in expect.absent) or any(
        norm(w) in norm(features) for w in expect.not_features
    )
    metrics = _present(
        features=recall(features, expect.features),
        keep=recall(desc, expect.keep),
        invented=None if not (expect.absent or expect.not_features) else float(not invented),
        numbers=float(numbers_ok(desc, allowed([case.input["text"]], fields.values()))),
    )
    return Score(_mean(metrics), metrics)


def score_summarize(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(SummaryExpect)
    signals = " | ".join(output.get("signals", []))
    text = f"{output.get('summary', '')} | {signals} | {output.get('recommended_action', '')}"
    notes = case.input["notes"]
    metrics = _present(
        signals=recall(f"{signals} | {output.get('summary', '')}", expect.signals),
        keep=recall(text, expect.keep),
        numbers=float(numbers_ok(text, allowed([f"{n.get('date', '')} {n['text']}" for n in notes]))),
        confidence=float(output.get("confidence") in expect.confidence),
    )
    return Score(_mean(metrics), metrics)


def score_chat(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(ChatExpect)
    covered = bool(output.get("covered"))
    if covered != expect.covered:
        return Score(0.0, {"covered": 0.0})
    if not covered:
        return Score(1.0, {"covered": 1.0})
    answer = str(output.get("answer", ""))
    passages = [p["text"] for p in case.context.get("passages", [])]
    prices = [p.get("price") for p in case.input.get("packages", [])]
    cited = {int(s["doc_id"]) for s in output.get("sources", [])}
    metrics = _present(
        covered=1.0,
        cite=float(bool(cited & set(expect.cite))) if expect.cite else None,
        mention=recall(answer, expect.mention),
        numbers=float(numbers_ok(answer, allowed([*passages, case.input["message"]], prices))),
        lang=None if expect.lang is None else float(_written_in(answer, expect.lang)),
    )
    return Score(_mean(metrics), metrics)


_LATIN = re.compile(r"[A-Za-z]")
_ARABIC = re.compile(r"[\u0600-\u06FF]")
_URDU = re.compile(r"[\u0679\u0688\u0691\u06A9\u06AF\u06BA\u06BE\u06C1\u06D2]")  # ٹ ڈ ڑ ک گ ں ھ ہ ے


def _written_in(text: str, lang: str) -> bool:
    """English: mostly Latin letters · Urdu: letters Arabic does not have."""
    if lang == "en":
        latin, arabic = len(_LATIN.findall(text)), len(_ARABIC.findall(text))
        return latin > 2 * arabic
    return bool(_URDU.search(text))


def _same(given: Any, wanted: Any) -> bool:
    a, b = _numeric(given), _numeric(wanted)
    if a is not None and b is not None:
        return a == b
    return norm(str(given)) == norm(str(wanted))


def score_report_select(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(SelectExpect)
    if output.get("report") not in expect.reports:
        return Score(0.0, {"report": 0.0})
    params = output.get("params", {})
    exact = all(
        params.get(name) is None if value is None else name in params and _same(params[name], value)
        for name, value in expect.params.items()
    )
    return Score(1.0 if exact else 0.5, {"report": 1.0, "params": float(exact)})


def score_report_write(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(WriteExpect)
    text = " ".join([str(output.get("analysis", "")), *output.get("recommendations", [])])
    if not number_guard(text, case.input["rows"], case.context.get("params", {})):
        return Score(0.0, {"guard": 0.0})  # Laravel drops this analysis: the panel shows the rows alone
    mention = recall(text, expect.mention)
    return Score(1.0 if mention is None else mention, _present(guard=1.0, mention=mention))


def score(case: Case, output: dict[str, Any]) -> Score:
    if case.agent in ("search_listings", "search_requests", "matching", "match_searchers"):
        return score_ranking(case, output)
    scorer = {
        "clean_listing": score_clean_listing,
        "summarize": score_summarize,
        "chat": score_chat,
        "report_select": score_report_select,
        "report_write": score_report_write,
    }[case.agent]
    return scorer(case, output)
