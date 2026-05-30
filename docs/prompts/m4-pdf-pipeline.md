# Prompt — M4: PDF Pipeline

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 4 (PDF Pipeline)** of QForge. Goal in one line: **turn uploaded
past-paper PDFs into reviewable question candidates via the async Python service, with Redis +
Horizon online.**

## Prerequisites

**M1–M3 must be `Done`.** Confirm via the **Progress** table in `docs/MILESTONES.md`.

## Read first (orientation)

- `PLAN.md` — **source of truth**; see *Python Service*, *Infra / Config changes*, and the
  `document_uploads` table in *Data Model*. On conflict, PLAN.md wins.
- `docs/MILESTONES.md` → **M4 — PDF pipeline** (scope, ER, extraction sequence, acceptance).
- `docs/diagrams/m4-schema.mmd` and `docs/diagrams/seq-extract.mmd`.
- `CLAUDE.md`, `docs/CONVENTIONS.md` (service boundaries, Horizon, commands).

## Objective

Bring the queue online, build the Python `/extract` endpoint (digital text + OCR fallback +
heuristic parsing), and wire the Laravel upload → job → review flow so admins can approve extracted
questions into the bank.

## Scope / deliverables

**Infra (Laravel)**
- Switch `QUEUE_CONNECTION=redis`, `REDIS_HOST=qforge_redis`; install `laravel/horizon` + `predis`
  (`sudo docker compose exec qforge_app composer require …`); publish Horizon; ensure `qforge_worker`
  runs `php artisan horizon`; gate `/horizon` to admins.

**Python service (`python-service/`)** — processing only, no DB access.
- `POST /extract`: input is a file path on the shared volume (`/shared-storage`, already mounted) +
  type. Extract text with pdfplumber/pypdf; **per-page Tesseract OCR fallback** when a page yields
  little/no text; heuristic parser splits into candidate questions, detecting `marks`/`type`/`unit?`.
  Return structured JSON `{status, data, errors}` (per CLAUDE.md). Add `pydantic-settings` config.

**Backend (Laravel)**
- New table/model `document_uploads` (per `PLAN.md` / `docs/diagrams/m4-schema.mmd`).
- `POST /uploads` (admin, multipart) → store file to the shared volume, create `document_uploads`
  (`status=uploaded`), dispatch `ProcessDocumentUpload`, return `{ id, status }`.
- `GET /uploads/{id}` → status/progress for polling.
- `ProcessDocumentUpload` job: call Python `/extract` via `app/Services/PythonService.php`
  (`config('services.python.base_url')` — never hardcode), insert candidates as `questions`
  (`source=extracted`, `status=pending`), set upload `status=parsed` (or `failed` + error).
- Review endpoints: `GET /questions?status=pending`, `POST /questions/{id}/approve`,
  `POST /questions/{id}/reject` (+ bulk variants).

**Frontend wiring**
- Wire Admin → Upload and Extraction Review Queue through `frontend/src/api/client/axios.ts`
  (poll `GET /uploads/{id}`; approve/reject from the queue).

## Constraints & conventions

- Flow is **Frontend → Laravel → Python → Laravel**; the frontend never calls Python directly.
- Python is stateless: it parses and returns JSON; **Laravel persists** everything.
- Heavy work (extraction/OCR) runs **in a queued job**, never synchronously.
- Run commands via `sudo docker compose exec <service> …` — `qforge_app` for artisan/composer,
  `qforge_python` for pytest, `qforge_worker` for Horizon (see `docs/CONVENTIONS.md`). Start the
  stack with `echo 'rohitshakya' | sudo -S docker compose up -d` if down.

## Working method

1. **Plan first.** Inspect `python-service/app/`, the existing `PythonService` wrapper, queue config,
   and the named frontend screens; present a short plan/todo; confirm before editing.
2. Bring up Redis+Horizon → Python `/extract` → upload + job + review → frontend wiring.
3. Include tests (see Acceptance).

## Acceptance / verification

- Python: `sudo docker compose exec qforge_python pytest` — `/extract` on a digital PDF and a scanned
  PDF (OCR path) + parser unit tests.
- Laravel feature test: upload dispatches the job; approval flips a question's status to `approved`.
- End-to-end at `http://localhost:5173`: admin uploads a past-paper PDF → job appears/processes in
  `/horizon` → candidates show in the Review Queue → approve → questions become eligible (re-run M2
  generation to see them used).

## Definition of done

- All acceptance checks green.
- Update `docs/MILESTONES.md`: M4 → `Done` in the **Progress** table **and** the M4 `Status:` line.
- If the schema diverged from `docs/diagrams/m4-schema.mmd`, update the diagram and ER block.
- Summarize and flag follow-ups. **Do not commit** unless asked.
