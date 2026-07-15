# ADR 0002 — Subjects and units imported from a syllabus PDF

**Status:** Accepted (implemented — tracked as **M4.1** in [`../MILESTONES.md`](../MILESTONES.md))
**Date:** 2026-07-10
**Builds on:** M4 PDF pipeline (`document_uploads`, Python `/extract`, Redis/Horizon).

---

## Context

M4 accepts a syllabus upload (`document_uploads.type = syllabus`), runs it through the same
text + OCR pipeline as a past paper, and then does nothing with the result: it records
`pages`/`ocr_pages`, creates no rows, and discards the extracted text. Subjects and units are
still typed in by hand.

That hand-typing is what makes M4's review queue painful. An extracted question can only be
approved once it carries a unit, and `UnitResolver` maps a hint like `Unit 3` onto units that
already exist. With no units in the bank, every extracted question lands unassigned — as
observed with a real TU past paper, where **12 of 12** candidates needed manual unit assignment.

M5 has a second, unmet need: `PLAN.md` specifies that AI generation is grounded in syllabus
context, and nothing was persisting that context.

Measured against the real document (`B.Sc. CSIT 6th semester syllabus`, 23 pages, digital text,
no OCR required): 11 course blocks segment cleanly on `Course Title:`, and 83 of 83 unit headings
yield `(number, name, hours)` — 79 directly, and 4 (all Compiler Design) after deriving the name
from the first sub-topic, because that course prints `Unit 1: (3 hrs)` with no name at all.

## Decision

Parse the syllabus in Python, confirm it with a human, persist it in Laravel.

1. **Python `POST /extract` gains a `courses[]` field.** A `past_paper` fills `candidates[]`;
   a `syllabus` fills `courses[]`. One endpoint, one job, one PDF/OCR code path — so a *scanned*
   syllabus gets the OCR fallback for free.
2. **The proposal is staged in `document_uploads.meta.courses`,** an existing nullable JSON
   column. No new table: a proposed unit is not independently reviewable, it only means something
   as part of a whole subject.
3. **An admin confirms it** at `/admin/syllabus/:uploadId` before anything is written. A PDF with
   several courses shows a picker; one import creates one subject.
4. **`POST /uploads/{id}/import` writes subject + units in one transaction.** Never a half-built
   subject.
5. **Bodies are stored as markdown, not raw text.** `subjects.syllabus` holds the whole course
   document; a new `units.content` column holds each unit's own body.
6. **A new `units.hours` column** records the teaching hours the syllabus prints.

### The constraint that shaped everything else

`questions.unit_id → units` is `ON DELETE CASCADE`. **Deleting a unit silently deletes every
question in it.** An import therefore never deletes a unit — and, by extension, never renames,
reorders or rewrites one either. Re-importing a syllabus can only *add the units that are
missing*, matched on a normalized name (case-, punctuation- and whitespace-insensitive).

That single rule makes re-import idempotent and safe, which is why it is allowed rather than
locked behind a "already imported" 409.

### Why per-unit content, not just a subject-level blob

M5 invokes the LLM only when the deterministic engine reports a shortfall, and that shortfall
already names a unit (`missing_slots: [{unit: "JDBC", …}]`). Retrieval is therefore *per unit*.
Storing the body on `units.content` makes the grounding context a primary-key lookup —
`Unit::find($id)->content` — with no chunking, no embedding model and no vector store required
for a first cut. A subject-level blob would have forced M5 to split the document back apart by
heading, re-deriving structure the parser already had.

## Consequences

**Good.** Past papers for a subject imported from its syllabus now tag themselves: the same paper
that produced 12 unassigned questions produces 0. Units created from a syllabus take `position` =
the printed unit number, which is exactly what `UnitResolver` matches `Unit 3` against. M5 gets
its corpus as a side effect, in a form it can use directly.

**Accepted costs.**
- Re-import does not refresh an existing unit's `content`, so units created before this change
  keep `content = null` until a backfill command exists. This follows from "never mutate an
  existing unit" and is deliberate.
- Semester, credit hours and full/pass marks are parsed away and not stored — nothing consumes
  them, and unused columns rot.
- The markdown is rendered from the *parsed structure*, so lab work, textbook lists and course
  objectives do not reach `subjects.syllabus`. If M5 wants them, widen the parser rather than
  falling back to raw text: the raw lines are hard-wrapped mid-sentence and would poison a
  chunker.
- Unit names the syllabus omits are guessed from the first sub-topic and flagged `name_guessed`
  in the UI. Approval is blocked while any name is blank, so a guess is always a human's to accept.

## Alternatives rejected

- **A separate `POST /parse-syllabus` endpoint.** Cleaner types, but duplicates path resolution
  and the OCR call site, and gives two things to keep in sync.
- **A `syllabus_candidates` review table** mirroring the M4 question queue. Two tables and a
  status machine for data that lives for exactly one screen.
- **"Re-import replaces the units."** Faithful to the document, and destroys the question bank.
  See the cascade above.
- **Matching units by position instead of name.** Mirrors `UnitResolver`, but a subject with five
  unrelated units would report every syllabus unit 1–5 as "already existing" and add nothing.
