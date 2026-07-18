"""Deterministic fake embedder for the test suite (no Ollama required).

Each text hashes to a fixed pseudo-random unit vector: the same text always maps
to the same vector (so dedup logic is testable), different texts map to different
ones. No semantic meaning, of course — these vectors test the plumbing, not the
model.
"""

import hashlib
import math
import struct

from .base import Embedder, EmbedTask


class StubEmbedder(Embedder):
    dimensions = 768
    model = "stub"

    def embed(self, texts: list[str], task: EmbedTask = "document") -> list[list[float]]:
        # `task` is ignored on purpose: keeping the same text → same vector
        # (regardless of query/document) is what lets dedup plumbing tests assert
        # exact matches. The fake has no notion of asymmetric retrieval anyway.
        return [self._vector(text) for text in texts]

    def _vector(self, text: str) -> list[float]:
        # Stretch the text's sha256 into `dimensions` floats by re-hashing with a
        # counter, then L2-normalise so cosine behaves like it does for real models.
        values: list[float] = []
        counter = 0
        seed = hashlib.sha256(text.encode()).digest()
        while len(values) < self.dimensions:
            block = hashlib.sha256(seed + struct.pack("<I", counter)).digest()
            for i in range(0, len(block), 4):
                # Map 4 bytes to [-1, 1).
                (n,) = struct.unpack("<i", block[i : i + 4])
                values.append(n / 2**31)
            counter += 1
        values = values[: self.dimensions]

        norm = math.sqrt(sum(v * v for v in values)) or 1.0
        return [v / norm for v in values]
