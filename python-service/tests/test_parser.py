import pytest

from app.services.parser import (
    classify_type,
    find_marks,
    is_noise,
    parse_pages,
    section_default_from_line,
    unit_hint_from_line,
)
from app.services.pdf import PageText


def page(text: str, number: int = 1, ocr: bool = False) -> PageText:
    return PageText(number=number, text=text, ocr=ocr)


class TestFindMarks:
    @pytest.mark.parametrize(
        ("block", "expected"),
        [
            ("Define a hash collision. [5]", 5),
            ("Explain BFS vs DFS. (10 marks)", 10),
            ("Derive the recurrence. [12 Marks]", 12),
            ("State the theorem. 4 marks", 4),
            ("a) Sort it. [5]\nb) Search it. [5]", 10),
            ("No marks here at all.", None),
        ],
    )
    def test_reads_signposted_marks(self, block, expected):
        assert find_marks(block) == expected

    @pytest.mark.parametrize(
        ("block", "expected"),
        [
            # Forms taken verbatim from the TU B.Sc. CSIT model question papers.
            ("Explain the ER model in detail. What is aggregation? [8+2]", 10),
            ("What is distributed database? Explain fragmentation. [2+8]", 10),
            ("Why optimize queries? Compare the approaches. [3+7]", 10),
            ("Explain multimedia database in brief. [2.5+2.5]", 5),
            ("Write short notes on: [2 x 2.5=5]", 5),
            ("Compare the two approaches. [5+5]", 10),
        ],
    )
    def test_reads_compound_marks_from_real_papers(self, block, expected):
        assert find_marks(block) == expected

    def test_ignores_bare_parenthesised_numbers(self):
        # "(5)" without the word "marks" is a list marker or a citation, not a score.
        assert find_marks("Compare the two approaches (5)") is None

    @pytest.mark.parametrize("block", ["See [Fig. 2] for the schema.", "As shown in [i] above."])
    def test_ignores_non_arithmetic_brackets(self, block):
        assert find_marks(block) is None

    def test_rejects_implausible_totals(self):
        assert find_marks("[80 marks] [90 marks]") is None

    @pytest.mark.parametrize(
        ("block", "expected"),
        [
            # The TU 2079 CSC410 paper prints wordless parens at the line end.
            ("Discuss two drawbacks of Apriori. Find the itemsets. (2+8)", 10),
            ("Why are OLAP operations used? Discuss each. (1+9)", 10),
        ],
    )
    def test_reads_compound_trailing_parens(self, block, expected):
        assert find_marks(block) == expected

    def test_compound_paren_mid_text_is_not_marks(self):
        assert find_marks("Solve (2+8) using the identity given.") is None

    def test_bare_trailing_paren_needs_the_section_default_to_vouch(self):
        block = "Discuss different types of attributes. (5)"
        assert find_marks(block) is None
        assert find_marks(block, default=5) == 5
        # A mismatched value stays untrusted — likely a list marker after all.
        assert find_marks(block, default=10) is None

    def test_the_default_alone_is_not_a_token(self):
        # `find_marks` reads printed marks; falling back to the default is the
        # caller's decision, made in `_flush`.
        assert find_marks("Define management in brief.", default=10) is None

    def test_ocr_confused_digits_are_folded(self):
        # "[I + 4]" occurs verbatim in the scanned TU 2078 CSc410 paper.
        assert find_marks("Define data discretization. [I + 4]") == 5

    def test_folding_requires_a_real_digit(self):
        # A roman-numeral citation must not become arithmetic.
        assert find_marks("As shown in [ii] above.") is None


class TestSectionDefault:
    @pytest.mark.parametrize(
        ("line", "expected"),
        [
            # Verbatim shapes from the TU MGT411/CSC410 papers, 2078-2083.
            ("Attempt any Two questions: [3x10=30]", 10),
            ("Attempt any THREE Questions. (3 x 10 = 30)", 10),
            ("Attempt any TEN Questions. (10 x 5 = 50)", 5),
            ("Attempt any EIGHT questions . [8x 5 = 40]", 5),
            ("Attempt any TWO questions. [2 × 10 = 20]", 10),
            ("Attempt any eight questions. [8x5:40]", 5),
            ("Attempt all questions [5 x 4]", 4),
        ],
    )
    def test_reads_the_per_question_value(self, line, expected):
        assert section_default_from_line(line) == expected

    def test_a_directive_without_an_expression_sets_nothing(self):
        assert section_default_from_line("Attempt any TWO questions") is None

    def test_a_total_that_disagrees_is_ocr_damage(self):
        assert section_default_from_line("Attempt any TWO questions. [2 x 10 = 80]") is None

    def test_an_expression_off_a_directive_line_sets_nothing(self):
        assert section_default_from_line("1. Write short notes on: [2 x 2.5=5]") is None


