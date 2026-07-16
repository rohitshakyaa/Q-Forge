"""Parses a syllabus PDF into courses and their units.

Stateless, like the rest of this service: it returns structure, and Laravel decides
what to persist. The output feeds two things — the admin's confirmation page, and
(via `units.content`) the grounding context for AI question generation.

Bodies are rendered as markdown rather than passed through raw. A PDF hard-wraps
its text at the page width, mid-sentence, so the raw lines would hand a downstream
chunker fragments that end nowhere in particular.

Two document families are handled. The TU CSIT/BIT template labels each course with a
`Course Title:` / `Course No:` header, one course per file section. The TU Faculty of
Management (BIM) template has neither: it prints a `DEPT ###:` heading (or a bare title)
followed by a `Credits:` / `Lecture Hours:` block, packs many courses into one PDF, and
gives unit hours as `LH 4` / `4 LHs` rather than `(4 Hrs.)`. The `Course Title:` splitter
is kept exactly as it was — a proven path — and the FoM boundary-finder is a sibling
branch, reached only when no `Course Title:` marker exists. Everything below the split
(unit parsing, reflow, description, markdown) is shared.
"""

import re

from ..schemas import Course, SyllabusUnit
from .pdf import PageText

# "Course Title: NET Centric Computing        Full Marks: 60 + 20 + 20"
# pdfplumber collapses the two printed columns onto one line, so the trailing
# right-hand column has to be cut off the captured title.
_COURSE_TITLE = re.compile(r"^Course Title:\s*(.+)$", re.IGNORECASE)
_TITLE_TAIL = re.compile(r"\s+(?:Full|Pass)\s+Marks\s*:.*$", re.IGNORECASE)
# "Course No: CSC367", "Course Number: BIT 201", "Course Code: BIT-201". The value may
# carry an internal space ("BIT 201") and a glued right-hand column ("... Pass Marks:"),
# so capture just the code token and strip its spaces in `_build_course`.
_COURSE_NO = re.compile(
    r"^Course\s+(?:No\.?|Number|Code)\s*[:.]?\s*([A-Za-z]{2,}[\s-]*\d{2,}[A-Za-z0-9]*)",
    re.IGNORECASE,
)

# The FoM heading "IT 229: IT Entrepreneurship...", "MGT - 201 : Principles...". The
# department token is upper-case by convention, may carry an internal space or dash, and
# the code and name are glued with a colon. Kept case-sensitive so it does not fire on
# arbitrary prose; the trailing right-hand column (cover pages print "3 Cr. hrs") is left
# on the name and simply ignored — a cover-page match yields a unit-less course, dropped.
_CODE_HEADING = re.compile(r"^(?P<dept>[A-Z]{2,4})\s*-?\s*(?P<num>\d{2,4})\s*:\s*(?P<name>.*)$")

# A course's `Credits:` / `Lecture Hours:` / `Full Marks:` block — the most universal
# FoM signal, and the only thing that tells a bare title line ("Business Communications")
# apart from any other short heading. Matched anywhere in the line, not just anchored: the
# BIM template glues it mid-line ("Nature of the Course: core Credit Hours: 3(48 HRs)").
_COURSE_META = re.compile(
    r"(?:Lecture\s*Hours?|Credit\s*Hours?|Full\s*Marks|Pass\s*Marks|Credits?)\s*[:.]?\s*\d"
    r"|\b\d+\s*credits?\b",
    re.IGNORECASE,
)

# The hours a unit heading carries, in the three shapes seen across the templates:
#   "(8 Hrs.)"   parenthesised (CSIT/BIT)
#   "LH 4" / "LH4"   marker before the number (FoM 8th/1st sem; pdfplumber drops the space)
#   "4 LHs" / "8 LHs"   marker after the number (FoM 2nd sem)
_HOURS = (
    r"(?:"
    r"\(\s*(?P<h_paren>\d{1,3})\s*hrs?\.?\s*\)"
    r"|LH\s*(?P<h_pre>\d{1,3})\b"
    r"|(?P<h_post>\d{1,3})\s*LHs\b"
    r")"
)

# "Unit 1: Language Preliminaries (8 Hrs.)", "Unit 1:Overview of Entrepreneurship LH4",
# "Unit 10 Management and leadership LH 3". Colon optional (pdfplumber both drops the space
# after it and omits it entirely); hours required — they are the reliable terminator that
# stops the non-greedy name from swallowing a glued-on table column. Not anchored at the
# end, so trailing column text after the hours token is ignored rather than dropping the unit.
_UNIT_HEADING = re.compile(
    r"^Unit\s*(?P<num>\d{1,2})\s*[:.]?\s*(?P<name>.*?)\s*" + _HOURS,
    re.IGNORECASE,
)

