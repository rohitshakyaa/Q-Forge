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

# On OCR'd pages Tesseract misreads the numbering itself: "1." comes out as
# "l," or "I.", and "11." as "1 1.". Accept those shapes there — but only there,
# since a digital page never prints them. A comma terminator must not be
# followed by a digit, or the "40,000" of a cash-flow table becomes question 40.
# Pen ticks over the margin also leave a stray glyph *before* an intact number
# (".4," / "- 12." in the SE 2076 scan), so up to two junk characters may
# precede it — the number and terminator themselves must still both be present.
_QUESTION_START_OCR = re.compile(
    r"^[-._'‘’~*]{0,2}\s?(?:Q\s*\.?\s*)?([\dIl|]\s?\d?)\s*(?:[.)]|,(?!\d))\s*"
)

# Some papers print their first question with no terminator at all ("1 Answer
# the following questions in short."). Accepted only on an OCR page and only
# while no question is open yet: before question 1 everything is filtered
# preamble, so a lone "1" (or its misreads) followed by a capitalised word can
# start nothing but the first question.
_FIRST_QUESTION_BARE_OCR = re.compile(r"^(?:Q\s*\.?\s*)?([1Il|])\s+(?=[A-Z\"(])")

# "Unit 3", "Unit-III", "Group B", "Section A" printed on a line of their own —
# or fused with the section's own marks directive ("Section B [6x5=30]", the
# e-commerce 2075/2076 shape). Nothing else may trail, or a question that merely
# begins with "Section A ..." would be eaten as a heading.
_UNIT_HINT = re.compile(
    r"^\s*((?:unit|group|section)\s*[-–—:]?\s*(?:\d{1,2}|[ivxIVX]{1,5}|[A-H]))\s*[:.;]?"
    r"\s*(?:[\[(]\s*\d{1,2}\s*[x×*]\s*\d{1,3}\s*(?:[=:]\s*\d{1,3}\s*)?[\])])?\s*$",
    re.IGNORECASE,
)

# Marks are signposted by brackets, or by the word itself. A bare "(5)" is too
# often a list marker or a citation to trust, so parentheses must say "marks" —
# except at the very end of a question, where papers like the TU 2079 CSC410 set
# print "(2+8)" / "(5)" with no word at all (see `_marks_spans`).
#
# Real papers rarely print a single number. Tribhuvan University model questions
# use compound forms for split questions — "[8+2]", "[2.5+2.5]", "[2 x 2.5=5]" —
# so the bracket contents are evaluated as a small expression rather than matched
# as one integer.
_BRACKET_MARKS = re.compile(r"\[([^\[\]]{1,24})\]")
_PAREN_MARKS = re.compile(r"\(([^()]{1,24})\)")
_BARE_MARKS = re.compile(r"\b(\d{1,2}(?:\.\d+)?)\s*marks?\b", re.IGNORECASE)
_TRAILING_PAREN = re.compile(r"\(([^()]{1,24})\)\s*$")

_MARKS_EXPR = re.compile(r"^[\d\s.+x×*=]+$", re.IGNORECASE)

# OCR misreads inside a marks token — "[I + 4]" for "[1 + 4]". Applied after
# lower-casing, and only when the token already carries a real digit, so a
# roman-numeral citation "[i]" is still rejected as non-arithmetic.
_OCR_DIGITS = str.maketrans({"i": "1", "l": "1", "|": "1", "o": "0"})

# The same misreads in a question number ("I." for "1."). No "o" here: a number
# is never letter-O in these papers, but "I" (unlike in marks, where "[i]" is a
# citation) is safe to read as 1 because the OCR start pattern already vetted it.
_OCR_DIGITS_UPPER = str.maketrans({"I": "1", "i": "1", "l": "1", "|": "1"})

# The section directive: "Attempt any TEN questions. [10 x 5 = 50]". Many TU
# papers (the MGT411 set among them) print marks nowhere else — the directive's
# middle factor is the worth of every question in the section. The stated total,
# when printed, must agree with count x per; a mismatch means OCR mangled a digit
# and the value cannot be trusted.
_DIRECTIVE = re.compile(r"^\s*(?:attempt|answer)\s+(?:all|any)\b", re.IGNORECASE)
_DIRECTIVE_MARKS = re.compile(r"[\[(]\s*(\d{1,2})\s*[x×*]\s*(\d{1,3})\s*(?:[=:]\s*(\d{1,3})\s*)?[\])]")

# The directive's expression alone on the next line — pdfplumber and Tesseract
# both split "Attempt any Ten questions.   (10x6=60)" across two lines when the
# gap between them is wide enough. Anchored on both ends: only a line that is
# nothing but the expression may complete the directive above it.
_DIRECTIVE_MARKS_ALONE = re.compile(
    r"^\s*[\[(]\s*\d{1,2}\s*[x×*]\s*\d{1,3}\s*(?:[=:]\s*\d{1,3}\s*)?[\])]\s*$"
)

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
    # Markdown table rows (pdf.py renders ruled tables that way) are content,
    # never noise — the all-punctuation separator "|---|---|" would otherwise
    # match the \W+ rule and punch a hole in the table.
    if line.lstrip().startswith("|"):
        return False

    return bool(_NOISE.match(line))


def unit_hint_from_line(line: str) -> str | None:
    match = _UNIT_HINT.match(line)
    if not match:
        return None
    return re.sub(r"\s+", " ", match.group(1)).strip()


