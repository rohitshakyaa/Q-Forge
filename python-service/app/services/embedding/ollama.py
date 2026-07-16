"""The default embedder: the local Ollama embedding model, via its batch REST API."""

import httpx

from ...config import get_settings
from .base import Embedder


class OllamaEmbedder(Embedder):
    dimensions = 768  # nomic-embed-text; keep in sync with RAG_VECTOR_SIZE in Laravel

    def __init__(
        self,
        url: str | None = None,
        model: str | None = None,
        timeout: float = 120.0,
    ) -> None:
        settings = get_settings()
        self.url = (url or settings.ollama_url).rstrip("/")
        self.model = model or settings.ollama_embed_model
        self.timeout = timeout

    def embed(self, texts: list[str]) -> list[list[float]]:
        # /api/embed is Ollama's batch endpoint: one call, one vector per input.
        response = httpx.post(
            f"{self.url}/api/embed",
            json={"model": self.model, "input": texts},
            timeout=self.timeout,
        )
        response.raise_for_status()

        embeddings = response.json().get("embeddings")
        if not isinstance(embeddings, list) or len(embeddings) != len(texts):
            raise RuntimeError(
                f"ollama returned {len(embeddings) if isinstance(embeddings, list) else 'no'} "
                f"embeddings for {len(texts)} texts"
            )
        return embeddings
