"""The simulation: every part of the AI node, deterministic, with no weights and no GPU.

It is NOT a model, and nothing it answers is a measure of one. It exists so that every result is known:

- `embed.py` — bag-of-words vectors in bge-m3's shape: texts sharing words are close, so the hybrid order is known.
- `rules.py` — the rule model: what the documented rules alone answer (docs/model-lab.md «من أين الأسئلة»: the
  budget and its 10%, the rooms, the share of the wishes met). It reads no expected answer.
- `oracle.py` — for the lab only: from a case's expected answer, the answer that is right, the one that breaks
  every limit, the one that ranks positions, and the one that never answers. They measure the lab itself: a right
  answer must score 1 through the production pipeline, and each limit of «قراءة النتيجة» must catch what it names.
- `server.py` — the AI node's two servers (the OpenAI-compatible one and the embedding one) answering with the
  rule model, so the whole platform runs end to end on one machine (`zon-agents sim`, compose.yaml).
- `labsim.py` — `zon-agents lab sim`: the golden cases through the production pipelines against each of them, and
  every known result checked.

The profile is `sim-rules` (profiles.SIMULATED, runtime "sim"); every answer of the service then says
`simulated: true`, and Laravel refuses it in production unless PRODUCTION_ALLOWS_FAKE names `ai`.
"""
