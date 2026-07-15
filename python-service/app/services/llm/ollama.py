"""The default provider: the local Ollama model, over its REST API via httpx."""

import json
import logging

import httpx

from ...config import get_settings
from .base import LLMProvider
from .prompt import build_prompt

logger = logging.getLogger(__name__)


class OllamaProvider(LLMProvider):
    def __init__(
        self,
        url: str | None = None,
        model: str | None = None,
        timeout: float = 180.0,
    ) -> None:
        settings = get_settings()
        self.url = (url or settings.ollama_url).rstrip("/")
        self.model = model or settings.ollama_model
        self.timeout = timeout
        self.debug = settings.llm_debug

    def generate(self, *, grounding: str, type: str, marks: int, count: int) -> list[dict]:
        prompt = build_prompt(grounding=grounding, type=type, marks=marks, count=count)

        if self.debug:
            logger.info("LLM prompt (model=%s, type=%s, marks=%s, count=%s):\n%s",
                        self.model, type, marks, count, prompt)

        response = httpx.post(
            f"{self.url}/api/generate",
            json={
                "model": self.model,
                "prompt": prompt,
                "stream": False,
                # Ask Ollama to constrain decoding to valid JSON.
                "format": "json",
                "options": {"temperature": 0.4},
            },
            timeout=self.timeout,
        )
        response.raise_for_status()

        body = response.json().get("response", "")

        if self.debug:
            logger.info("LLM raw response:\n%s", body)

        return _coerce_items(body)


def _coerce_items(raw: str) -> list[dict]:
    """Pull the question list out of the model's JSON response, tolerantly.

    The model is asked for ``{"questions": [...]}`` but may return a bare list or a
    single object; anything unparseable yields an empty list, so a garbled response
    degrades to "generated nothing" rather than raising.
    """
    try:
        parsed = json.loads(raw)
    except (json.JSONDecodeError, TypeError):
        logger.warning("ollama returned non-JSON output; discarding")
        return []

    if isinstance(parsed, dict):
        if isinstance(parsed.get("questions"), list):
            return [item for item in parsed["questions"] if isinstance(item, dict)]
        return [parsed]
    if isinstance(parsed, list):
        return [item for item in parsed if isinstance(item, dict)]
    return []