class TestClassifyType:
    def test_option_lines_make_an_mcq(self):
        block = "Which sort is stable?\n(a) Quicksort\n(b) Merge sort\n(c) Heapsort"
        assert classify_type(block, None) == "mcq"

    def test_two_options_are_not_enough(self):
        assert classify_type("Name it.\n(a) Foo\n(b) Bar", None) == "short"

    def test_high_marks_make_it_long(self):
        assert classify_type("Sort an array.", 10) == "long"

    def test_essay_verb_makes_it_long(self):
        assert classify_type("Discuss the CAP theorem.", 3) == "long"

    def test_otherwise_short(self):
        assert classify_type("Define a B-tree.", 5) == "short"

    def test_mcq_wins_over_high_marks(self):
        block = "Pick one.\n(a) Foo\n(b) Bar\n(c) Baz"
        assert classify_type(block, 10) == "mcq"


class TestNoiseAndHints:
    @pytest.mark.parametrize(
        "line",
        [
            "Tribhuvan University",
            "Institute of Engineering",
            "Full Marks: 60",
            "Time: 3 hrs",
            "Attempt all questions.",
            "Candidates are required to give their answers in their own words.",
            "Page 2 of 4",
            "12",
            "-----",
            # Repeating headers from the TU model question papers. These sit at the
            # top of every page, so if they are not noise they get appended to
            # whichever question was still open when the page turned.
            "Model Question, 2079",
            "Institute of Science and Technology",
            "Bachelor Level/ Fourth Year/ Eighth Semester/Science Full Marks: 60",
            "Computer Science and Information Technology (CSC461) Pass Marks: 24",
            "(Advanced Database) Time: 3 hours",
            "Attempt any two questions. (2 × 10 = 20)",
        ],
    )
    def test_rubric_and_letterhead_are_noise(self, line):
        assert is_noise(line)

    @pytest.mark.parametrize(
        "line", ["1. Define a hash collision.", "Explain the difference between BFS and DFS."]
    )
    def test_questions_are_not_noise(self, line):
        assert not is_noise(line)

    @pytest.mark.parametrize(
        ("line", "expected"),
        [
            ("Unit 1", "Unit 1"),
            ("Unit-III", "Unit-III"),
            ("Group B", "Group B"),
            ("Section A:", "Section A"),
        ],
    )
    def test_headings_yield_unit_hints(self, line, expected):
        assert unit_hint_from_line(line) == expected

    def test_inline_unit_mention_is_not_a_heading(self):
        assert unit_hint_from_line("1. Unit 3 covers graphs; explain why.") is None

    def test_a_fused_directive_still_yields_the_heading(self):
        # The e-commerce 2075/2076 papers glue the directive to the heading.
        assert unit_hint_from_line("Section B [6x5=30]") == "Section B"
        assert unit_hint_from_line("Section A (10x3=30)") == "Section A"

    def test_a_question_starting_with_a_section_word_is_not_a_heading(self):
        assert unit_hint_from_line("Section A of the act defines liability.") is None