# Best-effort: the FoM 2nd-sem courses that number units with Roman numerals and trailing
# `N LHs` ("I. The Communication Process 8 LHs"). Case-sensitive upper-case numerals and a
# required hours token keep it from matching prose. Fed into the same unit builder.
_ROMAN_HEADING = re.compile(
    r"^(?P<roman>[IVXLC]{1,7})\.\s*(?P<name>.*?)\s*(?P<h_post>\d{1,3})\s*LHs\b"
)
_ROMAN_VALUES = {"I": 1, "V": 5, "X": 10, "L": 50, "C": 100}

# "1.1 ...", "1.13.1 ...", "1.32.4.1 ..." — a sub-topic at any nesting depth.
_SUBTOPIC = re.compile(r"^(\d{1,2}(?:\.\d{1,2}){1,3})\s+(.*)$")
_SUBTOPIC_LABEL = re.compile(r"^([^:]{3,60}):\s*(.*)$", re.DOTALL)
# A lettered ("a. Word Length") or dashed ("- Memos", "-Basics") list item — rendered as a
# plain bullet. The BIM template prints these with and without a space after the marker.
_LIST_ITEM = re.compile(r"^(?:[-•▪◦‣*§]\s*|[a-z][.)]\s+)(.+)$")

# Cover-page and section-banner lines that pass the "short, colon-free" heading test but
# are not course titles — the TU front matter and the running "BIM Nth Semester" band.
_BANNER = re.compile(
    r"\b(?:semester|bim|bachelor|tribhuvan|faculty\s+of\s+management|office\s+of\s+the\s+dean"
    r"|course\s+detail|\d{4})\b",
    re.IGNORECASE,
)

_DESCRIPTION = re.compile(r"^Course\s*Description[:.]?\s*(.*)$", re.IGNORECASE)

# Headings that end the final unit's body — everything after them is back matter. `\s*`
# rather than `\s+` because pdfplumber glues the words ("TextBooks", "CourseDetails").
_END_OF_UNITS = re.compile(
    r"^\s*(?:laboratory\s*works?|lab\s*works?|practical\s*works?|practical\b"
    r"|text\s*/?\s*reference\s+books?|reference\s+books?|references?|text\s*books?"
    r"|suggested\s+readings?|recommended\s+readings?|teaching\s+methods?"
    r"|evaluation|assignments?|course\s*books?)\b.*$",
    re.IGNORECASE,
)

# Headings that end the course description.
_END_OF_DESCRIPTION = re.compile(
    r"^\s*(?:course\s*(?:objectives?|contents?|details?)|detailed\s+course|unit\s+\d)",
    re.IGNORECASE,
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
        " ": " ",
        # Wingdings/Symbol list bullets survive as private-use glyphs; drop them.
        "": "", "": "", "": "", "": "", "➢": "",
    }
)

_MAX_HEADING_LEN = 80


def normalize_punctuation(text: str) -> str:
    """Fold the typographic quotes and dashes a PDF carries into plain ASCII."""
    return text.translate(_PUNCTUATION)


def _roman_to_int(numeral: str) -> int:
    total = 0
    highest = 0
    for char in reversed(numeral.upper()):
        value = _ROMAN_VALUES.get(char, 0)
        total += -value if value < highest else value
        highest = max(highest, value)

    return total


def _hours_from_match(match: re.Match) -> int | None:
    for group in ("h_paren", "h_pre", "h_post"):
        value = match.groupdict().get(group)
        if value:
            return int(value)

    return None


def _looks_like_course_heading(line: str) -> bool:
    """A course name printed alone: a short, colon-free line that is not a unit heading."""
    stripped = line.strip()

    return (
        bool(stripped)
        and ":" not in stripped
        and len(stripped) <= _MAX_HEADING_LEN
        and not _UNIT_HEADING.match(stripped)
        and not _ROMAN_HEADING.match(stripped)
    )


