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

# The TU BIT template labels the code "Course Number:" (not "Course No:"), prints it
# with an internal space ("BIT 201"), and — because the units sit in a table — pdfplumber
# glues the column header onto the first unit's heading row.
BIT_DSA = """Data Structures and Algorithms
Course Title: Data Structures and Algorithms Full Marks: 60 + 20 + 20
Course Number: BIT 201 Pass Marks: 24 + 8 + 8
Year/Part: II/I Total Hours: 45
Nature of Course: Theory (3 Hrs.) + Lab (3 Hrs.) Credit Hours: 3
Course Content:
Unit 1: Background and Concept of Data Structures (2hrs) Teaching Methodology Teaching Hour
Concepts of Data Types, Data Structure, Abstract Data Type and their uses
Unit 2: Algorithms (2hrs)
Introduction to Algorithm and their properties, time and space complexities
Laboratory Works:
Data Structure and Algorithm is highly practical oriented course.
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


class TestBitTemplate:
    """The TU BIT layout: 'Course Number:' label, a spaced code, and a table header
    glued onto Unit 1's heading row."""

    @pytest.fixture
    def course(self):
        return parse_courses([page(BIT_DSA)])[0]

    def test_the_spaced_course_number_is_read_and_despaced(self, course):
        # "Course Number: BIT 201" -> "BIT201"
        assert course.code == "BIT201"

    def test_the_title_excludes_the_glued_on_marks_column(self, course):
        assert course.name == "Data Structures and Algorithms"

    def test_unit_1_survives_the_table_header_glued_onto_its_row(self, course):
        # Without relaxing the end-of-line anchor, "Unit 1: ... (2hrs) Teaching ..."
        # fails to match and the unit is dropped entirely.
        assert [(u.number, u.name, u.hours) for u in course.units] == [
            (1, "Background and Concept of Data Structures", 2),
            (2, "Algorithms", 2),
        ]

    def test_the_glued_column_header_does_not_pollute_the_name(self, course):
        assert "Teaching" not in course.units[0].name

    def test_back_matter_is_not_swallowed_into_the_last_unit(self, course):
        assert "Laboratory Works" not in (course.units[1].content or "")


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


# The TU Faculty of Management (BIM) template, as pdfplumber reads it: no `Course Title:`
# header, many courses per PDF, a `DEPT ###:` heading with the code glued into it, hours
# given as `LH 4` (and, mid-word-glued, `LH4` / `Unit1:`), and a cover page whose code list
# and banners must not become courses. Transcribed from the real 8th-sem document.
FOM_8TH = """Course detail of
BIM (Bachelor of Information Management) 8th Semester
IT 229: IT Entrepreneurship and Supply Chain Management 3 Cr. hrs
IT 230: Economics of Information and Communication 3 Cr. hrs
2017
IT 229: IT Entrepreneurship and Supply Chain Management
Credits: 3
Lecture Hours: 48
Course Objectives
This module aims to impart entrepreneurial skill in student.
Course Description
Overview of Entrepreneurship, Business Plan for a new venture, Supply Chain
Management, Co-ordination in a Supply Chain.
Course Details
Unit 1:Overview of Entrepreneurship LH4
Entrepreneurship
Definition of Entrepreneur
Unit 2:Business Plan for a new venture LH 6
Defining the Business plan
References
Supply chain Management by Sunil Chopra (Third Edition)
IT 230: Economics of Information and Communications
Credits: 3
Lecture Hours: 48
Course Description
Managerial Economics Basic, Markets for Information Goods.
Course Details
Unit1:Managerial Economics Basic LH 3
1.1 Defining moments of economics
1.2 Technological change in a global economy
Unit 2: Markets for Information Goods LH 4
Foundations of the Information economy
References
Information Rules by Carl Shapiro (Ninth edition)
"""

# The elective layout prints "(Elective )" on the line below the code heading, and the
# 2nd-sem codeless courses give a bare title over a `BIM Nth Semester` banner over the
# `Credits:` block, number units with `N LHs` or Roman numerals, and bullet with a dash.
FOM_ELECTIVE = """IT 305: Object Oriented Database Management System
(Elective )
Credits: 3
Lecture Hours: 48
Course Details
Unit 1: Introduction LH 4
A major Change: The relational Data Model
Unit 2: Object Orientated DBMS LH 8
The Object-oriented Data Model
"""

FOM_2ND = """Course detail of
BIM (Bachelor of Information Management) 2nd Semester
August 2022
Business Communications
3 credits
Lecture Hours: 48
Course Objectives
The course seeks to enable students to communicate.
Course Description
This course provides the principles of effective communication.
Course Details
I. The Communication Process 8 LHs
-Basics of communication
-Theories and principles of communication
II. Business Communication 8 LHs
-What is business communication?
Digital Logic
BIM 2nd Semester
Nature of the course: Theory + Practical
Credits: 3
Lecture Hours: 48
Course Contents:
Unit 1: Binary Systems (6 Hrs.)
Number Base Conversions; Complements; Binary Codes
Unit 2: Boolean Algebra and Logic Gates (6 Hrs.)
Boolean Functions; Digital Logic Gates
Text Book:
Digital Logic and Computer Design, M. Morris Mano
"""