def _directive_marks(line: str) -> int | None:
    """Per-question value of a "count x per (= total)" expression in `line`.

    The stated total, when printed, must agree with count x per; a mismatch
    means OCR mangled a digit and the value cannot be trusted.
    """
    match = _DIRECTIVE_MARKS.search(line)
    if not match:
        return None

    count, per = int(match.group(1)), int(match.group(2))
    if match.group(3) is not None and count * per != int(match.group(3)):
        return None

    return per


def section_default_from_line(line: str) -> int | None:
    """Per-question marks from a section directive, or None.

    "Attempt any TEN questions. [10 x 5 = 50]" -> 5. Only the "count x per"
    shape counts; a directive with no expression sets nothing.
    """
    if not _DIRECTIVE.match(line):
        return None

    return _directive_marks(line)


def _evaluate_marks(inner: str) -> float | None:
    """Read a marks expression: "5", "12 marks", "8+2", "2.5+2.5", "2 x 2.5=5".

    Returns None for anything that is not purely arithmetic — "[i]", "[Fig. 2]"
    and citations must not be mistaken for a score.
    """
    expression = re.sub(r"marks?", "", inner, flags=re.IGNORECASE).strip().lower()
    if re.search(r"\d", expression):
        expression = expression.translate(_OCR_DIGITS)
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


def _marks_spans(block: str, default: int | None = None) -> list[tuple[int, int, float]]:
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

    # A wordless paren at the very end of the question — "… algorithm. (2+8)" —
    # is a marks token, not a list marker: a compound expression always, a bare
    # number only when it agrees with the section directive's per-question value.
    trailing = _TRAILING_PAREN.search(block)
    if trailing:
        value = _evaluate_marks(trailing.group(1))
        if value is not None and (
            re.search(r"[+x×*=]", trailing.group(1), re.IGNORECASE)
            or (default is not None and value == default)
        ):
            spans.append((trailing.start(), trailing.end(), value))

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


def find_marks(block: str, default: int | None = None) -> int | None:
    """Total the marks signposted in a block.

    A block may carry several ("a) … [5] b) … [5]"); the question is worth their
    sum. Fractional marks ("2.5+2.5") are summed then rounded, since the column
    is an integer and half-marks only ever appear as halves of a whole.

    `default` is the section directive's per-question value; it does not add to
    the total, it only vouches for a bare trailing "(5)".
    """
    spans = _marks_spans(block, default)
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


def _strip_marks(block: str, default: int | None = None) -> str:
    """Remove the marks tokens from the question text; they live in `marks` now."""
    for start, end, _ in reversed(_marks_spans(block, default)):
        block = block[:start] + " " + block[end:]

    return block


def _clean(block: str, default: int | None = None) -> str:
    block = _strip_marks(block, default)
    block = re.sub(r"[ \t]+", " ", block)
    block = re.sub(r"\n{2,}", "\n", block)

    return block.strip()


def _flush(
    number: str | None,
    lines: list[str],
    unit_hint: str | None,
    page: int,
    ocr: bool,
    default: int | None = None,
) -> Candidate | None:
    raw = "\n".join(lines)
    # The question's own printed marks win; the section directive's per-question
    # value fills in when the paper prints marks nowhere else (the MGT411 set).
    marks = find_marks(raw, default)
    if marks is None:
        marks = default
    text = _clean(raw, default)

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
    section_default: int | None = None
    pending_directive = False
    start_page = 1
    start_ocr = False

    def flush() -> None:
        nonlocal buffer, number
        if buffer:
            candidate = _flush(number, buffer, unit_hint, start_page, start_ocr, section_default)
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
                # A new unit heading ends the question that preceded it — and
                # voids the old directive: if this section's own directive is
                # missed, better no marks than the previous section's. A fused
                # heading ("Section B [6x5=30]") carries the directive itself.
                flush()
                unit_hint = hint
                section_default = _directive_marks(stripped)
                pending_directive = False
                continue

            default = section_default_from_line(stripped)
            if default is not None:
                # The directive separates sections as surely as a heading does.
                flush()
                section_default = default
                pending_directive = False
                continue

            if _DIRECTIVE.match(stripped):
                # A directive with no expression on its own line — the layout
                # may have pushed "(10x6=60)" to the next one. Note that, and
                # let the noise rule drop the directive line as before.
                pending_directive = True
            elif pending_directive:
                pending_directive = False
                if _DIRECTIVE_MARKS_ALONE.match(stripped):
                    completed = _directive_marks(stripped)
                    if completed is not None:
                        flush()
                        section_default = completed
                        continue

            if is_noise(stripped):
                continue

            start_pattern = _QUESTION_START_OCR if page.ocr else _QUESTION_START
            match = start_pattern.match(stripped)
            if match is None and page.ocr and number is None and not candidates:
                # Before the first question everything is preamble, so a bare
                # "1 Answer the following…" (no terminator printed at all) can
                # start nothing but question 1.
                match = _FIRST_QUESTION_BARE_OCR.match(stripped)
            if match:
                flush()
                number = re.sub(r"\s+", "", match.group(1)).translate(_OCR_DIGITS_UPPER)
                start_page = page.number
                start_ocr = page.ocr
                # Papers contain typos like "9.." — drop punctuation the numbering
                # regex left behind rather than starting the question with it.
                remainder = re.sub(r"^[.)\s]+", "", stripped[match.end() :]).strip()
                if remainder:
                    buffer.append(remainder)
            elif number is not None:
                # A question is open — even if its number stood alone on its own
                # line and the buffer is still empty (pdfplumber renders many TU
                # papers exactly that way: "5." on one line, the text below it).
                buffer.append(stripped)
            # Text before the first numbered question is preamble — dropped.

    flush()
    return candidates
