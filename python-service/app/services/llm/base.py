"""The provider contract.

A provider turns an already-assembled grounding block (Laravel does the retrieval —
this service does no DB access and no retrieval) into raw candidate dicts. It may
return malformed or partial output; the `/generate-questions` endpoint validates each
item and routes the bad ones to `errors`, so a provider never has to be perfect.
"""

from abc import ABC, abstractmethod


class LLMProvider(ABC):
    @abstractmethod
    def generate(self, *, grounding: str, type: str, marks: int, count: int) -> list[dict]:
        """Return up to `count` raw question dicts for the given type + marks.

        Shape per item: ``{"text", "type", "marks", ["options", "answer"]}``. Returning
        fewer than `count`, or an occasional malformed item, is acceptable.
        """
        raise NotImplementedError
