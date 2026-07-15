"""Parses a syllabus PDF into courses and their units.

Stateless, like the rest of this service: it returns structure, and Laravel decides
what to persist. The output feeds two things — the admin's confirmation page, and
(via `units.content`) the grounding context for AI question generation.

Bodies are rendered as markdown rather than passed through raw. A PDF hard-wraps
its text at the page width, mid-sentence, so the raw lines would hand a downstream
chunker fragments that end nowhere in particular.
"""

import re

from ..schemas import Course, SyllabusUnit
from .pdf import PageText

# "Course Title: NET Centric Computing        Full Marks: 60 + 20 + 20"
# pdfplumber collapses the two printed columns onto one line, so the trailing
# right-hand column has to be cut off the captured title.
_COURSE_TITLE = re.compile(r"^Course Title:\s*(.+)$", re.IGNORECASE)
_TITLE_TAIL = re.compile(r"\s+(?:Full|Pass)\s+Marks\s*:.*$", re.IGNORECASE)
_COURSE_NO = re.compile(r"^Course No:\s*(\S+)", re.IGNORECASE)

# "Unit 1: Language Preliminaries (8 Hrs.)", "Unit 3: (4hrs)", "Unit 2. Foo (5 hrs)"
_UNIT_HEADING = re.compile(
    r"^Unit\s+(\d{1,2})\s*[:.]?\s*(.*?)\s*\(\s*(\d{1,3})\s*hrs?\.?\s*\)\s*$",
    re.IGNORECASE,
)

# "1.1 Compiler Structure: Analysis and Synthesis Model of Compilation, ..."
_SUBTOPIC = re.compile(r"^(\d{1,2}\.\d{1,2})\s+(.*)$")
_SUBTOPIC_LABEL = re.compile(r"^([^:]{3,60}):\s*(.*)$", re.DOTALL)

_DESCRIPTION = re.compile(r"^Course Description:\s*(.*)$", re.IGNORECASE)

# Headings that end the final unit's body — everything after them is back matter.
_END_OF_UNITS = re.compile(
    r"^\s*(?:laboratory\s+works?|lab\s+works?|practical\s+works?"
    r"|text\s*/?\s*reference\s+books?|reference\s+books?|text\s+books?"
    r"|evaluation|assignments?)\b.*$",
    re.IGNORECASE,
)

# Headings that end the course description.
_END_OF_DESCRIPTION = re.compile(
    r"^\s*(?:course\s+(?:objectives?|contents?|details?)|unit\s+\d)", re.IGNORECASE
)

_PUNCTUATION = str.maketrans(
    {
        "“": '"', "”": '"', "„": '"',
        # U+201F is a *double* quote in Unicode, but the TU syllabus PDFs emit it
        # where an apostrophe belongs ("Practitioner‟s Approach") — a font
        # substitution artifact. Fold it to what it means here, not what it is.
        "‟": "'",
        "‘": "'", "’": "'", "‚": "'", "‛": "'",
        "–": "-", "—": "-", "−": "-",
        "…": "...",
        " ": " ",
    }
)

_MAX_HEADING_LEN = 80


def normalize_punctuation(text: str) -> str:
    """Fold the typographic quotes and dashes a PDF carries into plain ASCII."""
    return text.translate(_PUNCTUATION)


def _looks_like_course_heading(line: str) -> bool:
    """The course name printed alone above the `Course Title:` line."""
    stripped = line.strip()

    return (
        bool(stripped)
        and ":" not in stripped
        and len(stripped) <= _MAX_HEADING_LEN
        and not _UNIT_HEADING.match(stripped)
    )


def _reflow(lines: list[str]) -> str:
    """Rebuild paragraphs and bullets from hard-wrapped PDF lines.

    A line continues the one before it unless it opens a new `N.N` sub-topic. Where
    sub-topics exist they become bullets; otherwise the whole body is one paragraph.
    """
    blocks: list[tuple[str | None, list[str]]] = []  # (subtopic number, its lines)

    for line in lines:
        stripped = line.strip()
        if not stripped:
            continue

        match = _SUBTOPIC.match(stripped)
        if match:
            blocks.append((match.group(1), [match.group(2)]))
        elif blocks:
            blocks[-1][1].append(stripped)
        else:
            blocks.append((None, [stripped]))

    if not blocks:
        return ""

    rendered: list[str] = []
    for number, parts in blocks:
        body = normalize_punctuation(" ".join(parts)).strip()
        if not body:
            continue

        if number is None:
            rendered.append(body)
            continue

        # "Compiler Structure: Analysis and ..." -> "- **Compiler Structure:** Analysis and ..."
        labelled = _SUBTOPIC_LABEL.match(body)
        if labelled:
            rendered.append(f"- **{labelled.group(1).strip()}:** {labelled.group(2).strip()}")
        else:
            rendered.append(f"- {body}")

    return "\n".join(rendered) if len(rendered) > 1 else rendered[0]


