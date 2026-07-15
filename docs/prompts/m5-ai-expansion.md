# Prompt — M5: AI Bank Expansion

> Paste this into a fresh Claude Code session at the repo root (`/var/www/Q-Forge`).

You are implementing **Milestone 5 (AI Bank Expansion)** of QForge. Goal in one line: **when the bank
can't satisfy a blueprint, top it up with a local LLM and regenerate — AI as a supportive component
under the algorithm's control.**

## Prerequisites

**M1–M4.1 must be `Done`** (generation returns `missing_slots`; Redis+Horizon and the Python service
are online; syllabus/units importable). Confirm via the **Progress** table in `docs/MILESTONES.md`.

## Read first (orientation)

- `PLAN.md` — **source of truth**; see *Python Service* (`/generate-questions`, `LLMProvider`),
  *Infra* (`qforge_ollama`), and the generate→short→expand→regenerate flow.
- `docs/MILESTONES.md` → **M5 — AI bank expansion** (scope, ER note, acceptance).
- `docs/diagrams/m5-schema.mmd` — schema is **unchanged since M4**.
- `CLAUDE.md`, `docs/CONVENTIONS.md`.

## ⚠️ Reconcile two things in PLAN.md/MILESTONES before you build (do not follow them blindly)

PLAN.md and the M5 milestone were written before M2–M4 landed and contain **two stale assumptions**.
Inspect the current code first (`PaperController::generate`, `CandidateFilter`), confirm both, then
**update PLAN.md line ~71 and the M5 milestone to match** as part of this milestone:

1. **Expand-bank is keyed on the blueprint, not a paper.** An **infeasible** generate persists
   **nothing** (`PaperController::generate` returns an id-less partial payload on the `!satisfiable`
   branch) — yet that is the only path where expansion is wanted. So there is no `paper.id` to target.
   The endpoint is therefore **`POST /blueprints/{id}/expand-bank`**, not `POST /papers/{id}/…`.
2. **AI questions must be stored `status=approved` to be usable.** `CandidateFilter` selects
   `where('status','approved')`. Questions stored `status=pending` (i.e. sitting in the M4 review
   queue) are **invisible to the generator**, so the regenerate demo would silently still fail. Store
   AI questions `status=approved, source=ai`; "audit" means an admin filters `?source=ai`, **not** the
   pending review queue.

## Objective

Add a local LLM provider in Python behind a swappable interface, and a Laravel job that generates
questions for a blueprint's named missing slots, validates them, and stores them as approved
AI-sourced bank entries so a previously infeasible blueprint becomes satisfiable on re-generate.

## Scope / deliverables

**Infra**
- Add `qforge_ollama` service to `docker-compose.yml` (image `ollama/ollama`, a named volume for
  model weights, on the `local` network). Add `OLLAMA_URL` to the Python service env. Pick a small
  instruct model (e.g. Qwen2.5 3B/7B or Llama 3.1 8B) — CPU-only latency is acceptable since this
  path is async and rare; prefer a 3B model if RAM is tight.

**Python service** — processing only.
- `POST /generate-questions`: input = the **Laravel-assembled grounding block** (see *Grounding
  strategy*) + target type+marks + count → returns structured candidate questions
  `{status, data, errors}`. On partial success return the **valid subset in `data`** and the rest in
  `errors` — never discard all on one bad item. Python does **no retrieval and no DB access**; it
  receives already-assembled context text and calls the model.
