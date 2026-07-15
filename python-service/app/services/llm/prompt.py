"""Prompt construction, shared so the stub and the real provider agree on the shape."""

_KIND = {"short": "short-answer", "long": "long-answer", "mcq": "multiple-choice"}


def build_prompt(*, grounding: str, type: str, marks: int, count: int) -> str:
    kind = _KIND.get(type, type)

    if type == "mcq":
        item_shape = (
            f'{{"text": "...", "type": "{type}", "marks": {marks}, '
            '"options": ["...", "...", "...", "..."], "answer": "..."}'
        )
        extra = (
            " Each question must have exactly four options and name the correct answer."
        )
    else:
        item_shape = f'{{"text": "...", "type": "{type}", "marks": {marks}}}'
        extra = ""

    return (
        "You are an examiner authoring questions for a university course. Using ONLY the "
        "syllabus context provided, and nothing outside it, write "
        f"{count} distinct {kind} exam questions worth {marks} marks each.{extra}\n"
        'Respond with a single JSON object of the form '
        f'{{"questions": [{item_shape}, ...]}} and no prose.\n\n'
        "=== SYLLABUS CONTEXT ===\n"
        f"{grounding.strip()}\n"
    )
