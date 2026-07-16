"""The embedder contract.

An embedder turns texts into fixed-length vectors ("embeddings" — see
docs/RAG-GUIDE.md §2). Processing only, exactly like the LLM provider next door:
no DB access, no retrieval — Laravel stores the vectors (Qdrant) and does every
similarity search itself.
"""

from abc import ABC, abstractmethod


class Embedder(ABC):
    #: Vector length this embedder produces. Vectors of different lengths (or from
    #: different models) live on different "maps" and must never be compared.
    dimensions: int

    #: Model identifier, echoed in the /embed response so Laravel can stamp it
    #: next to each stored vector and detect stale ones after a model swap.
    model: str

    @abstractmethod
    def embed(self, texts: list[str]) -> list[list[float]]:
        """Return one vector per input text, in input order.

        Raises on failure — the /embed endpoint turns that into an error
        envelope. Unlike generation there is no partial success: a batch either
        embeds or it doesn't (the model is deterministic, not creative).
        """
        raise NotImplementedError
