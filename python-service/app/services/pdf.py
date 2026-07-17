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


def _render_table_markdown(rows: list[list[str | None]]) -> str:
    """Render pdfplumber's table matrix as a markdown table.

    Cells are None for empty grid cells — exactly the information the flattened
    text loses ("A 5 200" no longer says which column the 200 was in).
    """
    cleaned = [
        [" ".join((cell or "").split()).replace("|", "/") for cell in row] for row in rows
    ]
    width = max(len(row) for row in cleaned)
    lines = ["| " + " | ".join(row + [""] * (width - len(row))) + " |" for row in cleaned]
    lines.insert(1, "|" + "---|" * width)

    return "\n".join(lines)


def _is_data_table(rows: list[list[str | None]]) -> bool:
    """True when the detected grid actually distributes content across columns.

    pdfplumber's line strategy also "finds" a table in an ordinary Word page
    frame — the TU CSC376 syllabus yields a 26-row, 2-column grid whose second
    column is empty in all but 3 rows — and wrapping the whole page in pipes
    hides every heading from the downstream parsers. A real data table fills
    two or more cells in most of its rows; a layout artifact fills one.
    """
    multi_cell_rows = sum(
        1 for row in rows if sum(1 for cell in row if cell and cell.strip()) >= 2
    )

    return multi_cell_rows * 2 >= len(rows)


def _extract_text_with_tables(page) -> str:
    """Page text with ruled tables rendered as markdown, in reading order.

    `extract_text()` flattens a table row to "A 5 200", silently dropping empty
    cells. Here the ruled tables are lifted out (`find_tables`, line strategy),
    the remaining text is read from *outside* their boxes, and each table is
    spliced back in as markdown at its vertical position.

    Only for digital pages: a scan's table exists solely in the bitmap, where
    Tesseract has no notion of cells — those still come out as flattened rows.
    """
    tables = [
        (table, rows)
        for table in page.find_tables()
        if len(table.rows) >= 2
        and len(table.rows[0].cells) >= 2
        and _is_data_table(rows := table.extract())
    ]
    if not tables:
        return (page.extract_text() or "").strip()

    prose = page
    for table, _ in tables:
        prose = prose.outside_bbox(table.bbox)

    blocks: list[tuple[float, str]] = [
        (line["top"], line["text"]) for line in prose.extract_text_lines()
    ]
    for table, rows in tables:
        blocks.append((table.bbox[1], _render_table_markdown(rows)))

    blocks.sort(key=lambda block: block[0])

    return "\n".join(text for _, text in blocks).strip()


def _image_coverage(page) -> float:
    """Fraction of the page area covered by embedded images.

    A born-digital page has none (or small logos); a scanned page is one image
    covering everything. The distinction matters because scanners bake their own
    OCR text layer into the file, and that layer is routinely garbage
    ("PrinciJrles of Ulanagement") while still long enough to pass a character
    count — so char count alone cannot decide when to re-OCR.
    """
    page_area = float(page.width) * float(page.height)
    if not page_area:
        return 0.0

    image_area = 0.0
    for image in page.images:
        width = float(image["x1"]) - float(image["x0"])
        height = float(image["bottom"]) - float(image["top"])
        image_area += max(width, 0.0) * max(height, 0.0)

    return image_area / page_area


def extract_pages(path: Path) -> list[PageText]:
    """Read every page as text, falling back to OCR per page when a page is scanned.

    The fallback is per-page rather than per-document because real past papers are
    routinely mixed: a digital cover sheet in front of photocopied question pages.
    A page is scanned when its text layer is near-empty *or* when it is one big
    image — the text layer of an image page is the scanner's own OCR, which is
    consistently worse than a fresh Tesseract pass at 300 DPI.
    """
    settings = get_settings()
    pages: list[PageText] = []

    try:
        pdf = pdfplumber.open(path)
    except Exception as exc:  # pdfminer raises a grab-bag of exception types
        raise UnreadableDocumentError(f"could not open PDF: {exc}") from exc

    with pdf:
        for index, page in enumerate(pdf.pages, start=1):
            text = (page.extract_text() or "").strip()

            scanned = (
                len(text) < settings.ocr_char_threshold
                or _image_coverage(page) >= settings.ocr_image_coverage
            )
            if scanned:
                text = ocr.image_to_text(ocr.page_to_image(page)).strip()
                pages.append(PageText(number=index, text=text, ocr=True))
            else:
                pages.append(PageText(number=index, text=_extract_text_with_tables(page), ocr=False))

    return pages
