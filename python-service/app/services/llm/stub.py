"""Deterministic fake provider for the test suite (no Ollama required).

It returns exactly `count` well-formed items so the happy path is assertable. When
the grounding block contains `MALFORMED_SENTINEL`, it also appends one item missing
its `text`, so the endpoint's partial-success handling (valid subset in `data`, bad
item in `errors`) can be exercised without a real, non-deterministic model.
"""

from .base import LLMProvider

MALFORMED_SENTINEL = "__EMIT_MALFORMED__"


class StubProvider(LLMProvider):
    def generate(
        self,
        *,
        grounding: str,
        type: str,
        marks: int,
        count: int,
        units: list[str] | None = None,
    ) -> list[dict]:
        # Echo the target units into the text so tests can assert the plumbing
        # end-to-end (Laravel -> request -> provider) without a real model.
        scope = f" [{' + '.join(units)}]" if units else ""

        items: list[dict] = []
        for i in range(count):
            item = {
                "text": (
                    f"[stub] Question {i + 1}: explain a key idea "
                    f"from the provided syllabus.{scope}"
                ),
                "type": type,
                "marks": marks,
            }
            if type == "mcq":
                item["options"] = ["Option A", "Option B", "Option C", "Option D"]
                item["answer"] = "Option A"
            items.append(item)

        if MALFORMED_SENTINEL in grounding:
            # Missing the required `text` field → the endpoint routes it to `errors`.
            items.append({"type": type, "marks": marks})

        return items
