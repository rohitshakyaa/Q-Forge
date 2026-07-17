from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture
def client(shared_root: Path) -> TestClient:
    # Depend on shared_root so the settings cache is pointed at the tmp volume.
    return TestClient(app)


def post_extract(client: TestClient, path: Path, type_: str = "past_paper") -> dict:
    response = client.post("/extract", json={"path": str(path), "type": type_})
    assert response.status_code == 200
    return response.json()


class TestDigitalPdf:
    def test_returns_candidates_without_ocr(self, client, digital_pdf):
        body = post_extract(client, digital_pdf)

        assert body["status"] == "success"
        assert body["errors"] == []
        assert body["data"]["pages"] == 1
        assert body["data"]["ocr_pages"] == 0

        candidates = body["data"]["candidates"]
        assert len(candidates) == 4
        assert all(c["ocr"] is False for c in candidates)

    def test_detects_marks_types_and_units(self, client, digital_pdf):
        candidates = post_extract(client, digital_pdf)["data"]["candidates"]

        assert [c["marks"] for c in candidates] == [5, 10, None, 5]
        assert [c["type"] for c in candidates] == ["short", "long", "mcq", "short"]
        assert [c["unit_hint"] for c in candidates] == ["Unit 1", "Unit 1", "Unit 2", "Unit 2"]

    def test_letterhead_is_not_a_candidate(self, client, digital_pdf):
        candidates = post_extract(client, digital_pdf)["data"]["candidates"]
        assert all("Tribhuvan" not in c["text"] for c in candidates)


class TestScannedPdf:
    def test_ocr_fallback_recovers_text_from_an_image_only_pdf(self, client, scanned_pdf):
        body = post_extract(client, scanned_pdf)

        assert body["status"] == "success"
        assert body["data"]["ocr_pages"] == 1, "the scanned page should have gone through OCR"

        candidates = body["data"]["candidates"]
        assert candidates, "OCR produced no candidates"
        assert all(c["ocr"] is True for c in candidates)

    def test_ocr_candidates_carry_recognisable_content(self, client, scanned_pdf):
        candidates = post_extract(client, scanned_pdf)["data"]["candidates"]
        combined = " ".join(c["text"] for c in candidates).lower()

        # OCR is noisy — assert on distinctive tokens rather than exact strings.
        assert "hash collision" in combined
        assert "bfs" in combined

    def test_marks_survive_ocr(self, client, scanned_pdf):
        candidates = post_extract(client, scanned_pdf)["data"]["candidates"]
        assert any(c["marks"] == 5 for c in candidates)


class TestRuledTables:
    """A ruled table on a digital page is rendered as markdown, not flattened."""

    def test_table_becomes_markdown_inside_its_question(self, client, table_pdf):
        candidates = post_extract(client, table_pdf)["data"]["candidates"]

        assert len(candidates) == 1, "the table must stay inside its question"
        text = candidates[0]["text"]

        assert "| Activity | Duration | Precedence | Cost/day |" in text
        assert "|---|---|---|---|" in text
        # The empty Precedence cell survives — flattened text would say "A 5 200".
        # (the parser's whitespace cleanup collapses the empty cell to a single space)
        assert "| A | 5 | | 200 |" in text
        # Prose above and below the table keeps its reading order.
        assert text.index("Earned Value") < text.index("| Activity |") < text.index("Calculate SV")

    def test_marks_are_still_read_around_the_table(self, client, table_pdf):
        candidates = post_extract(client, table_pdf)["data"]["candidates"]
        assert candidates[0]["marks"] == 10


class TestJunkTextLayer:
    """A scan whose text layer is the scanner's own garbled OCR must be re-OCR'd."""

    def test_full_page_image_forces_ocr_despite_a_long_text_layer(self, client, junk_layer_pdf):
        body = post_extract(client, junk_layer_pdf)

        assert body["status"] == "success"
        assert body["data"]["ocr_pages"] == 1, "the junk layer should not have been trusted"

    def test_candidates_come_from_tesseract_not_the_junk_layer(self, client, junk_layer_pdf):
        candidates = post_extract(client, junk_layer_pdf)["data"]["candidates"]
        combined = " ".join(c["text"] for c in candidates).lower()

        assert "hash collision" in combined
        assert "recluirecl" not in combined, "text came from the garbled embedded layer"


class TestSyllabus:
    def test_syllabus_is_read_but_yields_no_questions(self, client, digital_pdf):
        body = post_extract(client, digital_pdf, type_="syllabus")

        assert body["status"] == "success"
        assert body["data"]["candidates"] == []
        assert body["data"]["pages"] == 1
        assert body["data"]["meta"]["characters"] > 0


class TestFailures:
    def test_path_outside_the_shared_volume_is_refused(self, client):
        body = post_extract(client, Path("/etc/passwd"))

        assert body["status"] == "error"
        assert "escapes the shared volume" in body["errors"][0]

    def test_traversal_out_of_the_volume_is_refused(self, client, shared_root):
        body = post_extract(client, shared_root / ".." / ".." / "etc" / "passwd")

        assert body["status"] == "error"
        assert "escapes the shared volume" in body["errors"][0]

    def test_missing_file_reports_an_error(self, client, shared_root):
        body = post_extract(client, shared_root / "nope.pdf")

        assert body["status"] == "error"
        assert "no such file" in body["errors"][0]

    def test_a_non_pdf_reports_an_error_rather_than_raising(self, client, shared_root):
        junk = shared_root / "junk.pdf"
        junk.write_bytes(b"this is definitely not a pdf")

        body = post_extract(client, junk)
        assert body["status"] == "error"
        assert body["errors"]
