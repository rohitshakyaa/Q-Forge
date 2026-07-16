from typing import Any, Generic, Literal, TypeVar

from pydantic import BaseModel, Field, model_validator

DocumentType = Literal["syllabus", "past_paper"]
QuestionType = Literal["short", "long", "mcq"]

T = TypeVar("T")


class Envelope(BaseModel, Generic[T]):
    """The `{status, data, errors}` shape every endpoint returns (see CLAUDE.md)."""

    status: Literal["success", "error"]
    data: T | None = None
    errors: list[str] = Field(default_factory=list)

    @classmethod
    def ok(cls, data: T) -> "Envelope[T]":
        return cls(status="success", data=data)

    @classmethod
    def fail(cls, *errors: str) -> "Envelope[T]":
        return cls(status="error", data=None, errors=list(errors))


class ExtractRequest(BaseModel):
    # Absolute path inside the shared volume. Laravel builds it from
    # config('services.python.shared_root') + document_uploads.stored_path.
    path: str
    type: DocumentType = "past_paper"


class Candidate(BaseModel):
    """One parsed question. Laravel decides whether and how to persist it."""

    text: str
    type: QuestionType
    marks: int | None = None
    # Free-text unit hint as printed on the paper ("Unit 3", "Group B"). Laravel
    # resolves it against the subject's units; null means the parser saw none.
    unit_hint: str | None = None
    number: str | None = None
    page: int
    ocr: bool = False


class SyllabusUnit(BaseModel):
    """One unit of a course, as printed in the syllabus."""

    number: int
    name: str | None = None
    hours: int | None = None
    # The unit's own syllabus body, rendered as markdown. Laravel stores this on
    # `units.content` — it is the per-unit grounding context for AI generation.
    content: str | None = None
    # True when `name` was inferred from the first sub-topic because the syllabus
    # printed the heading without one ("Unit 1: (3 hrs)").
    name_guessed: bool = False


class Course(BaseModel):
    """A course block. `code`/`name` are null when the PDF prints no course header."""

    code: str | None = None
    name: str | None = None
    description: str | None = None
    # The whole course as one markdown document, destined for `subjects.syllabus`.
    markdown: str = ""
    units: list[SyllabusUnit] = Field(default_factory=list)


class ExtractData(BaseModel):
    pages: int
    ocr_pages: int
    # A past_paper fills `candidates`; a syllabus fills `courses`. Never both.
    candidates: list[Candidate] = Field(default_factory=list)
    courses: list[Course] = Field(default_factory=list)
    meta: dict[str, Any] = Field(default_factory=dict)


class GenerateQuestionsRequest(BaseModel):
    """Input to `/generate-questions`.

    `grounding` is the block Laravel assembled by primary key (unit + subject syllabus
    + exemplars) — this service does no retrieval and no DB access, it only writes text.
    """

    grounding: str
    type: QuestionType
    marks: int
    count: int = Field(ge=1, le=25)
    # Target unit names for the prompt (0–2). Two names ask for questions that
    # span BOTH units. Never echoed back — Laravel is authoritative for units
    # and stamps them on save, exactly as it does for type and marks.
    units: list[str] = Field(default_factory=list, max_length=2)


class EmbedRequest(BaseModel):
    """Input to `/embed` (M6 — RAG). Laravel sends texts; vectors come back in
    input order. Batched: a re-index sends many texts per call, a dedup check one."""

    texts: list[str] = Field(min_length=1, max_length=256)


class EmbedData(BaseModel):
    # Model + dimensions are echoed so Laravel can stamp them next to stored
    # vectors — vectors from different models are incomparable (RAG-GUIDE §6).
    model: str
    dimensions: int
    embeddings: list[list[float]]


class GeneratedQuestion(BaseModel):
    """One AI-authored question. `type`/`marks` are echoed by the model as a sanity
    signal — Laravel is authoritative and re-stamps them from the target slot."""

    text: str = Field(min_length=1)
    type: QuestionType
    marks: int
    # MCQ only: the choices and the correct answer.
    options: list[str] | None = None
    answer: str | None = None

    @model_validator(mode="after")
    def _mcq_needs_choices(self) -> "GeneratedQuestion":
        if self.type == "mcq":
            if not self.options or len(self.options) < 2:
                raise ValueError("an mcq needs at least two options")
            if not self.answer:
                raise ValueError("an mcq needs an answer")
        return self
