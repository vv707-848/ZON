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
from typing import Any

from zon_agents.lab.cases import (
    Case,
    ChatExpect,
    Chosen,
    CleanExpect,
    ExploreExpect,
    Grades,
    MatchingExpect,
    SearchersExpect,
    SelectExpect,
    SummaryExpect,
    Terms,
    WriteExpect,
)
from zon_agents.numbers import (
    _NUMBER,
    _numeric,
    derived_rows,
    forms,
    norm,
    number_guard,
    numbers,
    plain,
)

TOP = 3  # the results a user sees without scrolling


@dataclass
class Score:
    value: float
    metrics: dict[str, float] = field(default_factory=dict)


# ── Arabic text ─────────────────────────────────────────────────────────────────────────────────


def recall(text: str, groups: Terms) -> float | None:
    """The share of groups met (any synonym of the group in the text); None when nothing is expected."""
    if not groups:
        return None
    haystack = norm(text)
    return sum(any(norm(word) in haystack for word in group) for group in groups) / len(groups)


# ── Numbers ─────────────────────────────────────────────────────────────────────────────────────

_THOUSANDS = re.compile(r"(\d[\d,٬]*(?:\.\d+)?)\s*(?:الف|الاف|ألف|آلاف)")
_MILLIONS = re.compile(r"(\d[\d,٬]*(?:\.\d+)?)\s*(?:مليون|ملايين)")
_AND_A_HALF = re.compile(r"(?:(\d[\d,٬]*)\s*)?(الف|ألف|مليون)\s+ونصف?(?!\w)")  # «مليون ونصف» · «3 مليون ونص»


def _free(number: str) -> bool:
    """Small counts and ordinals («3 أحياء», «الثاني») are not checked in the texts the lab scores itself.
    The SuperAgent's NumberGuard checks them too (number_guard)."""
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
        for count, word in _AND_A_HALF.findall(flat):
            found |= forms((float(plain(count or "1")) + 0.5) * (1_000_000 if word == "مليون" else 1000))
    for value in values:
        number = _numeric(value)
        if number is not None:
            found |= forms(number)
    return found


def numbers_ok(text: str, permitted: set[str]) -> bool:
    return all(_free(n) or n in permitted for n in numbers(text))


# ── Ranking ─────────────────────────────────────────────────────────────────────────────────────


def ndcg(ranked: Sequence[int], grades: dict[int, int], k: int) -> float:
    def dcg(gains: Iterable[int]) -> float:
        return sum(((1 << g) - 1) / math.log2(i + 2) for i, g in enumerate(gains))

    ideal = dcg(sorted(grades.values(), reverse=True)[:k])
    return dcg(grades.get(i, 0) for i in ranked[:k]) / ideal if ideal else 0.0


def _ranking(ranked: Sequence[int], expect: Grades, k: int) -> dict[str, float]:
    if expect.empty:  # right is nothing: every measure is whether nothing was shown
        none = float(not ranked)
        return {"ndcg": none, "top1": none, "zero_top3": 1 - none}
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
        avoid=float(not any(norm(term) in norm(text) for term in expect.avoid)) if expect.avoid else None,
    )
    if metrics.get("avoid") == 0.0:  # a condition said the other way round, or an open task said done: wrong whole
        return Score(0.0, metrics)
    return Score(_mean(metrics), metrics)