def _derive_unit_name(body: list[str]) -> str | None:
    """Name an unnamed unit after its first sub-topic ("1.1 Compiler Structure: ...")."""
    for line in body:
        match = _SUBTOPIC.match(line.strip())
        if not match:
            continue
        labelled = _SUBTOPIC_LABEL.match(match.group(2))
        if labelled:
            return normalize_punctuation(labelled.group(1).strip())

    return None


def _parse_units(lines: list[str]) -> list[SyllabusUnit]:
    headings: list[tuple[int, re.Match]] = []
    for index, line in enumerate(lines):
        match = _UNIT_HEADING.match(line.strip())
        if match:
            headings.append((index, match))

    units: list[SyllabusUnit] = []
    for position, (index, match) in enumerate(headings):
        end = headings[position + 1][0] if position + 1 < len(headings) else len(lines)

        body = []
        for line in lines[index + 1 : end]:
            if _END_OF_UNITS.match(line):
                break
            body.append(line)

        name = normalize_punctuation(match.group(2).strip()) or None
        guessed = False
        if name is None:
            name = _derive_unit_name(body)
            guessed = name is not None

        units.append(
            SyllabusUnit(
                number=int(match.group(1)),
                name=name,
                hours=int(match.group(3)),
                content=_reflow(body) or None,
                name_guessed=guessed,
            )
        )

    return units


def _parse_description(lines: list[str]) -> str | None:
    for index, line in enumerate(lines):
        match = _DESCRIPTION.match(line.strip())
        if not match:
            continue

        body = [match.group(1)] if match.group(1).strip() else []
        for following in lines[index + 1 :]:
            if _END_OF_DESCRIPTION.match(following):
                break
            body.append(following)

        return _reflow(body) or None

    return None


def render_markdown(course: Course) -> str:
    """The whole course as one markdown document, for `subjects.syllabus`."""
    heading = course.name or "Untitled course"
    if course.code:
        heading = f"{heading} ({course.code})"

    parts = [f"# {heading}"]
    if course.description:
        parts.append(course.description)

    for unit in course.units:
        title = f"## Unit {unit.number}"
        if unit.name:
            title = f"{title}: {unit.name}"
        if unit.hours is not None:
            title = f"{title} ({unit.hours} Hrs.)"
        parts.append(title)

        if unit.content:
            parts.append(unit.content)

    return "\n\n".join(parts)


def _build_course(lines: list[str], name: str | None) -> Course:
    code_match = next((_COURSE_NO.match(line.strip()) for line in lines if _COURSE_NO.match(line.strip())), None)

    course = Course(
        code=code_match.group(1) if code_match else None,
        name=name,
        description=_parse_description(lines),
        units=_parse_units(lines),
    )
    course.markdown = render_markdown(course)

    return course


def parse_courses(pages: list[PageText]) -> list[Course]:
    """Split a syllabus into its course blocks.

    A PDF with no `Course Title:` header but recognisable unit headings still yields
    one course, with `code`/`name` left null for the admin to fill in — a syllabus in
    an unfamiliar format is not a broken one, and its units are worth keeping.
    """
    lines = "\n".join(page.text for page in pages).split("\n")

    starts = [index for index, line in enumerate(lines) if _COURSE_TITLE.match(line.strip())]

    if not starts:
        units_exist = any(_UNIT_HEADING.match(line.strip()) for line in lines)

        return [_build_course(lines, name=None)] if units_exist else []

    courses: list[Course] = []
    for position, start in enumerate(starts):
        end = starts[position + 1] if position + 1 < len(starts) else len(lines)
        # The next course's standalone name heading belongs to it, not to this block.
        if end < len(lines) and _looks_like_course_heading(lines[end - 1]):
            end -= 1

        # Prefer the name printed alone above `Course Title:`; the inline one carries
        # the right-hand "Full Marks:" column glued to it.
        name = None
        if start > 0 and _looks_like_course_heading(lines[start - 1]):
            name = lines[start - 1].strip()
        else:
            inline = _COURSE_TITLE.match(lines[start].strip())
            if inline:
                name = _TITLE_TAIL.sub("", inline.group(1)).strip() or None

        courses.append(_build_course(lines[start:end], name=normalize_punctuation(name) if name else None))

    return courses
