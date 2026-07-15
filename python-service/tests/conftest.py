"""Fixture PDFs are generated, not committed.

`digital.pdf` carries a real text layer. `scanned.pdf` is that same page rasterised
and re-wrapped as an image — the exact shape of a photocopied past paper, and the
only way to exercise the OCR fallback for real.
"""

import os
from pathlib import Path

import pdfplumber
import pytest
from fpdf import FPDF

from app.config import get_settings

PAST_PAPER_TEXT = [
    "Tribhuvan University",
    "Institute of Engineering",
    "Examination: 2023",
    "Full Marks: 60",
    "Time: 3 hrs",
    "",
    "Unit 1",
    "1. Define a hash collision. [5]",
    "2. Explain the difference between BFS and DFS. (10 marks)",
    "",
    "Unit 2",
    "3. Which of the following is a stable sort?",
    "(a) Quicksort",
    "(b) Merge sort",
    "(c) Heapsort",
    "(d) Selection sort",
    "4. List two uses of a B-tree. [5]",
]


@pytest.fixture(scope="session")
def shared_root(tmp_path_factory) -> Path:
    """Stand in for the /shared-storage volume, so path validation is exercised."""
    root = tmp_path_factory.mktemp("shared-storage")
    os.environ["QFORGE_SHARED_STORAGE_ROOT"] = str(root)
    get_settings.cache_clear()
    yield root
    del os.environ["QFORGE_SHARED_STORAGE_ROOT"]
    get_settings.cache_clear()


@pytest.fixture(scope="session")
def digital_pdf(shared_root: Path) -> Path:
    path = shared_root / "digital.pdf"
    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", size=16)
    for line in PAST_PAPER_TEXT:
        pdf.cell(0, 10, line, new_x="LMARGIN", new_y="NEXT")
    pdf.output(str(path))
    return path


@pytest.fixture(scope="session")
def scanned_pdf(shared_root: Path, digital_pdf: Path) -> Path:
    """The digital paper with its text layer stripped: pages become bitmaps."""
    path = shared_root / "scanned.pdf"
    with pdfplumber.open(digital_pdf) as doc:
        images = [page.to_image(resolution=300).original.convert("RGB") for page in doc.pages]

    images[0].save(path, "PDF", save_all=True, append_images=images[1:])
    return path
