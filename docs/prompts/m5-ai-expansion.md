# Prompt — M5: AI Bank Expansion

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 5 (AI Bank Expansion)** of QForge. Goal in one line: **when the bank
can't satisfy a blueprint, top it up with a local LLM and regenerate — AI as a supportive component
under the algorithm's control.**

## Prerequisites

**M1–M4 must be `Done`** (generation returns `missing_slots`; Redis+Horizon and the Python service
are online). Confirm via the **Progress** table in `docs/MILESTONES.md`.

## Read first (orientation)

- `PLAN.md` — **source of truth**; see *Python Service* (`/generate-questions`, `LLMProvider`),
  *Infra* (`qforge_ollama`), and the generate→short→expand→regenerate flow. On conflict, PLAN.md wins.
- `docs/MILESTONES.md` → **M5 — AI bank expansion** (scope, ER note, acceptance).
- `docs/diagrams/m5-schema.mmd` — schema is **unchanged since M4**.
- `CLAUDE.md`, `docs/CONVENTIONS.md`.

## Objective

Add a local LLM provider in Python behind a swappable interface, and a Laravel job that generates
questions for the named missing slots, validates them, and stores them as AI-sourced bank entries so
a previously infeasible blueprint becomes satisfiable on re-generate.

## Scope / deliverables

**Infra**
- Add `qforge_ollama` service to `docker-compose.yml` (image `ollama/ollama`, a named volume for
  model weights, on the `local` network). Add `OLLAMA_URL` to the Python service env. Pick a small
  instruct model (e.g. Qwen2.5 3B/7B or Llama 3.1 8B) — note CPU-only latency is acceptable since
  this path is async and rare; prefer a 3B model if RAM is tight.

**Python service** — processing only.
- `POST /generate-questions`: input subject/unit context, target type+marks, count, optional
  syllabus/past-question snippets → returns structured candidate questions `{status, data, errors}`.
- `LLMProvider` interface with `OllamaProvider` (default, calls Ollama's REST API via httpx) **and a
  stub provider** (deterministic fake output for tests), selected by env so it's swappable without
  touching call sites.

**Backend (Laravel)** — no new domain tables.
- `POST /papers/{id}/expand-bank` (teacher) → dispatch `ExpandQuestionBank` for the paper's named
  missing slots → `{ jobId }` (poll, then re-`generate`).
- `ExpandQuestionBank` job: call Python `/generate-questions` via `PythonService`
  (`config('services.python.base_url')`), **validate** generated questions (structure, marks, type),
  store as `questions` with `source=ai` (immediately usable, flagged for optional admin audit in the
  review queue).

**Frontend wiring**
- Wire the **"Expand bank with AI"** action on the Generate screen (`papers.ts` store) through
  `frontend/src/api/client/axios.ts`: trigger expand, show job status, then re-generate.

## Constraints & conventions

- AI is **supportive, not authoritative** — the deterministic algorithm still controls the final
  paper. Python generates text; **Laravel validates and persists**.
- Flow is **Frontend → Laravel → Python → Ollama → Laravel**; the frontend never calls Python/Ollama
  directly. No hardcoded URLs (`config('services.python.base_url')`, `OLLAMA_URL`).
- Generation runs in a **queued job**, never synchronously.
- Run commands via `sudo docker compose exec <service> …` (see `docs/CONVENTIONS.md`); start the
  stack (incl. pulling the Ollama model) with `echo 'rohitshakya' | sudo -S docker compose up -d`.

## Working method

1. **Plan first.** Inspect M2's `missing_slots` shape, the `PythonService` wrapper, Horizon setup,
   and the Generate screen; present a short plan/todo; confirm before editing.
2. Add Ollama + Python endpoint (with stub) → Laravel job + endpoint → frontend wiring.
3. Include tests (see Acceptance) — use the **stub provider** so tests don't depend on Ollama.

## Acceptance / verification

- Python: `sudo docker compose exec qforge_python pytest` — `/generate-questions` with the stub
  provider returns valid structured questions.
- Laravel feature test: the expand-bank job inserts `source=ai` questions and a previously
  infeasible blueprint becomes satisfiable afterward.
- End-to-end (with `qforge_ollama` up): infeasible blueprint → "Expand bank with AI" → job runs in
  `/horizon` → re-generate succeeds, AI-sourced questions marked in the paper.

## Definition of done

- All acceptance checks green.
- Update `docs/MILESTONES.md`: M5 → `Done` in the **Progress** table **and** the M5 `Status:` line.
- Schema should be unchanged; if you altered anything, update `docs/diagrams/` accordingly.
- Summarize and flag follow-ups (e.g. final model choice). **Do not commit** unless asked.
