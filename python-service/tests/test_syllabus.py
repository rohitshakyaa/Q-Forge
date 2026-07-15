"""Syllabus parsing, exercised against the shapes found in the real TU documents."""

import pytest

from app.services.pdf import PageText
from app.services.syllabus import normalize_punctuation, parse_courses


def page(text: str, number: int = 1) -> PageText:
    return PageText(number=number, text=text, ocr=False)


# A course as pdfplumber reads it: the two printed columns collapse onto one line,
# and the body is hard-wrapped mid-sentence at the page width.
NET_CENTRIC = """NET Centric Computing
Course Title: NET Centric Computing Full Marks: 60 + 20 + 20
Course No: CSC367 Pass Marks: 24 + 8 + 8
Nature of the Course: Theory + Lab Credit Hrs: 3
Semester: VI
Course Description:
The course covers the concepts of cross-platform web application development using
the ASP.NET Core MVC framework.
Course Objectives:
The objective of this course is to understand the theoretical foundation.
Course Contents:
Unit 1: Language Preliminaries (8 Hrs.)
Introduction to .Net framework, Compilation and execution of .Net applications, Basic
Languages constructs, use of “base” keyword, Method hiding and overriding
Unit 2: Introduction to ASP.NET (3 Hrs.)
.NET and ASP.NET frameworks: .NET, .NET Core, Mono, ASP.NET Web Forms
Laboratory works:
The laboratory work includes writing programs covering most of the concepts.
Text / Reference Books:
1. C# 8.0 and .NET Core 3.0, by Mark J. Price, 2019
"""

# Compiler Design prints its unit headings with no name at all, and one with no space
# before "hrs". The name exists only as the first sub-topic.
COMPILER = """Compiler Design and Construction
Course Title: Compiler Design and Construction Full Marks: 60 + 20 + 20
Course No: CSC365 Pass Marks: 24 + 8 + 8
Semester: VI
Unit 1: (3 hrs)
1.1 Compiler Structure: Analysis and Synthesis Model of Compilation, different
sub-phases within analysis phase
1.2 Interpreter: interpreter versus compiler
Unit 3: (4hrs)
3.1 Symbol Table Design: Function of Symbol Table, Information provided by Symbol
"""


class TestCourseHeader:
    @pytest.fixture
    def course(self):
        return parse_courses([page(NET_CENTRIC)])[0]

    def test_it_reads_the_course_code(self, course):
        assert course.code == "CSC367"

    def test_the_name_excludes_the_glued_on_marks_column(self, course):
        # "Course Title: NET Centric Computing Full Marks: 60 + 20 + 20"
        assert course.name == "NET Centric Computing"

    def test_it_reads_the_description(self, course):
        assert course.description is not None
        assert course.description.startswith("The course covers the concepts")
        # The description stops at "Course Objectives:".
        assert "theoretical foundation" not in course.description

    def test_a_hard_wrapped_description_becomes_one_paragraph(self, course):
        assert "\n" not in course.description
        assert "development using the ASP.NET" in course.description


class TestUnits:
    @pytest.fixture
    def units(self):
        return parse_courses([page(NET_CENTRIC)])[0].units

    def test_it_reads_number_name_and_hours(self, units):
        assert [(u.number, u.name, u.hours) for u in units] == [
            (1, "Language Preliminaries", 8),
            (2, "Introduction to ASP.NET", 3),
        ]

    def test_a_hard_wrapped_body_is_rejoined_into_one_paragraph(self, units):
        content = units[0].content
        assert "\n" not in content
        assert "applications, Basic Languages constructs" in content

    def test_typographic_quotes_are_normalised(self, units):
        assert '"base"' in units[0].content
        assert "“" not in units[0].content

    def test_back_matter_is_not_swallowed_into_the_last_unit(self, units):
        assert "Laboratory works" not in units[1].content
        assert "Mark J. Price" not in units[1].content

    def test_named_units_are_not_flagged_as_guessed(self, units):
        assert all(u.name_guessed is False for u in units)