class TestParsePages:
    @pytest.fixture
    def candidates(self):
        text = "\n".join(
            [
                "Tribhuvan University",
                "Full Marks: 60",
                "Unit 1",
                "1. Define a hash collision. [5]",
                "2. Explain the difference between BFS and DFS. (10 marks)",
                "Unit 2",
                "3. Which of the following is a stable sort?",
                "(a) Quicksort",
                "(b) Merge sort",
                "(c) Heapsort",
                "4. List two uses of a B-tree. [5]",
            ]
        )
        return parse_pages([page(text)])

    def test_splits_on_top_level_numbering_only(self, candidates):
        assert [c.number for c in candidates] == ["1", "2", "3", "4"]

    def test_mcq_options_stay_with_their_question(self, candidates):
        mcq = candidates[2]
        assert mcq.type == "mcq"
        assert "Quicksort" in mcq.text and "Heapsort" in mcq.text

    def test_unit_heading_carries_to_following_questions(self, candidates):
        assert [c.unit_hint for c in candidates] == ["Unit 1", "Unit 1", "Unit 2", "Unit 2"]

    def test_marks_are_detected_and_stripped_from_text(self, candidates):
        assert [c.marks for c in candidates] == [5, 10, None, 5]
        assert "[5]" not in candidates[0].text
        assert "10 marks" not in candidates[1].text.lower()

    def test_letterhead_is_dropped(self, candidates):
        assert all("Tribhuvan" not in c.text for c in candidates)

    def test_types_are_classified(self, candidates):
        assert [c.type for c in candidates] == ["short", "long", "mcq", "short"]

    def test_page_and_ocr_provenance_is_recorded(self):
        result = parse_pages([page("1. Define a term. [5]", number=3, ocr=True)])
        assert result[0].page == 3
        assert result[0].ocr is True

    def test_preamble_before_the_first_question_is_dropped(self):
        assert parse_pages([page("Some stray introduction text.")]) == []

    def test_a_doubled_numbering_dot_does_not_leak_into_the_text(self):
        # "9.." occurs verbatim in the TU model question paper.
        result = parse_pages([page("9.. Explain document-based NOSQL systems. [5]")])
        assert result[0].text == "Explain document-based NOSQL systems."

    def test_a_repeating_page_header_does_not_join_the_previous_question(self):
        pages = [
            page("12. Write short notes on:\na. Indexing", 1),
            page("Model Question, 2079\nTribhuvan University\n1. What is SLAAC? [5]", 2),
        ]
        first, second = parse_pages(pages)
        assert "Model Question" not in first.text
        assert first.text.endswith("a. Indexing")
        assert second.number == "1"

    def test_questions_continue_across_lines(self):
        result = parse_pages([page("1. Explain BFS\nand also DFS, with analysis. [10]")])
        assert len(result) == 1
        assert "and also DFS" in result[0].text

    def test_a_question_spanning_pages_is_not_split(self):
        result = parse_pages([page("1. Explain BFS", 1), page("in detail with analysis.", 2)])
        assert len(result) == 1
        assert result[0].page == 1

    def test_markdown_table_rows_are_content_not_noise(self):
        # pdf.py renders ruled tables as markdown; the all-punctuation separator
        # row must not be eaten by the \W+ noise rule.
        assert is_noise("|---|---|---|") is False
        assert is_noise("| A | 5 |  | 200 |") is False

        text = "\n".join(
            [
                "1. Perform Earned Value Analysis. [10]",
                "| Activity | Duration |",
                "|---|---|",
                "| A | 5 |",
                "Calculate SV and CPI.",
            ]
        )
        result = parse_pages([page(text)])
        assert len(result) == 1
        assert "|---|---|" in result[0].text
        assert "| A | 5 |" in result[0].text

    def test_a_number_alone_on_its_own_line_keeps_its_question(self):
        # pdfplumber renders many TU papers this way: "5." on one line, the text
        # below it. The text must attach to the open question, not be dropped.
        text = "\n".join(
            [
                "1.",
                "State and explain the emerging challenges for management. [10]",
                "2.",
                "Define business environment and explain its basic components. [10]",
            ]
        )
        first, second = parse_pages([page(text)])
        assert first.number == "1"
        assert "emerging challenges" in first.text
        assert second.number == "2"
        assert "business environment" in second.text


class TestOcrNumbering:
    """Tesseract misreads the numbering itself on scanned pages."""

    @pytest.mark.parametrize(
        ("line", "number"),
        [
            ("l, Introduce organizational goal. Describe goal formulation.", "1"),
            ("I. Perform Earned Value Analysis of the given project data.", "1"),
            ("|. Define organizational goals. Explain the formulation process.", "1"),
            ("1 1. Explain the interpersonal and nonverbal communication styles.", "11"),
        ],
    )
    def test_misread_numbers_start_questions_on_ocr_pages(self, line, number):
        result = parse_pages([page(line, ocr=True)])
        assert len(result) == 1
        assert result[0].number == number

    def test_misread_numbers_are_not_accepted_on_digital_pages(self):
        result = parse_pages([page("l, Introduce organizational goal and describe it.", ocr=False)])
        assert result == []

    @pytest.mark.parametrize(
        ("line", "number"),
        [
            # Pen ticks in the margin leave a stray glyph before an intact
            # number (the SE 2076 scan): the number and terminator must both
            # still be present, only the junk in front is forgiven.
            (".4, Briefly explain functional and non-functional requirements.", "4"),
            ("- 12. Write short notes on reliability validation.", "12"),
        ],
    )
    def test_a_stray_glyph_before_the_number_is_ignored_on_ocr_pages(self, line, number):
        result = parse_pages([page(line, ocr=True)])
        assert len(result) == 1
        assert result[0].number == number

    def test_the_first_question_may_lack_a_terminator_on_ocr_pages(self):
        # The NCC 2076 paper prints "1 Answer the following..." — no dot at all.
        text = "\n".join(
            [
                "Attempt all questions.",
                "1 Answer the following questions in short.",
                "a) ASP requirements",
                "2. a) Differentiate between ASP and IIS. [5]",
            ]
        )
        first, second = parse_pages([page(text, ocr=True)])
        assert first.number == "1"
        assert first.text.startswith("Answer the following")
        assert second.number == "2"

    def test_a_bare_number_mid_paper_does_not_split_a_question(self):
        # Only the first question gets the no-terminator allowance. Mid-paper a
        # bare "8 What..." (its dot lost to a pen mark) stays with the open
        # question rather than risking splits on prose like "5 GB of RAM".
        text = "\n".join(
            [
                "7. What are the activities of architectural design process?",
                "8 What is modular decomposition? Discuss its models.",
            ]
        )
        result = parse_pages([page(text, ocr=True)])
        assert len(result) == 1

    def test_a_cash_flow_row_does_not_start_a_question(self):
        # "40,000" must not become question 40: comma + digit is a thousands
        # separator, not a numbering terminator.
        text = "\n".join(
            [
                "4. Which project would you choose from the following and why?",
                "40,000 for project A in year one",
            ]
        )
        result = parse_pages([page(text, ocr=True)])
        assert len(result) == 1
        assert result[0].number == "4"
        assert "40,000 for project A" in result[0].text


