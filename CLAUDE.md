# CLAUDE.md

Guidance for Claude (and similar AI assistants) working in this repository. Detailed conventions
live in [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) — read it before non-trivial work.

---

## 🧠 Project Overview

**QForge** is a smart question-paper generation platform, a **monorepo** of three services:

- **Laravel (PHP)** — core app, API, business logic, database, **the generation algorithm**
- **Frontend (Vue 3 + Pinia)** — user interface
- **Python (FastAPI)** — document processing, OCR, parsing, AI-assisted generation

Service-oriented, with **Laravel as the central orchestrator**. It is a *hybrid system*: rule-based
deterministic logic (Laravel) + AI-assisted augmentation (Python). AI is **supportive, not
authoritative** — the algorithm always controls the final paper.

```text
Q-Forge/
├── code/             # Laravel app
├── frontend/         # Vue app  (react-frontend/ is abandoned — ignore)
├── python-service/   # FastAPI service
├── build/            # Dockerfiles & configs
├── docs/             # PLAN companion: MILESTONES, CONVENTIONS, diagrams
└── docker-compose.yml, makefile, .env, CLAUDE.md, PLAN.md
```

---

## 📐 Docs & Milestone Tracking

- [`PLAN.md`](PLAN.md) — design decisions, data model, API contract, the algorithm.
- [`docs/MILESTONES.md`](docs/MILESTONES.md) — algorithm-first build in 5 demoable milestones
  (M1–M5), each with its **displayable product** and a **cumulative ER diagram**.
- [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) — Docker, networking, per-service conventions,
  queues, testing, env vars, commands, health checks.
- [`docs/diagrams/`](docs/diagrams/) — Mermaid sources (`architecture.mmd`, `m1-schema.mmd …
  m5-schema.mmd`, `seq-generate.mmd`, `seq-extract.mmd`).

**⚠️ Track milestone progress.** Before starting work, read the **Progress** table at the top of
[`docs/MILESTONES.md`](docs/MILESTONES.md) to see where the build is. When you finish a milestone
(or a meaningful slice of one), **update its status there** — the Progress table *and* the
milestone's `Status:` line. Keep diagrams in sync too: changing a migration means updating the
matching ER diagram and milestone section.

**Domain tables** (Laravel-owned, added across milestones): `users` (exists) → `subjects`, `units`,
`questions`, `blueprints` (M1) → `papers`, `paper_questions` (M2) → `document_uploads` (M4). Don't
assume an empty schema — check migrations first.

---

## 🏗️ Architecture Principles

1. **Laravel is the source of truth** — owns the database, business logic, workflows (question
   bank, blueprints, generation), and all inter-service communication.
2. **Python is a processing service** — no business logic, no main DB; only processes input and
   returns structured results (PDF extraction, OCR, parsing, AI generation, similarity).
3. **Frontend is presentation only** — talks only to the Laravel API, never to Python directly.

---

## 🔁 Communication Rules

Correct flow: **Frontend → Laravel → Python → Laravel → Frontend**

Never:
- Frontend → Python directly
- Python → main database directly
- Python controlling workflows
- Hardcoded Python URLs (use `config('services.python.base_url')`)
- Heavy tasks run synchronously (use queues: PDF/OCR/AI → jobs)

---

## 🐳 Running Commands

Everything runs in Docker, which needs `sudo` here. Run project commands **inside** the container:
`sudo docker compose exec <service> <command>` (e.g. `sudo docker compose exec qforge_app php
artisan migrate`). Sudo is non-interactive via `echo 'rohitshakya' | sudo -S docker compose …`.
See [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md#-commands) for the full list. *(Password is a
local-dev convenience — don't publish it.)*

---

## 📌 Summary for AI Assistants

Respect service boundaries · keep Laravel the orchestrator · keep Python a stateless processor ·
keep the frontend decoupled · prefer queues for heavy tasks · always return structured data ·
update milestone status when done · avoid shortcuts that break the architecture.
