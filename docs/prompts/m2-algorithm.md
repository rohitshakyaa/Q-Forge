# Prompt — M2: The Algorithm (centerpiece)

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 2 (The Algorithm)** of QForge — the **academic centerpiece**. Goal
in one line: **generate a valid question paper from a blueprint using a deterministic
greedy + backtracking engine, and report exactly why generation fails when the bank is too thin.**

This milestone is built **test-first (TDD)** — the unit tests are the proof of the contribution.

## Prerequisites

**M1 must be `Done`** (subjects/units/questions/blueprints exist, with a working `QForgeDemoSeeder`).
Confirm via the **Progress** table in `docs/MILESTONES.md` and by checking migrations under `code/`.

## Read first (orientation)

- `PLAN.md` — **source of truth**; read *The Algorithm (`app/Services/PaperGeneration/`)* and
  *Data Model* (papers, paper_questions). On conflict, PLAN.md wins.
- `docs/MILESTONES.md` → **M2 — The algorithm** section (scope, ER, generation sequence, acceptance).
- `docs/diagrams/m2-schema.mmd` and `docs/diagrams/seq-generate.mmd` — schema + control flow.
- `CLAUDE.md`, `docs/CONVENTIONS.md` — rules and how to run things.

## Objective

Build the pure-PHP generation engine as a self-contained, unit-testable service; persist generated
papers; and return either the complete paper with per-constraint results, or a precise "cannot
satisfy" result naming the missing slots.

## Scope / deliverables

**Backend (Laravel)**
- New tables/models: `papers`, `paper_questions` (per `PLAN.md` / `docs/diagrams/m2-schema.mmd`).
- `app/Services/PaperGeneration/` (framework-light, injectable, testable in isolation):
  - `BlueprintCompiler` — `blueprint.definition` → ordered **slots** (type, marks, allowed units,
    optional attribute prefs).
  - `CandidateFilter` — per slot: approved questions matching subject/unit/type/marks, excluding
    questions already chosen in the current paper. (Cross-paper "last N" exclusion is fully wired in
    M3; structure the filter so that rule plugs in cleanly now.)
  - `GreedySelector` — picks the best candidate per slot (unit balance, least-recently-used via
    `used_count`, optional attribute match).
  - `ConstraintValidator` — counts per group, total marks, unit-coverage rule, no repetition,
    optional attribute constraints; returns a structured pass/fail per constraint
    (feeds the frontend `ConstraintResult[]`).
  - `BacktrackingResolver` — on validation failure, reverse recent selections and try alternative
    candidates per conflicting slot until valid or exhausted.
  - `PaperGenerator` (facade) — orchestrates the above; returns a `GenerationResult` (success +
    persisted paper, or failure + `missing_slots[]` describing exactly what the bank lacks, e.g.
    "2× 10-mark, Unit 3").
- `POST /papers/generate` (teacher, owner-scoped) → body `{ blueprint_id }` → full paper +
  `constraint_results[]`, or `{ satisfiable:false, missing_slots[] }`. On success, persist `papers`
  + `paper_questions` and increment `questions.used_count`.

**Frontend wiring**
- Wire Teacher → Generate Question Paper (`papers.ts` store) through `frontend/src/api/client/axios.ts`
  to render the produced paper, the per-constraint pass/fail checklist, and the `missing_slots`
  message on an infeasible blueprint.

## Constraints & conventions

- The algorithm lives in **pure PHP in Laravel** — no external solver, deterministic, explainable.
- Laravel owns generation; no Python involved in this milestone.
- Run all commands via `sudo docker compose exec qforge_app …` (see `docs/CONVENTIONS.md`); start the
  stack with `echo 'rohitshakya' | sudo -S docker compose up -d` if it's down.

## Working method — TDD (required)

1. **Plan first.** Explore M1's models/seeder and the named docs; present a short plan/todo; confirm.
2. **Red:** write `tests/Unit/PaperGeneration/` tests against seeded/factory data covering:
   feasible blueprint → valid paper; unit-coverage enforced; repetition excluded *within* a paper;
   infeasible blueprint → correct `missing_slots`; a case where naive greedy fails but
   backtracking recovers a valid paper. Run them and watch them fail.
3. **Green:** implement the services until tests pass.
4. **Refactor** for clarity, keeping tests green. Then wire `POST /papers/generate` and the frontend.

## Acceptance / verification

- `sudo docker compose exec qforge_app php artisan test --testsuite=Unit` — all PaperGeneration tests
  pass (this is the gate).
- Feature test for `POST /papers/generate`: success and `missing_slots` paths.
- Manual UI at `http://localhost:5173`: pick a seeded blueprint → Generate → real paper with an
  all-green constraint checklist; pick an unsatisfiable blueprint → see the precise shortfall.

## Definition of done

- Unit + feature tests green; UI demo works end-to-end on seeded data.
- Update `docs/MILESTONES.md`: M2 → `Done` in the **Progress** table **and** the M2 `Status:` line.
- If the schema diverged from `docs/diagrams/m2-schema.mmd`, update the diagram and ER block.
- Summarize the algorithm design and flag follow-ups. **Do not commit** unless asked.
