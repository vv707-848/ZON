"""Perceptual hash of a photo, on the CPU (architecture §8: idealo/imagededup, never the GPU).

Optional: installed with the `phash` extra. Without it POST /media/phash answers 501 and Laravel keeps its local
hasher (IMAGE_HASH_DRIVER=gd).
"""

import asyncio
import io
from typing import Any, Protocol


class Hasher(Protocol):
    async def phash(self, image: bytes) -> str: ...


class PHashHasher:
    def __init__(self, engine: Any) -> None:
        self._engine = engine

    async def phash(self, image: bytes) -> str:
        return await asyncio.to_thread(self._encode, image)

    def _encode(self, image: bytes) -> str:
        import numpy as np
        from PIL import Image

        with Image.open(io.BytesIO(image)) as picture:
            array = np.asarray(picture.convert("RGB"))
        return str(self._engine.encode_image(image_array=array))


def build_hasher() -> Hasher | None:
    try:
        from imagededup.methods import PHash
    except ImportError:
        return None
    return PHashHasher(PHash(verbose=False))
