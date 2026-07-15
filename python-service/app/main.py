import logging

from fastapi import FastAPI

from .schemas import Envelope, ExtractData, ExtractRequest
from .services import parser, pdf, syllabus

logger = logging.getLogger(__name__)

app = FastAPI(title="QForge processing service")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/extract", response_model=Envelope[ExtractData])
def extract(request: ExtractRequest) -> Envelope[ExtractData]:
    """Read a PDF off the shared volume and return candidate questions.

    Stateless by design: Laravel owns the database and decides what to persist.
    Failures come back as `{status: "error"}` with a 200 so the calling job can
    record the reason on `document_uploads` rather than retrying a bad file.
    """
    try:
        path = pdf.resolve_shared_path(request.path)
        pages = pdf.extract_pages(path)
    except (pdf.UnsafePathError, pdf.UnreadableDocumentError) as exc:
        return Envelope.fail(str(exc))
    except Exception as exc:  # noqa: BLE001 - surface the reason, never 500 the job
        logger.exception("extraction failed for %s", request.path)
        return Envelope.fail(f"extraction failed: {exc}")

    # A past paper yields question candidates; a syllabus yields the course and its
    # units, which an admin confirms before Laravel creates them. Never both.
    is_past_paper = request.type == "past_paper"
    candidates = parser.parse_pages(pages) if is_past_paper else []
    courses = [] if is_past_paper else syllabus.parse_courses(pages)

    return Envelope.ok(
        ExtractData(
            pages=len(pages),
            ocr_pages=sum(1 for page in pages if page.ocr),
            candidates=candidates,
            courses=courses,
            meta={
                "characters": sum(len(page.text) for page in pages),
                "type": request.type,
            },
        )
    )
