import pytest

from app.services.parser import (
    classify_type,
    find_marks,
    is_noise,
    parse_pages,
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
