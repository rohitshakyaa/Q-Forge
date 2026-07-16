# QForge Backend + Python Service — Implementation Plan

## Context

The QForge frontend (`frontend/`, Vue 3 + Pinia + Tailwind) is complete but runs entirely on
mock data in local Pinia stores. The Laravel app (`code/`) has only Sanctum auth + role
middleware; no domain tables exist. The Python service (`python-service/`) is a bare FastAPI
`/health` stub. The `react-frontend/` folder is abandoned and ignored.

This plan turns the proposal ("QForge: Smart Question Paper Generator") into a buildable backend.
The **academic centerpiece is the constraint-based paper-generation algorithm** (hybrid greedy +
backtracking), so the plan is sequenced to build and prove that algorithm early, against seeded
data, before the messier PDF/AI pipeline. AI is *supportive only* — invoked rarely to top up the
bank when the deterministic algorithm cannot fill a slot.

The goal: a working, demoable, defense-ready system where Laravel owns the algorithm, data, and
workflow; Python only processes documents and generates text; the frontend talks only to Laravel.

## Locked Design Decisions

| Area | Decision |
|---|---|
| Generation algorithm | **Pure PHP in Laravel, from scratch** — slot creation, candidate filtering, greedy fill, constraint validation, backtracking. Deterministic, unit-tested, no external solver. |
| Question schema | Fixed columns (`subject_id`, `unit_id`, `type`, `marks`, `difficulty?`, `source`, `status`) + JSON `attributes` for subject-specific extras. Hard constraints: subject/unit/type/marks. Soft: difficulty + attributes + balancing. |
| PDF extraction | **Heuristic/regex parsing** (split on question numbers + marks patterns) + **mandatory admin review** before entering the bank. No LLM for extraction in v1. |
| OCR | **Digital-first**: pdfplumber/pypdf text extraction; auto-fallback to Tesseract OCR per page when little/no text is found. |
| LLM provider | **Ollama container** (`qforge_ollama`, small instruct model e.g. Qwen2.5/Llama 3.1 8B) behind a thin `LLMProvider` abstraction in Python so it's swappable. |
| Generate ↔ AI flow | **Sync generate; async AI top-up as a separate explicit step.** Generate returns a complete paper OR a "cannot satisfy" result naming missing slots; teacher clicks "Expand bank with AI" → queued job → re-run generate. |
| Queue | **Redis + Horizon** (`laravel/horizon` + `predis`), using existing `qforge_redis` + `qforge_worker` containers. |
| Blueprint storage | Core columns + JSON `definition` (sections / unitRules / unitAllocations / exclusionRules) mirroring the frontend shape. Since post-M5.1, `unitAllocations.count` is enforced as a per-unit **max** cap (unset = uncapped); its `marks` column stays display-only. |
| Unit coverage strategy | **Rule-based, not scored** (post-M5.1): greedy ranks uncovered-unit-first (then LRU, then id — deterministic); per-unit maximums are hard caps; repetition control is the last-N exclusion window; no additive scoring formula, no difficulty/frequency weights. Structural infeasibility = `units > 2×slots` (AI questions span ≤ 2 units) or all-capped `Σcaps < slots`. |
| Export | **barryvdh/laravel-dompdf** (PDF from Blade) + **phpoffice/phpword** (DOCX), rendered in Laravel from one shared paper view-model. |
| Subject identifier | Public route key is `code` (e.g. `CS302`), matching the frontend; numeric `id` internal only. |
| Milestone order | **Algorithm-first** (see Milestones). |

---

## Data Model

All tables in MySQL via Laravel migrations. Key entities:

- **subjects** — `id`, `code` (unique, route key), `name`, `description`, `syllabus` (longtext/markdown), timestamps.
- **units** — `id`, `subject_id` (FK), `name`, `position`, `hours` (int, nullable), `content` (text, nullable), timestamps. (A subject has many units.) *`hours` and `content` are filled by the syllabus import (M4.1). `content` is the unit's syllabus body as markdown — the per-unit grounding context for AI generation, reachable by primary key because a shortfall names a unit.*
- **questions** — `id`, `subject_id` (FK), `unit_id` (FK, nullable), `type` (string: `short`/`long`/`mcq`), `marks` (int, nullable), `difficulty` (nullable enum easy/medium/hard), `text` (longtext), `source` (enum `extracted`/`ai`/`manual`), `status` (enum `pending`/`approved`/`rejected`), `attributes` (JSON, nullable), `used_count` (int, denormalized for display), timestamps. Index on `(subject_id, unit_id, type, marks, status)` for fast candidate filtering. *`unit_id` and `marks` became nullable in M4: the extractor yields only a unit **hint**, and many past papers never print their marks. Approving a question requires both, and the generator selects `approved` rows only, so it never sees a null.*
- **question_unit** (pivot) — `question_id` (FK), `unit_id` (FK), unique pair. *A question may span several units (post-M5). `questions.unit_id` stays the **primary** unit — the one the question is listed under and counted against for soft allocations — and always also appears in the pivot, so a single-unit question has exactly one row. The generator filters candidates on **any-overlap** with the blueprint's allowed units, and one multi-unit question **covers every allowed unit it is tagged with**. The paper snapshot (`paper_questions.unit_id`) records the primary when allowed, otherwise the lowest-id allowed tag — a paper never labels a question with an excluded unit. Multi-unit tags are assigned by humans (review approval / manual create via `unit_ids`, a full set that must contain the primary) and — since post-M5.1 — by the AI top-up when a coverage gap needs unit-spanning questions (pair-targeted `MissingSlot.unitIds`: primary = first/scarcest, both tagged via the pivot); the Python parser keeps its single `unit_hint`.*
- **blueprints** — `id`, `owner_id` (FK users), `subject_id` (FK), `name`, `total_marks`, `duration`, `ai_assist` (bool), `definition` (JSON), `last_used_at` (nullable), timestamps.
- **papers** — `id`, `owner_id` (FK), `blueprint_id` (FK), `subject_id` (FK), `name`, `total_marks`, `duration`, `status` (enum `draft`/`saved`/`exported`), `export_count` (int), `generated_at`, timestamps.
- **paper_questions** (pivot/snapshot) — `id`, `paper_id` (FK), `question_id` (FK), `section_label`, `display_no`, `marks`, `unit_id`, `is_ai` (bool). This is the **source of truth for repetition control**: "used in last N papers" is derived by joining the most recent N `papers` for a subject to their `paper_questions`.
- **document_uploads** — `id`, `uploader_id` (FK), `subject_id` (FK nullable), `type` (`syllabus`/`past_paper`), `original_filename`, `stored_path`, `status` (`uploaded`/`processing`/`parsed`/`failed`), `error`, `meta` (JSON, e.g. exam year), timestamps. Tracks the async extraction job; extracted questions land as `questions` rows with `source=extracted`, `status=pending`.

Seeders: extend `DatabaseSeeder` with a `QForgeDemoSeeder` creating 1–2 subjects, their units, and a
pool of approved questions across types/marks/units — enough to exercise the algorithm in M2 without
the PDF pipeline.

---

## Laravel API (extends `code/routes/api.php`)

All under `auth:sanctum`. Role-gated via existing `role:` middleware (`EnsureUserHasRole`).
Matches the contract the frontend already assumes.

**Admin (`role:admin`)**
- `apiResource subjects` (route key `code`) + nested `units`
- `apiResource questions` (+ `GET /questions?subject=&unit=&type=&difficulty=&status=` filtering)
- `POST /uploads` (multipart) → stores file, dispatches `ProcessDocumentUpload` job → returns `{ id, status }`
- `GET /uploads/{id}` → status + progress for polling
- `GET /questions?status=pending` (review queue) + `POST /questions/{id}/approve` + `POST /questions/{id}/reject` (bulk variants)
- `apiResource users` (manage users & roles)

