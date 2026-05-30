# Prompt — M3: Papers Lifecycle + Export

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 3 (Papers Lifecycle + Export)** of QForge. Goal in one line:
**preview, save, and export generated papers (PDF/DOCX), and prove repetition control across
papers.**

## Prerequisites

**M1 and M2 must be `Done`** (generation produces and persists `papers` + `paper_questions`). Confirm
via the **Progress** table in `docs/MILESTONES.md`.

## Read first (orientation)

- `PLAN.md` — **source of truth**; see *Export* (dompdf + PhpWord) and repetition control in the
  algorithm/Data Model sections. On conflict, PLAN.md wins.
- `docs/MILESTONES.md` → **M3 — Papers lifecycle + export** (scope, ER note, acceptance).
- `docs/diagrams/m3-schema.mmd` — schema is **unchanged since M2**; M3 adds behavior + export.
- `CLAUDE.md`, `docs/CONVENTIONS.md`.

## Objective

Finish the repetition rule (exclude questions used in the last N papers), add paper history
endpoints, and export a paper to PDF and DOCX from a single shared view-model — wiring the
Paper View, Export, and History screens.

## Scope / deliverables

**Backend (Laravel)** — no new tables.
- Complete repetition control in `CandidateFilter`: exclude questions used in the **last N papers**
  for the subject (N from `blueprint.definition.exclusionRules.lastNPapers`), derived by joining the
  most recent N `papers` to their `paper_questions`. Add a regression test that a second generation
  excludes the first paper's questions.
- `apiResource papers` (owner-scoped) + `GET /papers/{id}`; paper history listing.
- Export: `GET /papers/{id}/export?format=pdf|docx`. Install `barryvdh/laravel-dompdf` and
  `phpoffice/phpword` (via `sudo docker compose exec qforge_app composer require …`). Build **one
  shared paper view-model** consumed by both a Blade template (dompdf → PDF) and the PhpWord builder
  (→ DOCX). On export, increment `export_count` and set `status=exported`.

**Frontend wiring**
- Wire Teacher → Paper View (preview), Export buttons (PDF/DOCX download), and History & Analytics
  through `frontend/src/api/client/axios.ts` (`papers.ts` store). Keep existing store shapes.

## Constraints & conventions

- Laravel owns papers and export — do **not** generate documents in Python (CLAUDE.md).
- Run all commands via `sudo docker compose exec qforge_app …` (see `docs/CONVENTIONS.md`); start the
  stack with `echo 'rohitshakya' | sudo -S docker compose up -d` if needed.

## Working method

1. **Plan first.** Inspect M2's `CandidateFilter`, `papers`/`paper_questions`, and the named frontend
   store; present a short plan/todo; confirm before editing.
2. Implement repetition rule (+ test) → history endpoints → export → frontend wiring.

## Acceptance / verification

- `sudo docker compose exec qforge_app php artisan test` — export returns valid PDF and DOCX;
  second generation from the same blueprint excludes last-N questions.
- Exported files open correctly (valid PDF and .docx).
- Manual UI at `http://localhost:5173`: Generate → Paper View → export PDF and DOCX; generate again
  and confirm prior questions are not reused; History lists past papers with status.

## Definition of done

- All acceptance checks green.
- Update `docs/MILESTONES.md`: M3 → `Done` in the **Progress** table **and** the M3 `Status:` line.
- Schema should be unchanged; if you altered any column, update `docs/diagrams/` accordingly.
- Summarize and flag follow-ups. **Do not commit** unless asked.