def score_chat(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(ChatExpect)
    covered = bool(output.get("covered"))
    named = None if expect.intent is None else float(output.get("intent") == expect.intent)
    if covered != expect.covered:
        return Score(0.0, _present(covered=0.0, intent=named))
    answer = str(output.get("answer", ""))
    if not covered:
        said_in = None if expect.lang is None else float(_written_in(answer, expect.lang))
        metrics = _present(covered=1.0, intent=named, lang=said_in)
        return Score(min(metrics.values()), metrics)  # «outside the library» is right only whole: intent and language
    passages = [p["text"] for p in case.context.get("passages", [])]
    packages = case.input.get("packages", [])
    prices = [p.get("price") for p in packages]
    said = [text for p in packages for text in (p.get("name", ""), *p.get("features", []))]  # «توفير 1060 ريالاً»
    cited = {int(s["doc_id"]) for s in output.get("sources", [])}
    metrics = _present(
        covered=1.0,
        cite=float(bool(cited & set(expect.cite))) if expect.cite else None,
        mention=recall(answer, expect.mention),
        numbers=float(numbers_ok(answer, allowed([*passages, *said, case.input["message"]], prices))),
        lang=None if expect.lang is None else float(_written_in(answer, expect.lang)),
        intent=named,
        avoid=float(not any(norm(word) in norm(answer) for word in expect.avoid)) if expect.avoid else None,
        asks=None if expect.asks is None else float(answer.count("؟") + answer.count("?") <= expect.asks),
    )
    if metrics.get("mention") == 0.0:  # none of what it had to say: wrong, whatever else it got right
        return Score(0.0, metrics)
    return Score(_mean(metrics), metrics)


_LATIN = re.compile(r"[A-Za-z]")
_ARABIC = re.compile(r"[\u0600-\u06FF]")
_URDU = re.compile(r"[\u0679\u0688\u0691\u06A9\u06AF\u06BA\u06BE\u06C1\u06D2]")  # ٹ ڈ ڑ ک گ ں ھ ہ ے


_SCRIPTS = {"ru": re.compile(r"[\u0400-\u04FF]"), "hi": re.compile(r"[\u0900-\u097F]")}
# A Latin language by words of its own that English does not use
_WORDS = {
    "fr": {"le", "la", "les", "des", "est", "vous", "votre", "pas", "pour", "avec", "une", "nous", "lors", "de"},
    "tr": {"ve", "bir", "için", "paket", "aylık", "fiyatı", "riyal", "olan", "bu", "ile"},
}


def _written_in(text: str, lang: str) -> bool:
    """English: mostly Latin letters · Urdu: letters Arabic does not have · Russian, Hindi: mostly their script ·
    French, Turkish: Latin, with words of their own."""
    latin, arabic = len(_LATIN.findall(text)), len(_ARABIC.findall(text))
    if lang == "en":
        return latin > 2 * arabic
    if lang in _SCRIPTS:
        return len(_SCRIPTS[lang].findall(text)) > 2 * (latin + arabic)
    if lang in _WORDS:
        words = set(re.findall(r"\w+", text.lower()))
        return latin > 2 * arabic and len(words & _WORDS[lang]) >= 2
    return bool(_URDU.search(text))


def _same(given: Any, wanted: Any) -> bool:
    a, b = _numeric(given), _numeric(wanted)
    if a is not None and b is not None:
        return a == b
    return norm(str(given)) == norm(str(wanted))


_NARROWING = ("city", "region", "neighborhood", "property_type", "purpose")  # each shrinks what a report counts


def _params_exact(params: dict[str, Any], wanted: dict[str, Any], *, asked: bool = False) -> bool:
    """The parameters wanted. And for a question asked (`asked`, report_select), no filter it did not ask: «أسعار
    الشقق في الرياض كلها» with neighbourhood=النرجس answers another question (review of 21 Nov). An exploration
    step narrows on purpose (report_explore deepens what was found)."""
    extra = asked and any(params.get(name) not in (None, "") for name in _NARROWING if name not in wanted)
    return not extra and all(
        params.get(name) is None if value is None else name in params and _same(params[name], value)
        for name, value in wanted.items()
    )


def _chosen(item: Chosen, given: dict[str, Any]) -> float:
    """1 · the right report with the right parameters, 0.5 · the right report, 0 · another."""
    if given.get("report") not in item.reports:
        return 0.0
    return 1.0 if _params_exact(given.get("params", {}), item.params, asked=True) else 0.5


def score_report_select(case: Case, output: dict[str, Any]) -> Score:
    """Each question's report found among the answer's; a report nobody asked for costs as much as a missing one."""
    expect = case.expected(SelectExpect)
    given = [g for g in output.get("reports", []) if isinstance(g, dict)]
    if not expect.reports:
        right = float(not given)  # no report answers the message: running one is wrong
        return Score(right, {"reports": right})
    best = [max((_chosen(item, g) for g in given), default=0.0) for item in expect.reports]
    metrics = {"reports": sum(b > 0 for b in best) / len(best), "params": sum(b == 1.0 for b in best) / len(best)}
    return Score(sum(best) / max(len(best), len(given)), metrics)


def score_report_explore(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(ExploreExpect)
    report = output.get("report")
    if report not in expect.report:
        return Score(0.0, {"step": 0.0})
    if report is None:
        return Score(1.0, {"step": 1.0})
    exact = _params_exact(output.get("params", {}), expect.params)
    return Score(1.0 if exact else 0.5, {"step": 1.0, "params": float(exact)})


def score_report_write(case: Case, output: dict[str, Any]) -> Score:
    expect = case.expected(WriteExpect)
    recommendations = [r for r in output.get("recommendations", []) if isinstance(r, dict)]
    text = " ".join([str(output.get("analysis", "")), *(str(r.get("text", "")) for r in recommendations)])
    parts = case.input["parts"]
    # as Laravel judges it (SuperAgent::write with NumberGuard::derived): the rows, and the totals and differences the
    # code works out from each part's rows — the writer is sent them (`computed`), and a total it cites is no invention
    rows = [row for part in parts for row in [*part["rows"], *derived_rows(part["rows"])]]
    params = {f"{i}.{name}": value for i, part in enumerate(parts) for name, value in part.get("params", {}).items()}
    params |= {f"{i}.row_count": part["row_count"] for i, part in enumerate(parts)}  # «3 أحياء»: how many rows it read
    # Laravel drops an analysis its NumberGuard rejects, and an empty one (SuperAgent::write): the panel shows the
    # rows alone. Empty is what is left when every sentence carried a number the rows do not hold (report_write)
    if not str(output.get("analysis", "")).strip() or not number_guard(text, rows, params):
        return Score(0.0, {"guard": 0.0})
    mention = recall(text, expect.mention)
    action = float(any(r.get("action") in expect.actions for r in recommendations)) if expect.actions else None
    measured = [m for m in (mention, action) if m is not None]
    return Score(
        sum(measured) / len(measured) if measured else 1.0, _present(guard=1.0, mention=mention, action=action)
    )


def score(case: Case, output: dict[str, Any]) -> Score:
    if case.agent in ("search_listings", "search_requests", "matching", "match_searchers"):
        return score_ranking(case, output)
    scorer = {
        "clean_listing": score_clean_listing,
        "summarize": score_summarize,
        "chat": score_chat,
        "report_select": score_report_select,
        "report_explore": score_report_explore,
        "report_write": score_report_write,
    }[case.agent]
    return scorer(case, output)
