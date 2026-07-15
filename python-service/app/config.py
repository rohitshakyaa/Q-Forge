from functools import lru_cache
from pathlib import Path

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


@lru_cache
def get_settings() -> Settings:
    return Settings()
