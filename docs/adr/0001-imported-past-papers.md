# ADR 0001 — Imported past papers as a repetition source

**Status:** Accepted (design only — implementation tracked as **M3.1** in [`../MILESTONES.md`](../MILESTONES.md))
**Date:** 2026-06-01
**Supersedes / builds on:** M3 cross-paper repetition control.

---

## Context

M3 added repetition control: when generating a paper, the engine excludes every question
used in the **last N papers** for the same owner + subject (derived from `paper_questions`).
That window only ever sees papers **QForge itself generated** — so it protects against reuse
*going forward*, among papers produced inside the app.

It does **not** know about **real, historical exams**. The proposal (Obj 6, Scope 5,
Algorithm Step 3) frames repetition as *"stop any question which appeared on recent papers
from being used again"* — and to a teacher, "recent papers" means the actual last institutional
exams, not just things generated in QForge. Past-exam PDFs uploaded in M4 become ordinary
`questions` rows (`source=extracted`); once harvested, the system keeps the *questions*, not the
*paper*, so the engine will happily reuse a question that was on the real 2024 Final.

**The gap:** historical exams cannot influence repetition.

## Decision

Represent a real past exam as a first-class `papers` row (`origin=imported`) with its
`paper_questions`, and make the repetition window include imported papers **subject-wide**.
Because the exclusion already works off `paper_questions.question_id`, the engine needs only a
*query* change — no change to slot filling, validation, or backtracking.

### Locked choices (from the M3.1 grilling)

| # | Decision | Rationale |
|---|---|---|
| 1 | **Explicit `origin` enum** `('generated','imported')` (default `generated`); `papers.blueprint_id` becomes **nullable** | Self-documenting and future-proof (room for `seed`/`ai`); avoids coupling "imported" to a structural `NULL`. |
| 2 | Imported papers count **subject-wide** (any owner); generated papers stay owner-scoped | Matches the institution-level non-repetition intent; one teacher's drafts still don't block another's. |
| 3 | **Pure rolling** window — imported papers compete in the last-N by `generated_at`, no special treatment | Bounded: the bank never permanently starves; old exams age out naturally. (We explicitly rejected "always-exclude imported", which shrinks the bank forever.) |
| 4 | Manual record action accepts **existing `question_ids` AND inline new-question payloads** | A real exam usually contains questions not yet in the bank; inline creation makes the manual path actually usable before M4. |
| 5 | **`exam_date` required** → stored in `papers.generated_at` | Rolling-by-recency is only meaningful with the true exam date; a year is entered as `YYYY-01-01`. |
| 6 | **Bump `used_count`** for linked questions | A real past exam is real usage — consistent with M3's generate-persist behaviour. |
| 7 | **Admin-only** endpoint; `owner_id` = uploading admin (audit trail) | Matches the proposal (admins manage past papers); subject-wide matching is via `origin`, not `owner`. |

## Design

### Schema delta (`papers`)
```text
papers.blueprint_id   : FK NOT NULL  ->  FK NULLABLE   (imported exams have no blueprint)
papers.origin         : + ENUM('generated','imported') NOT NULL DEFAULT 'generated'
```
`status` is left as-is (`draft|saved|exported`); imported papers are recorded as `saved`.
`total_marks` = sum of linked question marks; `duration` = 0 (unknown for a historical exam).
**No change** to `paper_questions`.

> When implemented, update the M2/M3 ER diagrams in [`../diagrams/`](../diagrams/) (papers entity:
> `blueprint_id` nullable + new `origin`) per the repo convention.

### Repetition query change — the heart of it
In `app/Services/PaperGeneration/PaperGenerator.php::lastNExclusion()`:
```php
$recentPaperIds = Paper::query()
    ->where('subject_id', $blueprint->subject_id)
    ->where(fn ($q) => $q
        ->where('owner_id', $blueprint->owner_id)   // my generated papers
        ->orWhere('origin', 'imported'))            // + any imported exam (subject-wide)
    ->orderByDesc('generated_at')->orderByDesc('id')
    ->limit($compiled->lastNPapers)
    ->pluck('id');
```
Everything downstream (pulling `paper_questions.question_id`, the `whereNotIn` closure applied to
both the greedy and backtracking pools) is unchanged.

### New service
`App\Services\ImportedPaperService::record(Subject $subject, string $name, CarbonInterface $examDate, array $existingQuestionIds, array $newQuestions, User $uploader): Paper`
- Within a transaction: create any `newQuestions` (`source=extracted`, `status=approved`,
  tagged to their unit), collect all linked ids, create the `papers` row
  (`origin=imported`, `blueprint_id=null`, `generated_at=$examDate`, `status=saved`,
  `total_marks` = Σ marks, `owner_id=$uploader->id`), create `paper_questions` (section_label
  `"Imported"`, sequential `display_no`, marks/unit from each question, `is_ai=false`), and
  `increment('used_count')` for the linked questions.

### Entry points
1. **Manual (this milestone, no Python):** `POST /subjects/{subject}/past-papers` (admin)
   ```json
   { "name": "CSC409 Final 2024", "exam_date": "2024-12-15",
     "question_ids": [12, 18, 41],
     "new_questions": [{ "text": "...", "type": "long", "marks": 10, "unit_id": 5 }] }
   ```
   → `ImportedPaperService::record(...)` → `201 { paper }`.
2. **Automatic (M4):** `ProcessDocumentUpload` for a `past_paper` upload extracts questions,
   then calls the **same service** with the extracted ids + `document_uploads.meta.exam_year`.

### Out of scope (explicitly)
- Imported papers do **not** appear in the teacher History list or analytics (both stay
  owner-scoped on generated papers). A future admin "Past exams" view can surface them.
- No PDF parsing here — that is M4. M3.1 only needs *question data*, not a document.
- No "always-exclude" / pinned imported papers (rejected in decision #3).

## Consequences

**Positive**
- The repetition guarantee finally covers real exams; a generated paper can avoid the questions
  that were on the last institutional exams.
- Zero change to the generation algorithm itself — only the candidate-exclusion query and a
  data-entry path. Fully unit/feature testable without Python.
- The same service serves both the manual path and the M4 upload job.

**Negative / risks**
- **Pure rolling + drafts count (M3):** heavy draft generation can age a real exam out of the
  window. Accepted for now; if it bites, the window can later be measured over
  `origin=imported OR status IN (saved,exported)` without touching this design.
- A schema change re-opens the "M3 froze the schema" line — hence this is its own milestone
  (M3.1) with its own diagram update, not a silent edit.
- Inline question creation writes `status=approved` directly (bypasses the M4 review queue) —
  acceptable because an admin is asserting a real exam's contents.

## Test plan (when implemented)
- Generate a paper → it **excludes** questions from an `imported` past exam (headline).
- **Cross-teacher:** an imported exam recorded by admin suppresses questions for teacher A *and* B.
- Imported papers do **not** appear in a teacher's `GET /papers` history or analytics counts.
- `record(...)` with mixed existing ids + new questions creates the paper, links all, bumps
  `used_count`, and sets `generated_at = exam_date`.
- Rolling behaviour: an imported exam ages out once `lastNPapers` newer papers exist.