- `LLMProvider` interface with `OllamaProvider` (default, calls Ollama's REST API via httpx) **and a
  stub provider** (deterministic fake output for tests), selected by env so it's swappable without
  touching call sites.

**Backend (Laravel)** — no new domain tables.
- `POST /blueprints/{id}/expand-bank` (teacher, **owner-scoped** — 403 on another owner's blueprint).
  Re-derive `missing_slots` **server-side** by running the generator on the blueprint (the infeasible
  path is side-effect-free — it persists nothing), so you don't trust client state. If the blueprint
  is now already satisfiable, return that (nothing to expand — the teacher just regenerates).
  Otherwise dispatch `ExpandQuestionBank` for the missing slots → `{ jobId }` (poll, then re-`generate`).
- `ExpandQuestionBank` job: for each missing slot call Python `/generate-questions` via `PythonService`
  (`config('services.python.base_url')`) asking for **`need + buffer`** (e.g. `need + 2`) so one
  invalid item doesn't re-trigger infeasibility. **Validate** each returned question (structure, type
  matches the slot, marks matches the slot) and **resolve the slot's unit name → `unit_id`** — reuse
  the M4 `App\Services\Extraction\UnitResolver`. A `MissingSlot.unit` is a nullable **string name**,
  and `questions.unit_id`/`marks` are nullable since M4, so an approved AI question **must** end up
  with a non-null `unit_id` (when the slot names a unit) and `marks`. Store survivors as `questions`
  with `source=ai`, `status=approved` (immediately usable; auditable via `?source=ai`).

**Grounding strategy** — the model must write *unit-correct* questions, so grounding is
**mandatory, not optional**. An ungrounded 8B model invents plausible-but-off-syllabus questions.
Laravel (not Python) assembles a grounding block **per slot**, by primary key — this is the "RAG"
here: deterministic SQL retrieval, **no embeddings, no vector store, no fine-tuning**.
- **Required context:** `units.content` (the slot's unit syllabus body, markdown — reachable by PK
  because the slot names the unit) **plus** `subjects.syllabus` (course overview). If the slot names
  a unit whose `units.content` is `null` (units predating the M4.1 backfill), fall back to
  `subjects.syllabus` alone and note it in the job log — don't send a bare slot spec.
- **Few-shot exemplars:** up to **3** existing `status=approved` questions matching the slot's
  `subject_id` + `unit_id` + `type`, passed as style/difficulty/format examples. Expect scarcity —
  the unit is thin (that's *why* you're expanding), so 0–3 is normal; **degrade gracefully to
  syllabus-only when none exist**. Never fabricate exemplars to pad the count.
- If a slot's `unit` is `null` (section-based blueprint, no unit), ground on `subjects.syllabus` +
  same-subject/type exemplars only.

**Frontend wiring**
- Wire the **"Expand bank with AI"** action on the Generate screen (`papers.ts` store) through
  `frontend/src/api/client/axios.ts`: on an infeasible result the store already holds the
  `blueprint_id` and `missing_slots` — POST to `/blueprints/{id}/expand-bank`, show job status, then
  re-generate.

## Constraints & conventions

- AI is **supportive, not authoritative** — the deterministic algorithm still controls the final
  paper. Python generates text; **Laravel validates, resolves units, and persists**.
- Flow is **Frontend → Laravel → Python → Ollama → Laravel**; the frontend never calls Python/Ollama
  directly. No hardcoded URLs (`config('services.python.base_url')`, `OLLAMA_URL`).
- Generation runs in a **queued job**, never synchronously.
- Run commands via `sudo docker compose exec <service> …` (see `docs/CONVENTIONS.md`); start the
  stack (incl. pulling the Ollama model) with `echo 'rohitshakya' | sudo -S docker compose up -d`.
- **Only build what M5 asks.** Do not add a similarity/dedup engine, a new domain table, or an admin
  approval gate for AI questions — note them as follow-ups instead (see Definition of done).

## Working method

1. **Plan first.** Inspect `MissingSlot`/`missingSlotsArray()` shape, `PaperController::generate`'s
   infeasible branch, the `PythonService` wrapper, `UnitResolver`, Horizon setup, and the Generate
   screen; present a short plan/todo; confirm before editing.
2. Reconcile PLAN.md/MILESTONES per the ⚠️ section above.
3. Add Ollama + Python endpoint (with stub) → Laravel endpoint + job → frontend wiring.
4. Include tests (see Acceptance) — use the **stub provider** so tests don't depend on Ollama.

## Acceptance / verification

- Python: `sudo docker compose exec qforge_python pytest` — `/generate-questions` with the stub
  provider returns valid structured questions; a malformed item lands in `errors`, not `data`.
- Laravel feature test: `POST /blueprints/{id}/expand-bank` dispatches the job; the job inserts
  `source=ai, status=approved` questions with resolved `unit_id`+`marks`; a previously infeasible
  blueprint becomes satisfiable on re-`generate`; another owner's blueprint → 403.
- End-to-end (with `qforge_ollama` up): infeasible blueprint → "Expand bank with AI" → job runs in
  `/horizon` → re-generate succeeds, AI-sourced questions marked in the paper.

## Definition of done

- All acceptance checks green.
- Update `docs/MILESTONES.md`: M5 → `Done` in the **Progress** table **and** the M5 `Status:` line;
  reflect the blueprint-keyed endpoint + `status=approved` decisions there and in PLAN.md.
- Schema should be unchanged; if you altered anything, update `docs/diagrams/` accordingly.
- Summarize and flag follow-ups (final model choice; AI-question similarity/dedup; optional admin
  approval gate). **Do not commit** unless asked.

---
*This prompt targets an agentic tool with real system access (edits files, runs `docker compose`,
touches the queue). Review the scope locks, the ⚠️ reconciliation section, forbidden actions, and
stop conditions before pasting. Confirm file paths and the sudo password convention match the project.*