class TestUnnamedUnits:
    @pytest.fixture
    def units(self):
        return parse_courses([page(COMPILER)])[0].units

    def test_the_name_is_derived_from_the_first_subtopic(self, units):
        assert units[0].name == "Compiler Structure"
        assert units[1].name == "Symbol Table Design"

    def test_derived_names_are_flagged_for_review(self, units):
        assert all(u.name_guessed for u in units)

    def test_hours_parse_without_a_space_before_hrs(self, units):
        # "Unit 3: (4hrs)"
        assert [u.hours for u in units] == [3, 4]

    def test_the_unit_number_is_taken_from_the_heading_not_the_order(self, units):
        assert [u.number for u in units] == [1, 3]

    def test_subtopics_render_as_labelled_bullets(self, units):
        lines = units[0].content.split("\n")
        assert lines[0].startswith("- **Compiler Structure:** Analysis and Synthesis")
        assert lines[1].startswith("- **Interpreter:** interpreter versus compiler")

    def test_a_wrapped_subtopic_stays_on_one_bullet(self, units):
        assert "different sub-phases within analysis phase" in units[0].content.split("\n")[0]


class TestMarkdown:
    def test_the_document_has_a_title_and_one_heading_per_unit(self):
        markdown = parse_courses([page(NET_CENTRIC)])[0].markdown

        assert markdown.startswith("# NET Centric Computing (CSC367)")
        assert "## Unit 1: Language Preliminaries (8 Hrs.)" in markdown
        assert "## Unit 2: Introduction to ASP.NET (3 Hrs.)" in markdown

    def test_the_description_appears_under_the_title(self):
        markdown = parse_courses([page(NET_CENTRIC)])[0].markdown
        assert "The course covers the concepts" in markdown.split("## Unit 1")[0]

    def test_unit_bodies_are_carried_into_the_document(self):
        markdown = parse_courses([page(COMPILER)])[0].markdown
        assert "- **Compiler Structure:**" in markdown

    def test_a_course_without_a_code_omits_the_parenthesis(self):
        course = parse_courses([page("Unit 1: Basics (2 Hrs.)\nSome topic, another topic")])[0]
        assert course.markdown.startswith("# Untitled course\n")


class TestMultipleCourses:
    def test_each_course_block_is_returned_separately(self):
        courses = parse_courses([page(NET_CENTRIC), page(COMPILER, number=2)])

        assert [c.code for c in courses] == ["CSC367", "CSC365"]
        assert [c.name for c in courses] == [
            "NET Centric Computing",
            "Compiler Design and Construction",
        ]

    def test_units_do_not_leak_between_courses(self):
        first, second = parse_courses([page(NET_CENTRIC), page(COMPILER, number=2)])

        assert len(first.units) == 2
        assert len(second.units) == 2
        assert second.units[0].name == "Compiler Structure"

    def test_the_next_courses_heading_does_not_end_up_in_the_previous_one(self):
        first, _ = parse_courses([page(NET_CENTRIC), page(COMPILER, number=2)])
        assert "Compiler Design" not in first.markdown


class TestUnfamiliarFormats:
    def test_units_without_a_course_header_yield_one_unnamed_course(self):
        text = "Some Institute\nUnit 1: Introduction (2 Hrs.)\nFirst topic, second topic\n"
        courses = parse_courses([page(text)])

        assert len(courses) == 1
        assert courses[0].code is None
        assert courses[0].name is None
        assert [u.name for u in courses[0].units] == ["Introduction"]

    def test_a_document_with_neither_header_nor_units_yields_nothing(self):
        assert parse_courses([page("Just some prose about nothing in particular.")]) == []

    def test_an_empty_document_yields_nothing(self):
        assert parse_courses([page("")]) == []


class TestNormalizePunctuation:
    @pytest.mark.parametrize(
        ("raw", "expected"),
        [
            ("“base”", '"base"'),
            ("Practitioner‟s", "Practitioner's"),
            ("a – b", "a - b"),
            ("a — b", "a - b"),
            ("wait…", "wait..."),
        ],
    )
    def test_it_folds_typographic_characters(self, raw, expected):
        assert normalize_punctuation(raw) == expected
