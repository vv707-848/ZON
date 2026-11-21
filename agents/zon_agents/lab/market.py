"""«lab market»: texts from the market itself, as the owner collected them (21 Nov 2026), with their own spelling.

Measurement only, and no expected answer: nobody graded these texts, so nothing here is a «right answer» the prompts
could be fitted to. What is checked is what any right answer must satisfy, read from the text alone:

- an offer (`clean_listing`, as an advertiser's description reaches it):
  - `grounded`: each feature it keeps is said in the text (its words, as Laravel's CleanListingText::said reads them);
  - `numbers`: each amount the cleaned description states is one the text states, in digits, words or both
    («2 مليون و200 الف» is 2,200,000 however it is written — `numbers.grounded_amounts`, the agent's own guard);
  - `arabic`: the description is Arabic.
- a wish («مطلوب …», `chat` as a seeker's message reaches it, intent `semantic_search`):
  - `routes`: it points to «البحث الدلالي» or «مساعد المطابقة» (the prompt's own rule for a seeker);
  - `numbers`: no amount the message did not state (the prompt: no price of its own);
  - `asks`: no question back (the prompt: it guides, it does not interrogate);
  - `arabic`: the answer is Arabic.

Phone numbers, links and e-mails are masked as Laravel masks them before any agent (AiMasking) — the repository is
public, and production's agents never see them either.

An offer longer than the app accepts (`OFFER_MAX`, Laravel's Listing::DESC_MAX, owner's decision 21 Nov) is not
asked: the advertiser shortens it himself before it is sent, and the lab does not shorten it for him. The report
counts them (`over_limit`). The texts came as one stream: [build] splits it where an
offer («للبيع»، «للإيجار») or a wish («مطلوب» and a property) is announced, and drops the repeats.
"""

import asyncio
import json
import re
import time
from collections.abc import Sequence
from pathlib import Path
from typing import Any

from zon_agents.lab.runner import TODAY, FixedRetrieval, _percentile
from zon_agents.llm import LLM
from zon_agents.numbers import grounded_amounts, norm
from zon_agents.pipelines import PIPELINES
from zon_agents.pipelines.base import Ctx
from zon_agents.pipelines.text import DEED_REFUSALS

TEXTS = Path(__file__).parent / "market" / "market.jsonl"
OFFER_MAX = 300  # Laravel's Listing::DESC_MAX: letters, as mb_strlen counts them