class TestSectionDefaultMarks:
    """Papers that print marks only on the section directive (the TU MGT411 set)."""

    @pytest.fixture
    def candidates(self):
        text = "\n".join(
            [
                "Tribhuvan University",
                "Section A",
                "Attempt any THREE Questions. (3 x 10 = 30)",
                "1. Define management. Describe the functions of management.",
                "2. Introduce planning. Describe the process of planning.",
                "Section B",
                "Attempt any TEN Questions. (10 x 5 = 50)",
                "5. State and explain the problems of goal formulation.",
                "6. Distinguish centralization from decentralization. [2+3]",
            ]
        )
        return parse_pages([page(text)])

    def test_directive_marks_fill_unmarked_questions(self, candidates):
        assert [c.marks for c in candidates] == [10, 10, 5, 5]

    def test_directive_marks_drive_type_classification(self, candidates):
        # A 10-mark question is an essay even without an essay verb.
        assert candidates[0].type == "long"
        assert candidates[2].type == "short"

    def test_printed_marks_beat_the_default(self):
        text = "\n".join(
            [
                "Section A",
                "Attempt any TWO questions. [2 x 10 = 20]",
                "1. Compare the two approaches in depth. [4+6]",
            ]
        )
        assert parse_pages([page(text)])[0].marks == 10

    def test_a_new_section_heading_voids_the_default(self):
        text = "\n".join(
            [
                "Section A",
                "Attempt any TWO questions. [2 x 10 = 20]",
                "1. Define management and its functions in detail.",
                "Section B",  # its own directive was lost to OCR
                "5. State and explain the problems of goal formulation.",
            ]
        )
        first, second = parse_pages([page(text)])
        assert first.marks == 10
        assert second.marks is None

    def test_a_fused_heading_supplies_the_default_and_ends_the_question(self):
        # "Section B [6x5=30]" must act as heading + directive. Before this was
        # understood, the line fell into the open question above it, where its
        # bracket was summed into that question's marks (10 became 40 on the
        # e-commerce 2075 paper).
        text = "\n".join(
            [
                "Section A",
                "3. What is a structured document? Discuss its parts. [3+7]",
                "Section B [6x5=30]",
                "4. How e-cash works? List its properties.",
            ]
        )
        third, fourth = parse_pages([page(text)])
        assert third.marks == 10
        assert "6x5" not in third.text
        assert fourth.unit_hint == "Section B"
        assert fourth.marks == 5

    def test_directive_marks_on_the_following_line_still_set_the_default(self):
        # The SE 2076 scan and the born-digital SE 2079 paper both push the
        # expression onto its own line below the directive.
        text = "\n".join(
            [
                "Attempt any Ten questions.",
                "(10x6=60)",
                "1. Explain system modeling with suitable example.",
            ]
        )
        assert parse_pages([page(text)])[0].marks == 6

    def test_a_standalone_expression_without_a_directive_sets_nothing(self):
        # A "[2 x 2.5=5]" on its own line belongs to the question above it,
        # not to the section — only a directive line arms the carry-over.
        text = "\n".join(
            [
                "1. Write short notes on:",
                "[2 x 2.5=5]",
                "2. Define management briefly and precisely.",
            ]
        )
        first, second = parse_pages([page(text)])
        assert first.marks == 5
        assert second.marks is None

    def test_trailing_paren_marks_are_detected_and_stripped(self):
        text = "\n".join(
            [
                "Section B",
                "Attempt any EIGHT questions [8x 5 = 40]",
                "5. Discuss different types of attributes with examples. (5)",
                "6. Why is normalization important? Explain both approaches. (1+4)",
            ]
        )
        five, six = parse_pages([page(text)])
        assert (five.marks, six.marks) == (5, 5)
        assert "(5)" not in five.text
        assert "(1+4)" not in six.text
