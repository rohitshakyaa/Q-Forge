"""The default embedder: the local Ollama embedding model, via its batch REST API."""

import httpx

from ...config import get_settings
from .base import Embedder, EmbedTask

# nomic-embed-text is asymmetric: it was trained with a task prefix on every
# input, and embedding raw (prefix-less) text degrades retrieval quality. Stored
# text is a "document", search text is a "query"; matching a query against
# documents is exactly the case the prefixes were trained for.
_PREFIXES: dict[EmbedTask, str] = {
    "query": "search_query: ",
    "document": "search_document: ",
}


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
        # The raw name Ollama knows the model by (used in the API call)…
        self.ollama_model = model or settings.ollama_embed_model
        # …and the *comparability tag* Laravel stamps on stored vectors. The
        # "/prefixed" suffix marks these as task-prefixed, so vectors from the
        # earlier prefix-less pipeline are detectably stale and a reindex is
        # clearly required (RAG-GUIDE §6 rule 2).
        self.model = f"{self.ollama_model}/prefixed"
        self.timeout = timeout

    def embed(self, texts: list[str], task: EmbedTask = "document") -> list[list[float]]:
        prefix = _PREFIXES[task]
        prefixed = [f"{prefix}{text}" for text in texts]

        # /api/embed is Ollama's batch endpoint: one call, one vector per input.
        response = httpx.post(
            f"{self.url}/api/embed",
            json={"model": self.ollama_model, "input": prefixed},
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
