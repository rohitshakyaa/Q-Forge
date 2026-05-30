# Prompt — M1: Domain Foundation

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 1 (Domain Foundation)** of QForge, a smart question-paper
generator. Goal in one line: **stand up the core data model and CRUD so subjects, units, questions,
and blueprints can be managed through the real frontend.**

## Prerequisites

None — this is the first milestone. Before editing, confirm the current state: check existing
migrations and models under `code/`, and read the **Progress** table in `docs/MILESTONES.md`.

## Read first (orientation)

- `CLAUDE.md` — architecture rules and how to run things.
- `PLAN.md` — **source of truth** for design decisions; see the *Data Model* and *Laravel API*
  sections. On any conflict, PLAN.md wins.
- `docs/MILESTONES.md` → the **M1 — Domain foundation** section (scope, ER diagram, acceptance).
- `docs/CONVENTIONS.md` — Docker/commands, service boundaries, env, health checks.
- `docs/diagrams/m1-schema.mmd` — the exact columns/FKs to build.

## Objective

Create the `subjects`, `units`, `questions`, and `blueprints` tables (atop the existing `users`),
their Eloquent models and relationships, role-gated CRUD APIs, and a demo seeder — then wire the
existing admin/teacher screens to the live API so no mock data remains for these screens.

## Scope / deliverables

**Backend (Laravel, `code/`)**
- Migrations + models + relationships for `subjects`, `units`, `questions`, `blueprints` exactly per
  `PLAN.md` Data Model / `docs/diagrams/m1-schema.mmd`. Notable points: `subjects.code` is unique and
  the **route key** (e.g. `CS302`); `questions` has `type`/`marks`/`difficulty?`/`source`/`status`/
  `attributes`(JSON)/`used_count` with an index on `(subject_id, unit_id, type, marks, status)`;
  `blueprints` stores a JSON `definition` (sections/unitRules/unitAllocations/exclusionRules).
- API endpoints (under `auth:sanctum`, role-gated with the existing `role:` middleware
  `EnsureUserHasRole`):
  - Admin: `apiResource subjects` (route key `code`) + nested `units`; `apiResource questions` with
    `GET /questions?subject=&unit=&type=&difficulty=&status=` filtering.
  - Teacher: `apiResource blueprints` (owner-scoped to the authenticated teacher).
- `QForgeDemoSeeder` (wired into `DatabaseSeeder`): 1–2 subjects + units + a pool of **approved**
  questions across types/marks/units, enough to exercise generation in M2.
- Move the Python URL into `config/services.php` as `'python' => ['base_url' => env('PYTHON_SERVICE_URL')]`
  and add `app/Services/PythonService.php` (thin wrapper using
  `Http::baseUrl(config('services.python.base_url'))`). Retire the ad-hoc `config('app.python_url')`
  in the `/api/test-python` route.

**Frontend wiring (`frontend/`)**
- Replace mock data with live API calls through `frontend/src/api/client/axios.ts` in the relevant
  Pinia stores (`catalog.ts`, `blueprints.ts`) so these screens work end-to-end: Admin → Subjects &
  Units, Subject Detail, Question Bank; Teacher → Blueprint Builder/Editor. Keep the existing store
  shapes/types; just swap the data source.

## Constraints & conventions

- Communication: **Frontend → Laravel** only. Laravel owns the DB and business logic.
- Never hardcode the Python URL — use `config('services.python.base_url')`.
- **Run every command inside containers**, e.g.
  `sudo docker compose exec qforge_app php artisan migrate` (sudo password and full command list in
  `docs/CONVENTIONS.md`). The stack may be stopped — start it with
  `echo 'rohitshakya' | sudo -S docker compose up -d` first.
- Reuse the existing Sanctum auth and `role:admin` / `role:teacher` middleware; do not reinvent auth.

## Working method

1. **Plan first.** Explore `code/` (existing migrations, models, routes, middleware) and the named
   frontend stores, then present a short todo/plan and confirm before editing.
2. Implement backend → seeder → frontend wiring.
3. Include tests as you go (see Acceptance).

## Acceptance / verification

- Feature tests pass for CRUD on each resource with Sanctum tokens and correct role gating (admin
  vs teacher): `sudo docker compose exec qforge_app php artisan test`.
- `sudo docker compose exec qforge_app php artisan migrate:fresh --seed` produces a usable demo
  dataset.
- Manual UI check at `http://localhost:5173`: as admin create subject `CS302` with units, add
  questions, see them in the Question Bank; as teacher build and save a blueprint (Group A 3×10,
  Group B 5×5). All data persists in MySQL, no mock data on these screens.

## Definition of done

- All acceptance checks green.
- Update `docs/MILESTONES.md`: set M1 to `Done` in the **Progress** table **and** the M1
  `Status:` line.
- If you changed the schema vs `docs/diagrams/m1-schema.mmd`, update that diagram and the M1 ER block
  to match the migrations.
- Summarize what was built and flag any follow-ups. **Do not commit** unless asked.