**Teacher (`role:teacher`)**
- `apiResource blueprints` (owner-scoped)
- `POST /papers/generate` → body `{ blueprint_id }` → runs algorithm synchronously → returns either the full paper + `constraint_results[]`, or `{ satisfiable:false, missing_slots:[...] }`
- `POST /blueprints/{id}/expand-bank` → re-derives the blueprint's `missing_slots` server-side (an infeasible `generate` persists nothing, so there is no paper to key on); if still infeasible, dispatches `ExpandQuestionBank` inside a `Bus::batch` (Ollama) for the named missing slots → `{ satisfiable, jobId? }`; poll via `GET /jobs/{batchId}`, then re-`generate`. AI questions are stored `status=approved` (not `pending`) so the generator's `CandidateFilter` (`where status=approved`) can actually select them; they are audited via `?source=ai`.
- `apiResource papers` (owner-scoped) + `GET /papers/{id}`
- `GET /papers/{id}/export?format=pdf|docx`

Move Python config to **`config/services.php`** per CLAUDE.md:
```php
'python' => ['base_url' => env('PYTHON_SERVICE_URL', 'http://qforge_python:8000')],
```
(retire the ad-hoc `config('app.python_url')` used in the current `/test-python` route).

---

## The Algorithm (`app/Services/PaperGeneration/`) — the centerpiece

A self-contained, framework-light service so it is unit-testable in isolation:

- `BlueprintCompiler` — deserializes `blueprint.definition` → ordered list of **slots** (each slot: type, marks, allowed units, optional attribute prefs).
- `CandidateFilter` — for a slot, queries approved questions matching subject/unit/type/marks, **excluding** questions used in the last N papers (from `paper_questions`) and questions already chosen in the current paper.
- `GreedySelector` — fills each slot picking the best candidate, prioritizing unit balance, least-recently-used (lowest `used_count`/oldest usage), and optional attribute match.
- `ConstraintValidator` — checks count per group, total marks, unit-coverage rule, no repetition (within paper + across last N), optional attribute constraints. Returns structured pass/fail per constraint (feeds the frontend `ConstraintResult[]`).
- `BacktrackingResolver` — on validation failure (esp. unit coverage / exhausted candidates), reverses recent selections and tries alternative candidates per conflicting slot until valid or candidates exhausted.
- `PaperGenerator` (facade) — orchestrates the above; returns a `GenerationResult` (success + paper draft, or failure + `missing_slots` describing exactly what the bank lacks, e.g. "2× 10-mark, Unit 3"). Each `MissingSlot` also carries a server-set nullable `unitId` (the generator already knows it) so the M5 expansion job targets an allowed `unit_id` without re-parsing a name — `UnitResolver` is for printed PDF headings, not this.

**Tests** (`tests/Unit/PaperGeneration/`): feasible blueprint → valid paper; unit-coverage enforcement; repetition exclusion across last N papers; infeasible blueprint → correct `missing_slots`; backtracking recovers a solvable-but-greedy-fails case. These tests *are* the proof of the academic contribution.

---

## Python Service (`python-service/app/`)

Structure: `main.py` (routes), `config.py` (`pydantic-settings`), `services/pdf.py`,
`services/parser.py`, `services/ocr.py`, `services/syllabus.py`, `services/llm/` (provider
abstraction), `schemas.py`.

Endpoints (all return `{status, data, errors}` per CLAUDE.md):
- `GET /health` (exists)
- `POST /extract` — input: file path on the shared volume (`/shared-storage`, already mounted) + type. Runs pdfplumber/pypdf; per-page OCR fallback via pytesseract. A `past_paper` returns `candidates[]` — the heuristic parser's question blocks with detected `marks`/`type`/`unit?`. A `syllabus` returns `courses[]` — course code/name/description plus units (`number`, `name`, `hours`, markdown `content`), each course rendered as one markdown document. **No DB access** — Laravel persists them.
- `POST /generate-questions` — input: the **Laravel-assembled grounding block** (unit + subject syllabus + ≤3 exemplars, joined by primary key — deterministic SQL retrieval, no embeddings/vector store) + target type+marks + count. Calls `LLMProvider` (Ollama) → returns `{status, data, errors}`: the valid candidate questions in `data`, malformed items in `errors` (never all-or-nothing on one bad item). No DB access, no retrieval — Laravel validates + stores.

`LLMProvider` interface with `OllamaProvider` (default) + a stub provider, selected by env, so the
model is swappable without touching call sites.

