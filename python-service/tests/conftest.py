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


@pytest.fixture(scope="session")
def table_pdf(shared_root: Path) -> Path:
    """A digital paper whose question carries a ruled table (the EVA shape).

    The Precedence cell of activity A is deliberately empty: flattened text
    loses that information ("A 5 200"), the markdown rendering must keep it.
    """
    path = shared_root / "table.pdf"
    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", size=14)
    pdf.cell(0, 10, "1. Perform Earned Value Analysis of the project. [10]", new_x="LMARGIN", new_y="NEXT")
    rows = [
        ("Activity", "Duration", "Precedence", "Cost/day"),
        ("A", "5", "", "200"),
        ("B", "5", "A", "200"),
        ("C", "10", "A", "100"),
    ]
    for row in rows:
        for cell in row:
            pdf.cell(38, 8, cell, border=1)
        pdf.ln()
    pdf.cell(0, 10, "Calculate SV, SPI, CV and CPI respectively.", new_x="LMARGIN", new_y="NEXT")
    pdf.output(str(path))
    return path


@pytest.fixture(scope="session")
def junk_layer_pdf(shared_root: Path, digital_pdf: Path) -> Path:
    """A scan with the scanner's own garbled OCR baked in as the text layer.

    The real shape of the shared TU past papers: one full-page image, plus an
    embedded text layer long enough to pass any character count but useless
    ("PrinciJrles of Ulanagement"). Extraction must ignore that layer and OCR
    the image instead.
    """
    path = shared_root / "junk-layer.pdf"
    with pdfplumber.open(digital_pdf) as doc:
        image = doc.pages[0].to_image(resolution=150).original.convert("RGB")

    image_path = shared_root / "junk-layer-page.png"
    image.save(image_path)

    pdf = FPDF(unit="pt", format=(image.width, image.height))
    pdf.add_page()
    pdf.image(str(image_path), x=0, y=0, w=image.width, h=image.height)
    pdf.set_font("Helvetica", size=10)
    pdf.set_text_color(255, 255, 255)  # invisible-ish, like a real OCR layer
    for line in [
        "l-ribhuvan Uuiversity",
        "Carrdidate.s ure recluirecl trt give lheir onsv,et',s",
        "I' Deflne a hasli collisiorr. i5l",
        "2' Explairr thc diff'erence betrveen Blrs ancl DIrS. (lo rnarks)",
    ]:
        pdf.cell(0, 14, line, new_x="LMARGIN", new_y="NEXT")
    pdf.output(str(path))
    return path


@pytest.fixture(scope="session")
def framed_pdf(shared_root: Path) -> Path:
    """A page whose prose sits inside a Word-style layout frame.

    The shape of the TU CSC376 syllabus: pdfplumber's line strategy "finds" a
    two-column table over the whole page, but the second column is empty — a
    layout artifact, not a data table. The text must come out as plain lines,
    never wrapped into markdown pipes.
    """
    path = shared_root / "framed.pdf"
    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", size=12)
    for line in [
        "1. Define a hash collision. [5]",
        "2. Explain the difference between BFS and DFS. [10]",
        "3. List two uses of a B-tree. [5]",
    ]:
        pdf.cell(150, 10, line, border=1)
        pdf.cell(30, 10, "", border=1)
        pdf.ln()
    pdf.output(str(path))
    return path
