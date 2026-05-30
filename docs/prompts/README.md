# QForge — Milestone Implementation Prompts

Ready-to-use prompts for building QForge one milestone at a time. Each file is a self-sufficient
brief you hand to Claude to implement that milestone.

## How to use

1. Open a **fresh Claude Code session at the repo root** (`/var/www/Q-Forge`).
2. Paste the contents of the milestone prompt you want to build (e.g. `m1-domain-foundation.md`).
3. Let Claude **plan first** — it will read the referenced docs, explore, and propose a short
   plan/todo before editing. Approve or adjust, then let it build.
4. When it finishes it will run the acceptance checks and mark the milestone `Done` in
   [`../MILESTONES.md`](../MILESTONES.md).

## Order matters

Milestones are **sequential** — each assumes the previous ones are `Done`:

| # | Prompt | Builds |
|---|---|---|
| M1 | [`m1-domain-foundation.md`](m1-domain-foundation.md) | Domain schema + CRUD (subjects/units/questions/blueprints) |
| M2 | [`m2-algorithm.md`](m2-algorithm.md) | The constraint-based generation algorithm (centerpiece, TDD) |
| M3 | [`m3-papers-export.md`](m3-papers-export.md) | Papers lifecycle, repetition control, PDF/DOCX export |
| M4 | [`m4-pdf-pipeline.md`](m4-pdf-pipeline.md) | PDF upload → Python extract/OCR → admin review (Redis+Horizon) |
| M5 | [`m5-ai-expansion.md`](m5-ai-expansion.md) | AI bank top-up via local Ollama |

Check the **Progress** table in [`../MILESTONES.md`](../MILESTONES.md) to see where the build stands
before starting.

## What every prompt enforces

- **Read the canonical docs first** — [`../../CLAUDE.md`](../../CLAUDE.md),
  [`../../PLAN.md`](../../PLAN.md) (source of truth on design decisions), the milestone's section in
  [`../MILESTONES.md`](../MILESTONES.md), [`../CONVENTIONS.md`](../CONVENTIONS.md), and the relevant
  [`../diagrams/`](../diagrams/) files.
- **Plan-first**, then implement.
- **All commands run inside containers** via `sudo docker compose exec <service> …` (see
  CONVENTIONS.md — never on the host).
- **Verify against the acceptance criteria, then update milestone status** in MILESTONES.md.
- **Do not commit** unless you ask.
