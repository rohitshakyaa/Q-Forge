from functools import lru_cache
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Runtime configuration, overridable via environment variables."""

    model_config = SettingsConfigDict(env_prefix="QFORGE_", extra="ignore")

    # Root of the volume shared with Laravel. Every path handed to /extract must
    # resolve inside this directory — see services.pdf.resolve_shared_path.
    shared_storage_root: Path = Path("/shared-storage")

    # A page yielding fewer than this many characters of embedded text is treated
    # as scanned, and re-read with OCR.
    ocr_char_threshold: int = 40

    # Rasterisation DPI for the OCR fallback. 300 is the sweet spot for Tesseract;
    # lower loses small print, higher costs time for no accuracy gain.
    ocr_dpi: int = 300

    ocr_language: str = "eng"

    # M5 — LLM provider for /generate-questions. These read the plain, unprefixed
    # env names (OLLAMA_URL, ...) via validation_alias, matching docker-compose and
    # PLAN.md, rather than the QFORGE_ prefix the rest of the settings use.
    llm_provider: str = Field("ollama", validation_alias="LLM_PROVIDER")
    ollama_url: str = Field("http://qforge_ollama:11434", validation_alias="OLLAMA_URL")
    ollama_model: str = Field("qwen2.5:3b-instruct", validation_alias="OLLAMA_MODEL")
    # When true, log the exact prompt sent to the model and its raw response, so the
    # generation can be inspected in `docker compose logs qforge_python`.
    llm_debug: bool = Field(False, validation_alias="LLM_DEBUG")


@lru_cache
def get_settings() -> Settings:
    return Settings()
