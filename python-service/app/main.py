import logging

from fastapi import FastAPI
from pydantic import ValidationError

# Surface app INFO logs (e.g. the LLM prompt/response dump when LLM_DEBUG=1) in the
# container output; without a root handler, app loggers only emit WARNING and above.
logging.basicConfig(level=logging.INFO)

from .schemas import (
    Envelope,
    ExtractData,
    ExtractRequest,
    GeneratedQuestion,
    GenerateQuestionsRequest,
)
from .services import parser, pdf, syllabus
from .services.llm import get_provider

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


@app.post("/generate-questions", response_model=Envelope[list[GeneratedQuestion]])
def generate_questions(
    request: GenerateQuestionsRequest,
) -> Envelope[list[GeneratedQuestion]]:
    """Write candidate questions from a Laravel-assembled grounding block.

    Processing only: no retrieval, no DB. The valid subset comes back in `data` and
    every malformed item in `errors` — one bad item never discards the whole batch.
    Laravel validates against the slot, resolves the unit, and persists.
    """
    provider = get_provider()

    try:
        raw_items = provider.generate(
            grounding=request.grounding,
            type=request.type,
            marks=request.marks,
            count=request.count,
            units=request.units,
        )
    except Exception as exc:  # noqa: BLE001 - surface the reason, never 500 the caller
        logger.exception("generation failed")
        return Envelope.fail(f"generation failed: {exc}")

    valid: list[GeneratedQuestion] = []
    errors: list[str] = []

    for index, item in enumerate(raw_items):
        try:
            valid.append(GeneratedQuestion.model_validate(item))
        except ValidationError as exc:
            reason = "; ".join(e["msg"] for e in exc.errors()) or "invalid question"
            errors.append(f"item {index}: {reason}")

    # Partial success is still success: the caller reads `data` for what to store and
    # logs `errors`. Only a provider-level failure (above) is reported as an error.
    return Envelope(status="success", data=valid, errors=errors)
