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

    # Ordering matters for KV-cache reuse: everything that is identical across a
    # slot's retry rounds (role, the large syllabus block, type/marks/shape) comes
    # first, and the ONE value that changes each round — `count` — is isolated on
    # the final line. That keeps the whole prefix byte-stable between rounds, so the
    # local model can reuse the syllabus prefill instead of re-encoding it each time.
    return (
        "You are an examiner authoring questions for a university course. "
        "Use ONLY the syllabus context provided, and nothing outside it.\n\n"
        "=== SYLLABUS CONTEXT ===\n"
        f"{grounding.strip()}\n\n"
        f"Write distinct {kind} exam questions worth {marks} marks each.{extra}\n"
        'Respond with a single JSON object of the form '
        f'{{"questions": [{item_shape}, ...]}} and no prose.\n\n'
        f"Number of questions to write: {count}\n"
    )
