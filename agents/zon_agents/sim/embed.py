"""Bag-of-words vectors in the shape of bge-m3's three: texts sharing words are close, nothing else is known of
them. Deterministic (md5 of each word), so an order through Qdrant is the same on every machine."""

import hashlib
import math
import re
from collections import Counter

from zon_agents.embeddings import DENSE_SIZE, Embedding

_WORD = re.compile(r"\w+")
SPARSE_SLOTS = 250_000
COLBERT_TOKENS = 24


def _slot(token: str) -> int:
    return int(hashlib.md5(token.encode(), usedforsecurity=False).hexdigest(), 16)


def bag_embedding(text: str) -> Embedding:
    tokens = _WORD.findall(text.lower()) or ["empty"]
    dense = [0.0] * DENSE_SIZE
    for token in tokens:
        dense[_slot(token) % DENSE_SIZE] += 1.0
    norm = math.sqrt(sum(v * v for v in dense))
    counts = Counter(_slot(token) % SPARSE_SLOTS for token in tokens)
    colbert = []
    for token in tokens[:COLBERT_TOKENS]:
        vector = [0.0] * DENSE_SIZE
        vector[_slot(token) % DENSE_SIZE] = 1.0
        colbert.append(vector)
    return Embedding([v / norm for v in dense], list(counts), [float(c) for c in counts.values()], colbert)
