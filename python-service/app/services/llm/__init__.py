"""Swappable LLM provider for `/generate-questions`.

The provider is chosen by the `LLM_PROVIDER` env var so call sites never care which
backend answers: `ollama` (default, the real local model) or `stub` (deterministic
fake output, used by the test suite so pytest never depends on a running Ollama).
"""

from ...config import get_settings
from .base import LLMProvider
from .ollama import OllamaProvider
from .stub import StubProvider


def get_provider() -> LLMProvider:
    if get_settings().llm_provider.lower() == "stub":
        return StubProvider()
    return OllamaProvider()


__all__ = ["LLMProvider", "OllamaProvider", "StubProvider", "get_provider"]