def _reflow(lines: list[str]) -> str:
    """Rebuild paragraphs and bullets from hard-wrapped PDF lines.

    A line continues the one before it unless it opens a new numbered sub-topic or a
    lettered/dashed list item. Where such markers exist they become bullets; otherwise
    the whole body is one paragraph. Nesting depth is not reconstructed — everything is
    flattened to a single bullet level, which is what the AI grounding context wants.
    """
    # (marker, its lines): a dotted number for a sub-topic, "" for a plain bullet,
    # or None for running paragraph text.
    blocks: list[tuple[str | None, list[str]]] = []

    for line in lines:
        stripped = line.strip()
        if not stripped:
            continue

        subtopic = _SUBTOPIC.match(stripped)
        if subtopic:
            blocks.append((subtopic.group(1), [subtopic.group(2)]))
            continue

        item = _LIST_ITEM.match(stripped)
        if item:
            blocks.append(("", [item.group(1)]))
            continue

        if blocks:
            blocks[-1][1].append(stripped)
        else:
            blocks.append((None, [stripped]))

    rendered: list[str] = []
    for marker, parts in blocks:
        body = normalize_punctuation(" ".join(parts)).strip()
        if not body:
            continue

        if marker is None:
            rendered.append(body)
        elif marker == "":
            rendered.append(f"- {body}")
        else:
            # "Compiler Structure: Analysis and ..." -> "- **Compiler Structure:** Analysis and ..."
            labelled = _SUBTOPIC_LABEL.match(body)
            if labelled:
                rendered.append(f"- **{labelled.group(1).strip()}:** {labelled.group(2).strip()}")
            else:
                rendered.append(f"- {body}")

    if not rendered:
        return ""

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


def _iter_unit_headings(lines: list[str]) -> list[tuple[int, int, str, int | None]]:
    """Every unit heading, `Unit N` and Roman alike, as (line index, number, name, hours)."""
    headings: list[tuple[int, int, str, int | None]] = []
    for index, line in enumerate(lines):
        stripped = line.strip()

        match = _UNIT_HEADING.match(stripped)
        if match:
            headings.append((index, int(match.group("num")), match.group("name").strip(), _hours_from_match(match)))
            continue

        roman = _ROMAN_HEADING.match(stripped)
        if roman:
            headings.append((index, _roman_to_int(roman.group("roman")), roman.group("name").strip(), int(roman.group("h_post"))))

    return headings