Add to `requirements.txt`: `pydantic-settings`, `httpx` (present), Ollama via HTTP (no extra client
needed — call its REST API with httpx). `pdfplumber`, `pypdf`, `pytesseract`, `Pillow` already present.

---

## Infra / Config changes

- **`docker-compose.yml`**: add `qforge_ollama` service (image `ollama/ollama`, volume for model weights, on `local` network). Add `OLLAMA_URL` env to the Python service. Ensure `qforge_worker` runs `php artisan horizon`.
- **Laravel**: `composer require laravel/horizon predis/predis barryvdh/laravel-dompdf phpoffice/phpword`; set `QUEUE_CONNECTION=redis`, `REDIS_HOST=qforge_redis`; publish Horizon config; gate `/horizon` to admins.
- **Jobs** (`app/Jobs/`): `ProcessDocumentUpload` (calls Python `/extract`, stores pending questions) and `ExpandQuestionBank` (calls Python `/generate-questions`, validates, stores). AI questions stored `source=ai, status=approved` — immediately usable (a `pending` AI question would be invisible to `CandidateFilter` and defeat the regenerate demo); audited via `?source=ai`, not the M4 pending review queue.
- **`PythonService`** (`app/Services/PythonService.php`): thin wrapper using `Http::baseUrl(config('services.python.base_url'))` — no hardcoded URLs.

---

## Milestones (algorithm-first, each independently demoable)

> See [`docs/MILESTONES.md`](docs/MILESTONES.md) for the full demoable breakdown, per-milestone
> cumulative ER diagrams, and sequence diagrams. **Displayable product** for each milestone means
> the relevant existing frontend screen is wired to the live API — its Pinia mock data replaced by
> real calls through `frontend/src/api/client/axios.ts` — not just a backend/curl demo.

- **M1 — Domain foundation.** Migrations + models + relationships for all tables; admin CRUD for subjects/units/questions; teacher CRUD for blueprints; `QForgeDemoSeeder`. Wire `config/services.php` python config + `PythonService` wrapper. *Demo:* manage subjects/units/questions/blueprints via API + frontend.
- **M2 — The algorithm (centerpiece).** `app/Services/PaperGeneration/*`, `POST /papers/generate`, `papers` + `paper_questions` persistence, `ConstraintResult` output, full unit-test suite. Built entirely on seeded questions. *Demo:* generate a valid paper from a blueprint; show constraint pass/fail; show `missing_slots` on an infeasible blueprint.
- **M3 — Papers lifecycle + export.** Repetition tracking via `paper_questions` (exclude-last-N), paper history endpoints, dompdf + PhpWord export from a shared view-model. *Demo:* generate → preview → export PDF/DOCX → second generation excludes prior questions.
- **M4 — PDF pipeline.** Redis+Horizon online; Python `/extract` (digital + OCR fallback + heuristic parser); `POST /uploads`, `ProcessDocumentUpload` job, admin review/approve/reject. *Demo:* upload a past-paper PDF → watch job in Horizon → review queue → approve into bank → use in generation.
- **M5 — AI bank expansion.** `qforge_ollama` container, Python `/generate-questions` + `LLMProvider`, `ExpandQuestionBank` job, `POST /blueprints/{id}/expand-bank`, generate→short→expand→regenerate loop. *Demo:* infeasible blueprint → AI tops up bank → regenerate succeeds.

---

## Verification

- **Unit:** `php artisan test --testsuite=Unit` — algorithm correctness (M2 is the gate).
- **Feature:** PHPUnit feature tests hitting the API with Sanctum tokens for CRUD, generate, export.
- **Python:** pytest for `/extract` (sample digital + scanned PDFs) and parser; provider stub for `/generate-questions`.
- **End-to-end (per CLAUDE.md):** `curl http://localhost:8000/health`; `http://localhost:8040/api/health`; Horizon at `/horizon`; full thread via the frontend at `http://localhost:5173` (login → seed/upload → blueprint → generate → export).
- **Containers:** `make up`; confirm `qforge_ollama` pulls its model and `qforge_worker` shows processed jobs in Horizon.
