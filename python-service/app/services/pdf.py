from dataclasses import dataclass
from pathlib import Path

import pdfplumber

from ..config import get_settings
from . import ocr


class UnsafePathError(ValueError):
    """The requested path escapes the shared volume."""


class UnreadableDocumentError(ValueError):
    """The file is missing or is not a PDF we can open."""


@dataclass(frozen=True)
class PageText:
    number: int  # 1-indexed
    text: str
    ocr: bool  # True when the text came from Tesseract rather than the PDF


def resolve_shared_path(raw: str) -> Path:
    """Resolve `raw` and refuse anything outside the shared volume.

    Laravel is the only caller, but the path arrives over HTTP, so `../` has to be
    ruled out before we open the file.
    """
    root = get_settings().shared_storage_root.resolve()
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = root / candidate

    resolved = candidate.resolve()
    if resolved != root and root not in resolved.parents:
        raise UnsafePathError(f"path escapes the shared volume: {raw}")
    if not resolved.is_file():
        raise UnreadableDocumentError(f"no such file: {raw}")

    return resolved


def extract_pages(path: Path) -> list[PageText]:
    """Read every page as text, falling back to OCR per page when a page is scanned.

    The fallback is per-page rather than per-document because real past papers are
    routinely mixed: a digital cover sheet in front of photocopied question pages.
    """
    threshold = get_settings().ocr_char_threshold
    pages: list[PageText] = []

    try:
        pdf = pdfplumber.open(path)
    except Exception as exc:  # pdfminer raises a grab-bag of exception types
        raise UnreadableDocumentError(f"could not open PDF: {exc}") from exc

    with pdf:
        for index, page in enumerate(pdf.pages, start=1):
            text = (page.extract_text() or "").strip()

            if len(text) < threshold:
                text = ocr.image_to_text(ocr.page_to_image(page)).strip()
                pages.append(PageText(number=index, text=text, ocr=True))
            else:
                pages.append(PageText(number=index, text=text, ocr=False))

    return pages
