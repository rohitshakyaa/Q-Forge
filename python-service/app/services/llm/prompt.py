"""Prompt construction, shared so the stub and the real provider agree on the shape."""

_KIND = {"short": "short-answer", "long": "long-answer", "mcq": "multiple-choice"}


def build_prompt(
    *, grounding: str, type: str, marks: int, count: int, units: list[str] | None = None
) -> str:
    kind = _KIND.get(type, type)
    units = units or []

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

    # Unit targeting: one unit pins the topic; two units demand a genuinely
    # integrative question (the caller uses this to close a coverage gap where
    # the paper has fewer slots than units to cover).
    if len(units) == 1:
        extra += f' Every question must be about the unit "{units[0]}".'
    elif len(units) >= 2:
        extra += (
            f' Every question must genuinely integrate material from BOTH units: "{units[0]}" '
            f'and "{units[1]}". A question answerable using only one of them is invalid.'
        )

    return (
        "You are an examiner authoring questions for a university course. Using ONLY the "
        "syllabus context provided, and nothing outside it, write "
        f"{count} distinct {kind} exam questions worth {marks} marks each.{extra}\n"
        'Respond with a single JSON object of the form '
        f'{{"questions": [{item_shape}, ...]}} and no prose.\n\n'
        "=== SYLLABUS CONTEXT ===\n"
        f"{grounding.strip()}\n"
    )
