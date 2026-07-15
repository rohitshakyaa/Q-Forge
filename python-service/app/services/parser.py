"""Heuristic splitter that turns page text into candidate questions.

Deliberately conservative: it is better to hand a reviewer a slightly over-long
block of text than to shred one question into five. Everything it emits lands in
the admin review queue as `pending`, so a wrong guess costs a click, not data.
"""

import re

from ..schemas import Candidate
from .pdf import PageText

# A question starts at a top-level number: "1.", "1)", "Q3.", "Q. 4".
# Lettered parts — "(a)", "b)" — are NOT starts: they are either sub-parts of the
# question above or MCQ options, and splitting on them shreds both.
_QUESTION_START = re.compile(r"^(?:Q\s*\.?\s*)?(\d{1,2})\s*[.)]\s*")

# "Unit 3", "Unit-III", "Group B", "Section A" printed on a line of their own.
_UNIT_HINT = re.compile(
    r"^\s*((?:unit|group|section)\s*[-–—:]?\s*(?:\d{1,2}|[ivxIVX]{1,5}|[A-H]))\s*[:.]?\s*$",
    re.IGNORECASE,
)

# Marks are signposted by brackets, or by the word itself. A bare "(5)" is too
# often a list marker or a citation to trust, so parentheses must say "marks".
#
# Real papers rarely print a single number. Tribhuvan University model questions
# use compound forms for split questions — "[8+2]", "[2.5+2.5]", "[2 x 2.5=5]" —
# so the bracket contents are evaluated as a small expression rather than matched
# as one integer.
_BRACKET_MARKS = re.compile(r"\[([^\[\]]{1,24})\]")
_PAREN_MARKS = re.compile(r"\(([^()]{1,24})\)")
_BARE_MARKS = re.compile(r"\b(\d{1,2}(?:\.\d+)?)\s*marks?\b", re.IGNORECASE)

_MARKS_EXPR = re.compile(r"^[\d\s.+x×*=]+$", re.IGNORECASE)

# Rubric, letterhead and footer lines that carry no question content. These repeat
# on every page of a multi-paper document, so they must be dropped rather than
# swallowed into whichever question happens to be open when the page turns.
_NOISE = re.compile(
    r"""^\s*(?:
        page\s+\d+(\s+of\s+\d+)?
      | \d{1,3}
      | .*\b(full|pass)\s+marks\b.*
      | .*\btime\s*[:.]\s*\d.*
      | time\s*[:.].*
      | (candidates?|students?)\s+are\s+required.*
      | attempt\s+(all|any).*
      | answer\s+(all|any).*
      | .*\buniversity\b.*
      | .*\b(institute|faculty|college)\s+of\b.*
      | (examination|exam)\s*[:.]?\s*\d{4}.*
      | model\s+question.*
      | (subject|course)\s*(code)?\s*[:.].*
      | (bachelor|master)\s+(of|level)\b.*
      | .*\bsemester\b.*
      | \W+
    )\s*$""",
    re.IGNORECASE | re.VERBOSE,
)

# Verbs that ask for an essay rather than a fact.
_LONG_VERBS = re.compile(
    r"^\W*(explain|discuss|describe|derive|elaborate|compare|contrast|prove|design|"
    r"analys[ei]|analyz[ei]|illustrate|justify|evaluate|write\s+short\s+notes)\b",
    re.IGNORECASE,
)

# An MCQ option line: "(a) foo", "b) bar", "C. baz".
_OPTION_LINE = re.compile(r"^\s*\(?([a-dA-D])[).]\s+\S")

_LONG_MARKS_FLOOR = 8
_MIN_CANDIDATE_CHARS = 12
_MAX_OPTION_CHARS = 120
_MIN_OPTIONS_FOR_MCQ = 3


def is_noise(line: str) -> bool:
    return bool(_NOISE.match(line))


def unit_hint_from_line(line: str) -> str | None:
    match = _UNIT_HINT.match(line)
    if not match:
        return None
    return re.sub(r"\s+", " ", match.group(1)).strip()


def _evaluate_marks(inner: str) -> float | None:
    """Read a marks expression: "5", "12 marks", "8+2", "2.5+2.5", "2 x 2.5=5".

    Returns None for anything that is not purely arithmetic — "[i]", "[Fig. 2]"
    and citations must not be mistaken for a score.
    """
    expression = re.sub(r"marks?", "", inner, flags=re.IGNORECASE).strip().lower()
    if not expression or not _MARKS_EXPR.match(expression):
        return None

    # "2 x 2.5=5" states its own total; trust the printed answer over our arithmetic.
    if "=" in expression:
        tail = expression.rsplit("=", 1)[1].strip()

        return float(tail) if re.fullmatch(r"\d+(?:\.\d+)?", tail) else None

    try:
        if re.search(r"[x×*]", expression):
            product = 1.0
            for factor in re.split(r"[x×*]", expression):
                product *= float(factor.strip())

            return product

        return sum(float(part.strip()) for part in expression.split("+"))
    except ValueError:
        return None