# AiMasking's patterns and placeholders (backend/app/Ai/Masking/AiMasking.php), in its order
_SEP = r"[\s.\-()]{0,2}"
_MASKS = (
    (re.compile(r"\b(?:https?:/+|www\.)\S+", re.I), "[رابط]"),  # «https:/» too: one the market wrote
    (re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+"), "[بريد]"),
    (re.compile(rf"(?:\+|00)\s?\(?966\)?{_SEP}\d(?:{_SEP}\d){{7,8}}"), "[رقم]"),
    (re.compile(rf"(?<!\d)\(?966\)?{_SEP}5(?:{_SEP}\d){{8}}(?!\d)"), "[رقم]"),
    (re.compile(rf"(?<!\d)\(?0\d(?:{_SEP}\d){{7,8}}(?!\d)"), "[رقم]"),
    (re.compile(rf"(?<!\d)5\d(?:{_SEP}\d){{7}}(?!\d)"), "[رقم]"),
)
_DIGITS = str.maketrans("٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹", "01234567890123456789")
_PLACE = (
    r"(?:عماره|عمارة|فيلا|فله|فلة|شقه|شقة|ارض|أرض|بيت|دور|استراحه|استراحة|محل|مزرعه|مزرعة|قطعة|قطعه|ملحق|غرفه|غرفة)"
)
_STARTS = re.compile(rf"(?<!\S)(?=(?:للبيع|للإيجار|للايجار)(?!\S)|مطلوب\s+(?:\S+\s+)?{_PLACE})")
_TYPES = (
    ("villa", r"فيلا|فله|فلة|دوبلكس"),
    ("building", r"عماره|عمارة"),
    ("apartment", r"شقه|شقة"),
    ("land", r"ارض|أرض|قطعة|قطعه"),
    ("floor", r"دور"),
    ("rest_house", r"استراحه|استراحة"),
    ("shop", r"محل"),
    ("farm", r"مزرعه|مزرعة"),
)


def _type(text: str) -> str:
    """The property the text names first (a «دور» only when it names nothing else: villas describe their floors)."""
    at = {name: m.start() for name, words in _TYPES if (m := re.search(rf"(?<!\w)(?:و?ب?ال)?(?:{words})(?!\w)", text))}
    named = {name: pos for name, pos in at.items() if name != "floor"} or at
    return min(named, key=lambda name: named[name]) if named else ""


def mask(text: str) -> str:
    text = text.translate(_DIGITS)
    for pattern, placeholder in _MASKS:
        text = pattern.sub(placeholder, text)
    return text


def build(stream: str) -> list[dict[str, Any]]:
    """The stream as separate texts: masked, split where an offer or a wish starts, short pieces kept with the one
    before them, repeats dropped. A wish is a text that starts with «مطلوب»; every other is an offer."""
    flat = re.sub(r"\s+", " ", mask(stream)).strip()
    pieces: list[str] = []
    for piece in (p.strip() for p in _STARTS.split(flat)):
        if not piece:
            continue
        if pieces and (len(piece) < 120 or len(pieces[-1]) < 60):
            pieces[-1] = f"{pieces[-1]} {piece}"
        else:
            pieces.append(piece)
    seen: set[str] = set()
    texts: list[dict[str, Any]] = []
    for piece in pieces:
        key = re.sub(r"\W", "", piece)[:200]
        if key in seen:
            continue
        seen.add(key)
        kind = "wish" if piece.startswith("مطلوب") else "offer"
        found = _type(piece)
        texts.append({"id": f"market-{len(texts) + 1:03}", "kind": kind, "type": found, "text": piece[:4000]})
    return texts


def load() -> list[dict[str, Any]]:
    return [json.loads(line) for line in TEXTS.read_text(encoding="utf-8").splitlines() if line.strip()]


def _bare(word: str) -> str:
    return re.sub(r"^(?:و?ب?ال|و)(?=\w{2})", "", word)


def _said(feature: str, text: str) -> bool:
    words = {_bare(w) for w in re.findall(r"\w+", norm(text))}
    return all(_bare(w) in words for w in re.findall(r"\w+", norm(feature)))


def _arabic(text: str) -> bool:
    return len(re.findall(r"[؀-ۿ]", text)) > len(re.findall(r"[A-Za-z]", text))


def checks(item: dict[str, Any], output: dict[str, Any]) -> dict[str, float]:
    """What any right answer to [item] satisfies (the module's docstring), 1 or 0 each."""
    text = item["text"]
    if item["kind"] == "offer":
        desc, features = str(output.get("desc_clean", "")), list(output.get("features", []))
        return {
            "grounded": float(all(_said(f, text) for f in features)),
            "numbers": float(grounded_amounts(desc, text)),
            "arabic": float(_arabic(desc)),
        }
    answer = str(output.get("answer", ""))
    return {
        "routes": float(any(term in norm(answer) for term in (norm("البحث الدلالي"), norm("مساعد المطابقة")))),
        "numbers": float(grounded_amounts(answer, text)),
        "asks": float("؟" not in answer and "?" not in answer),
        "arabic": float(_arabic(answer)),
    }


def payload(item: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    if item["kind"] == "offer":
        return "clean_listing", {"type": item["type"], "text": item["text"]}
    return "chat", {"intent": "semantic_search", "role": "seeker", "lang": "ar", "message": item["text"][:500]}


async def _one(llm: LLM, item: dict[str, Any], slots: asyncio.Semaphore) -> dict[str, Any]:
    agent, data = payload(item)
    ctx = Ctx(llm, FixedRetrieval([]), today=lambda: TODAY)
    refusals: list[dict[str, str]] = []
    async with slots:
        DEED_REFUSALS.set(refusals)  # this task's own: a wording refused for its deed, why and where (redacted)
        start = time.perf_counter()
        try:
            result = await PIPELINES[agent](ctx, data)
        except Exception as exc:
            return {
                "id": item["id"],
                "agent": agent,
                "error": f"{type(exc).__name__}: {exc}"[:120],  # the reason too: timeout · invalid · amount
                "latency_ms": round((time.perf_counter() - start) * 1000),
                **({"refusal": refusals[0]} if refusals else {}),
            }
        elapsed = round((time.perf_counter() - start) * 1000)
    error = "degraded" if result.degraded else None
    return {
        "id": item["id"],
        "agent": agent,
        "error": error,
        "latency_ms": elapsed,
        "output": result.output,
        "checks": {} if error else checks(item, result.output),
    }


async def market(llm: LLM, items: Sequence[dict[str, Any]], at_once: int = 8) -> dict[str, Any]:
    """Every text, [at_once] at a time; per agent: asked, failed, p50 · p95, and each check's share."""
    slots = asyncio.Semaphore(at_once)
    asked = [i for i in items if i["kind"] != "offer" or len(i["text"]) <= OFFER_MAX]
    rows = await asyncio.gather(*(_one(llm, item, slots) for item in asked))
    report: dict[str, Any] = {}
    for agent in ("clean_listing", "chat"):
        own = [r for r in rows if r["agent"] == agent]
        if not own:
            continue
        answered = [r for r in own if r["error"] is None]
        names = sorted({name for r in answered for name in r["checks"]})
        latencies = sorted(r["latency_ms"] for r in answered) or [0]
        report[agent] = {
            "asked": len(own),
            **({"over_limit": len(items) - len(asked)} if agent == "clean_listing" else {}),
            "failed": len(own) - len(answered),
            "p50_ms": _percentile(latencies, 50),
            "p95_ms": _percentile(latencies, 95),
            "checks": {n: round(sum(r["checks"][n] for r in answered) / max(len(answered), 1), 3) for n in names},
            "missed": [
                {
                    "id": r["id"],
                    **({"error": r["error"]} if r["error"] else {"checks": r["checks"]}),
                    **({"refusal": r["refusal"]} if r.get("refusal") else {}),
                    "output": r.get("output"),
                }
                for r in own
                if r["error"] or min(r["checks"].values(), default=1) < 1
            ],
        }
    return report
