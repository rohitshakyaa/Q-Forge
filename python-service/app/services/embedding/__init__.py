"""Swappable embedder for `/embed` (M6 — RAG, see docs/RAG-GUIDE.md).

Selection reuses the `LLM_PROVIDER` env var: `stub` means "no Ollama anywhere"
(deterministic fake vectors for pytest), anything else gets the real local
embedding model. One switch keeps test wiring identical to /generate-questions.
"""

from ...config import get_settings
from .base import Embedder
from .ollama import OllamaEmbedder
from .stub import StubEmbedder


def get_embedder() -> Embedder:
    if get_settings().llm_provider.lower() == "stub":
        return StubEmbedder()
    return OllamaEmbedder()


__all__ = ["Embedder", "OllamaEmbedder", "StubEmbedder", "get_embedder"]