# A code heading with an en-dash ("ITC – 211:"), followed by a headerless section whose
# only signal that it is a separate course is that its unit numbering restarts at 1.
FOM_RESET = """ITC – 211: Computers Information System
Credits: 3
Lecture Hours: 48
Detailed Course
Unit 1: Introduction to computer System LH 13
Introduction to computer
Unit 2: Programming Language LH 6
Machine language and assembly language
Digital Logic Design
Module Objectives
Detailed Course
Unit 1: Number Systems LH 5
Decimal, Binary, Octal, Hexadecimal Number Systems
Unit 2: Digital Design Fundamentals LH 8
Logic Gates
"""

# The 1st-sem template prints unit headings with hours but no name at all ("Unit 1: LH 6").
FOM_NAMELESS = """ENG - 201 : English Composition
Module Objectives
This course aims to develop students' skill in English.
Detailed Course
Unit 1: LH 6
Orientation on varieties of English, Formal and Informal English.
Unit 2: LH 6
Intonation, Stressed and Unstressed Syllables.
"""


class TestFomCodeHeadings:
    """The BIM template: code carried in the heading, `LH` hours, many courses per PDF."""

    @pytest.fixture
    def courses(self):
        return parse_courses([page(FOM_8TH)])

    def test_the_cover_list_and_banners_do_not_become_courses(self, courses):
        # Two real courses; the cover's "IT 229:/IT 230:" list yields unit-less blocks.
        assert [c.code for c in courses] == ["IT229", "IT230"]

    def test_the_code_is_read_from_the_heading_and_the_name_follows_the_colon(self, courses):
        assert courses[0].name == "IT Entrepreneurship and Supply Chain Management"
        assert courses[1].name == "Economics of Information and Communications"

    def test_lh_hours_parse_with_and_without_a_space(self, courses):
        # "Unit 1:Overview of Entrepreneurship LH4" and "Unit 2:... LH 6".
        assert [(u.number, u.name, u.hours) for u in courses[0].units] == [
            (1, "Overview of Entrepreneurship", 4),
            (2, "Business Plan for a new venture", 6),
        ]

    def test_a_glued_unit_heading_is_still_read(self, courses):
        # "Unit1:Managerial Economics Basic LH 3" — pdfplumber drops the space after "Unit".
        assert courses[1].units[0].name == "Managerial Economics Basic"
        assert courses[1].units[0].hours == 3

    def test_the_colonless_description_is_read(self, courses):
        assert courses[0].description.startswith("Overview of Entrepreneurship")

    def test_references_are_not_swallowed_into_the_last_unit(self, courses):
        assert "Sunil Chopra" not in (courses[0].units[-1].content or "")
        assert "Ninth edition" not in (courses[1].units[-1].content or "")


class TestFomElective:
    def test_the_elective_line_below_the_code_does_not_orphan_the_course(self):
        # "IT 305: ..." then "(Elective )": the code heading must keep its units.
        courses = parse_courses([page(FOM_ELECTIVE)])

        assert len(courses) == 1
        assert courses[0].code == "IT305"
        assert [u.number for u in courses[0].units] == [1, 2]


class TestFomCodelessCourses:
    """No code anywhere: split on a bare title anchored by its `Credits:` block."""

    @pytest.fixture
    def courses(self):
        return parse_courses([page(FOM_2ND)])

    def test_bare_titles_split_into_named_codeless_courses(self, courses):
        assert [(c.code, c.name) for c in courses] == [
            (None, "Business Communications"),
            (None, "Digital Logic"),
        ]

    def test_the_title_wins_over_the_semester_banner_between_it_and_credits(self, courses):
        # "Digital Logic" sits above "BIM 2nd Semester" above "Credits: 3".
        assert courses[1].name == "Digital Logic"

    def test_roman_numeral_units_are_read(self, courses):
        assert [(u.number, u.name, u.hours) for u in courses[0].units] == [
            (1, "The Communication Process", 8),
            (2, "Business Communication", 8),
        ]

    def test_parenthesised_hours_still_parse_in_the_same_document(self, courses):
        assert [(u.number, u.hours) for u in courses[1].units] == [(1, 6), (2, 6)]

    def test_dash_bullets_render_as_list_items(self, courses):
        content = courses[0].units[0].content
        assert "- Basics of communication" in content
        assert "- Theories and principles of communication" in content


class TestFomUnitReset:
    @pytest.fixture
    def courses(self):
        return parse_courses([page(FOM_RESET)])

    def test_the_en_dash_code_heading_is_read(self, courses):
        assert courses[0].code == "ITC211"

    def test_a_restart_in_unit_numbering_splits_off_an_anonymous_course(self, courses):
        assert len(courses) == 2
        assert (courses[1].code, courses[1].name) == (None, None)
        assert [u.name for u in courses[1].units] == ["Number Systems", "Digital Design Fundamentals"]


class TestFomNamelessUnits:
    def test_a_heading_with_hours_but_no_name_keeps_the_code_and_leaves_units_unnamed(self):
        courses = parse_courses([page(FOM_NAMELESS)])

        assert (courses[0].code, courses[0].name) == ("ENG201", "English Composition")
        assert [(u.number, u.name, u.hours) for u in courses[0].units] == [
            (1, None, 6),
            (2, None, 6),
        ]


class TestReflowListItems:
    def test_deep_nested_and_lettered_items_flatten_to_bullets(self):
        text = (
            "Unit 1: Storage LH 5\n"
            "1.13.1 Cost of producing information\n"
            "a. Word Length\n"
            "b. Speed\n"
        )
        unit = parse_courses([page(text)])[0].units[0]
        lines = unit.content.split("\n")
        assert lines == [
            "- Cost of producing information",
            "- Word Length",
            "- Speed",
        ]


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
