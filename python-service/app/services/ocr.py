import pytesseract
from PIL import Image

from ..config import get_settings


def image_to_text(image: Image.Image) -> str:
    """Run Tesseract over a rasterised page."""
    settings = get_settings()
    return pytesseract.image_to_string(
        image, lang=settings.ocr_language, config=f"--psm {settings.ocr_psm}"
    )


def page_to_image(page) -> Image.Image:
    """Rasterise a pdfplumber page (backed by pypdfium2 — no ImageMagick needed)."""
    settings = get_settings()
    return page.to_image(resolution=settings.ocr_dpi).original