def _parse_units(lines: list[str]) -> list[SyllabusUnit]:
    headings = _iter_unit_headings(lines)

    units: list[SyllabusUnit] = []
    for position, (index, number, raw_name, hours) in enumerate(headings):
        end = headings[position + 1][0] if position + 1 < len(headings) else len(lines)

        body = []
        for line in lines[index + 1 : end]:
            if _END_OF_UNITS.match(line):
                break
            body.append(line)

        name = normalize_punctuation(raw_name) or None
        guessed = False
        if name is None:
            name = _derive_unit_name(body)
            guessed = name is not None

        units.append(
            SyllabusUnit(
                number=number,
                name=name,
                hours=hours,
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


def _build_course(lines: list[str], name: str | None, code: str | None = None) -> Course:
    if code is None:
        code_match = next((_COURSE_NO.match(line.strip()) for line in lines if _COURSE_NO.match(line.strip())), None)
        # "BIT 201" / "BIT-201" -> "BIT201"; the code is a single token by convention.
        code = re.sub(r"[\s-]+", "", code_match.group(1)) if code_match else None

    course = Course(
        code=code,
        name=name,
        description=_parse_description(lines),
        units=_parse_units(lines),
    )
    course.markdown = render_markdown(course)

    return course


def _meta_within(lines: list[str], index: int, window: int = 3) -> bool:
    """Whether a `Credits:`/`Lecture Hours:` line sits within the next `window` non-blank lines.

    Deliberately shallow: a title sits just above its `Credits:` block, with at most a
    banner line ("BIM 2nd Semester") and a wrapped "Nature of the course:" line between
    them. A wider window would let a reference line near a page break claim the *next*
    course's `Credits:` as its own, and the cover's "Course detail of" claim the first.
    """
    seen = 0
    for line in lines[index + 1 :]:
        if not line.strip():
            continue
        if _COURSE_META.search(line):
            return True
        seen += 1
        if seen >= window:
            break

    return False


def _fom_boundaries(lines: list[str]) -> list[tuple[int, str | None, str | None]]:
    """Where each Faculty-of-Management course begins: (line index, code, name).

    Two signals. A `DEPT ###:` code heading is a boundary on its own — the strongest,
    most portable one, and the 1st-sem template prints one with no `Credits:` block below
    it. A bare title line is a boundary only when a `Credits:`/`Lecture Hours:` block
    follows within a line or two, which is what tells "Business Communications" apart from
    a stray reference line.

    Adjacent candidates with no unit heading between them are one header cluster (a title
    stacked above "BIM 2nd Semester" above "Credits: 3"); they collapse to the earliest,
    so the block opens at the title — but a code-bearing member lends its code to the
    cluster, so a title printed above its own code heading keeps the code.
    """
    idents: dict[int, tuple[str | None, str | None]] = {}
    normalized = [normalize_punctuation(line) for line in lines]

    for index, line in enumerate(normalized):
        stripped = line.strip()
        if not stripped:
            continue

        # Normalising first folds the en-dash the 1st-sem "ITC – 211:" heading uses.
        match = _CODE_HEADING.match(stripped)
        if match:
            idents[index] = (f"{match.group('dept')}{match.group('num')}", match.group("name").strip() or None)
        elif _looks_like_course_heading(stripped) and not _BANNER.search(stripped) and _meta_within(normalized, index):
            idents[index] = (None, stripped)

    boundaries: list[int] = []
    for index in sorted(idents):
        previous = boundaries[-1] if boundaries else None
        # One header cluster: a bare title stacked above its banner, or a code heading
        # with "(Elective)" printed beneath it. Two code headings never coalesce, so a
        # cover list can't merge into the first real course across a page break.
        both_code = previous is not None and idents[previous][0] is not None and idents[index][0] is not None
        if (
            previous is not None
            and not both_code
            and index - previous <= 3
            and not _iter_unit_headings(lines[previous:index])
        ):
            # Keep the earliest index; let a code-bearing member lend its code and name.
            if idents[index][0] and not idents[previous][0]:
                idents[previous] = idents[index]
            continue
        boundaries.append(index)

    return [(index, *idents[index]) for index in boundaries]


def _split_on_unit_reset(lines: list[str]) -> list[list[str]]:
    """Split a block where the unit numbering restarts — a headerless sub-course.

    The 1st-sem "Digital Logic Design" section carries neither a code heading nor a
    `Credits:` block, so it rides inside the previous course's block; its units restart at
    1, which is the only signal it is a course of its own. The leading sub-block keeps the
    course header; any later ones are anonymous for the admin to name.
    """
    cuts: list[int] = []
    previous = None
    for index, number, _name, _hours in _iter_unit_headings(lines):
        if previous is not None and number <= previous:
            cuts.append(index)
        previous = number

    if not cuts:
        return [lines]

    blocks: list[list[str]] = []
    previous = 0
    for cut in cuts:
        blocks.append(lines[previous:cut])
        previous = cut
    blocks.append(lines[previous:])

    return blocks


def parse_courses(pages: list[PageText]) -> list[Course]:
    """Split a syllabus into its course blocks.

    A PDF with no course header at all but recognisable unit headings still yields one
    course, with `code`/`name` left null for the admin to fill in — a syllabus in an
    unfamiliar format is not a broken one, and its units are worth keeping.
    """
    lines = "\n".join(page.text for page in pages).split("\n")

    starts = [index for index, line in enumerate(lines) if _COURSE_TITLE.match(line.strip())]

    # The CSIT/BIT template: split on `Course Title:` markers. Unchanged, proven path.
    if starts:
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

    # The Faculty-of-Management (BIM) template: no `Course Title:` marker, many courses
    # per PDF, split on code headings and anchored bare titles instead.
    boundaries = _fom_boundaries(lines)
    if boundaries:
        courses = []
        for position, (start, code, name) in enumerate(boundaries):
            end = boundaries[position + 1][0] if position + 1 < len(boundaries) else len(lines)

            # A headerless sub-course (unit numbering restarts) becomes its own anonymous
            # course; only the first sub-block carries this boundary's code and name.
            for offset, sub_block in enumerate(_split_on_unit_reset(lines[start:end])):
                sub_code, sub_name = (code, name) if offset == 0 else (None, None)
                course = _build_course(sub_block, name=normalize_punctuation(sub_name) if sub_name else None, code=sub_code)
                # Cover-page code lists and section banners parse as unit-less blocks; a
                # course with no units can't be imported, so don't surface it to the admin.
                if course.units:
                    courses.append(course)

        if courses:
            return courses

    units_exist = bool(_iter_unit_headings(lines))

    return [_build_course(lines, name=None)] if units_exist else []