def _marks_spans(block: str) -> list[tuple[int, int, float]]:
    """Locate every marks token, discarding overlaps longest-first.

    The forms overlap — "[12 Marks]" matches both the bracket form and the bare
    word form — and without this the same 12 marks would be counted twice.
    """
    spans: list[tuple[int, int, float]] = []

    for match in _BRACKET_MARKS.finditer(block):
        value = _evaluate_marks(match.group(1))
        if value is not None:
            spans.append((match.start(), match.end(), value))

    for match in _PAREN_MARKS.finditer(block):
        # Parentheses only count when they say so: "(5)" is a list marker.
        if "mark" not in match.group(1).lower():
            continue
        value = _evaluate_marks(match.group(1))
        if value is not None:
            spans.append((match.start(), match.end(), value))

    for match in _BARE_MARKS.finditer(block):
        spans.append((match.start(), match.end(), float(match.group(1))))

    spans.sort(key=lambda span: (span[0], -span[1]))

    kept: list[tuple[int, int, float]] = []
    consumed_to = -1
    for start, end, value in spans:
        if start < consumed_to:
            continue
        kept.append((start, end, value))
        consumed_to = end

    return kept


def find_marks(block: str) -> int | None:
    """Total the marks signposted in a block.

    A block may carry several ("a) … [5] b) … [5]"); the question is worth their
    sum. Fractional marks ("2.5+2.5") are summed then rounded, since the column
    is an integer and half-marks only ever appear as halves of a whole.
    """
    spans = _marks_spans(block)
    if not spans:
        return None

    total = round(sum(value for _, _, value in spans))

    return total if 0 < total <= 100 else None


def classify_type(block: str, marks: int | None) -> str:
    lines = [line.strip() for line in block.splitlines() if line.strip()]
    options = [
        line for line in lines if _OPTION_LINE.match(line) and len(line) <= _MAX_OPTION_CHARS
    ]
    if len(options) >= _MIN_OPTIONS_FOR_MCQ:
        return "mcq"

    body = " ".join(lines)
    if marks is not None and marks >= _LONG_MARKS_FLOOR:
        return "long"
    if _LONG_VERBS.match(body):
        return "long"
    return "short"


def _strip_marks(block: str) -> str:
    """Remove the marks tokens from the question text; they live in `marks` now."""
    for start, end, _ in reversed(_marks_spans(block)):
        block = block[:start] + " " + block[end:]

    return block


def _clean(block: str) -> str:
    block = _strip_marks(block)
    block = re.sub(r"[ \t]+", " ", block)
    block = re.sub(r"\n{2,}", "\n", block)

    return block.strip()


def _flush(
    number: str | None,
    lines: list[str],
    unit_hint: str | None,
    page: int,
    ocr: bool,
) -> Candidate | None:
    raw = "\n".join(lines)
    marks = find_marks(raw)
    text = _clean(raw)

    if len(text) < _MIN_CANDIDATE_CHARS:
        return None

    return Candidate(
        text=text,
        type=classify_type(raw, marks),
        marks=marks,
        unit_hint=unit_hint,
        number=number,
        page=page,
        ocr=ocr,
    )


def parse_pages(pages: list[PageText]) -> list[Candidate]:
    """Split every page into candidates, carrying the unit heading across pages."""
    candidates: list[Candidate] = []

    number: str | None = None
    buffer: list[str] = []
    unit_hint: str | None = None
    start_page = 1
    start_ocr = False

    def flush() -> None:
        nonlocal buffer, number
        if buffer:
            candidate = _flush(number, buffer, unit_hint, start_page, start_ocr)
            if candidate:
                candidates.append(candidate)
        buffer = []
        number = None

    for page in pages:
        for line in page.text.splitlines():
            stripped = line.strip()
            if not stripped:
                continue

            hint = unit_hint_from_line(stripped)
            if hint:
                # A new unit heading ends the question that preceded it.
                flush()
                unit_hint = hint
                continue

            if is_noise(stripped):
                continue

            match = _QUESTION_START.match(stripped)
            if match:
                flush()
                number = match.group(1)
                start_page = page.number
                start_ocr = page.ocr
                # Papers contain typos like "9.." — drop punctuation the numbering
                # regex left behind rather than starting the question with it.
                remainder = re.sub(r"^[.)\s]+", "", stripped[match.end() :]).strip()
                if remainder:
                    buffer.append(remainder)
            elif buffer:
                buffer.append(stripped)
            # Text before the first numbered question is preamble — dropped.

    flush()
    return candidates
