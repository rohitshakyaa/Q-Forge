"""The embedder contract.

An embedder turns texts into fixed-length vectors ("embeddings" — see
docs/RAG-GUIDE.md §2). Processing only, exactly like the LLM provider next door:
no DB access, no retrieval — Laravel stores the vectors (Qdrant) and does every
similarity search itself.
"""

from abc import ABC, abstractmethod
from typing import Literal

#: Which side of a retrieval a text plays. Asymmetric models (nomic-embed-text)
#: embed a stored *document* and a live *query* with different task prefixes;
#: comparing a query vector to document vectors is what the model was trained
#: for. Laravel picks the task per call site: stored text → "document",
#: search text → "query". Symmetric models can ignore it.
EmbedTask = Literal["query", "document"]


class Embedder(ABC):
    #: Vector length this embedder produces. Vectors of different lengths (or from
    #: different models) live on different "maps" and must never be compared.
    dimensions: int

    #: Model identifier, echoed in the /embed response so Laravel can stamp it
    #: next to each stored vector and detect stale ones after a model swap. It
    #: encodes the *comparability* of the vectors, so a change in how text is
    #: embedded (e.g. adding task prefixes) must change this too.
    model: str

    @abstractmethod
    def embed(self, texts: list[str], task: EmbedTask = "document") -> list[list[float]]:
        """Return one vector per input text, in input order.

        `task` tells an asymmetric model whether these are stored documents or
        search queries (see EmbedTask); symmetric embedders may ignore it.

        Raises on failure — the /embed endpoint turns that into an error
        envelope. Unlike generation there is no partial success: a batch either
        embeds or it doesn't (the model is deterministic, not creative).
        """
        raise NotImplementedError
