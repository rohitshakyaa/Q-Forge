# QForge Report — Section Writing Prompts

Paste-ready **Claude Code** prompts, one per report unit. Each drives a fresh Claude Code session to
draft that section into its `report/NN-*.md` file, following [`../GUIDE.md`](../GUIDE.md) and
[`../WRITING-QUALITY.md`](../WRITING-QUALITY.md).

## How to use
1. Start a **new Claude Code session** in the repo root (`/var/www/Q-Forge`) for each section — new
   task, new session (session hygiene).
2. Open the matching prompt file below, copy its **fenced prompt block**, paste, send.
3. Review the draft against the acceptance criteria the prompt lists, then move to the next section.

Recommended order (each later chapter can reference earlier drafts):
`03 → 04` first (analysis + design — the OO spine and the algorithm centrepiece), then
`01 → 02 → 05 → 06`, then `00 front-matter`, `07 references`, `08 appendices` last (they depend on the
body being stable).

| Prompt | Produces | Notes |
|---|---|---|
| [01-introduction.prompt.md](01-introduction.prompt.md) | `report/01-introduction.md` | Ch1 |
| [02-background.prompt.md](02-background.prompt.md) | `report/02-background.md` | Ch2 — only heavy paraphrase+cite chapter |
| [03-system-analysis.prompt.md](03-system-analysis.prompt.md) | `report/03-system-analysis.md` | Ch3 — OO |
| [04-system-design.prompt.md](04-system-design.prompt.md) | `report/04-system-design.md` | Ch4 — OO + §4.2 Algorithm (centrepiece) |
| [05-implementation-testing.prompt.md](05-implementation-testing.prompt.md) | `report/05-implementation-testing.md` | Ch5 |
| [06-conclusion.prompt.md](06-conclusion.prompt.md) | `report/06-conclusion.md` | Ch6 |
| [00-front-matter.prompt.md](00-front-matter.prompt.md) | `report/00-front-matter.md` | write after body is stable |
| [07-references.prompt.md](07-references.prompt.md) | `report/references.md` | reconcile cites → final IEEE list |
| [08-appendices.prompt.md](08-appendices.prompt.md) | `report/appendices.md` | snippets, screenshots, visit log |
| [09-build-docx.prompt.md](09-build-docx.prompt.md) | a downloadable `.docx` | **run last, different tool** — paste into **Claude Desktop / claude.ai** with the nine `.md` files attached; its file-creation builds the Word doc to the §7.1 format |

## Invariants every prompt enforces (so you don't have to)
- Read `report/GUIDE.md` (esp. §0 locked decisions, §3 content plan, §5/§5A writing+paraphrase, §7
  format) and `report/WRITING-QUALITY.md` (per-section rubric) **before** writing.
- Object-Oriented modelling throughout; **never** the structured/DFD-primary approach.
- Write in formal report voice from the first pass — **paraphrase, never paste** (repo docs → rewrite
  for register; external sources → paraphrase **and** cite `[n]`).
- Never fabricate citations (`{{CITATION NEEDED}}`) or institutional facts (`{{PLACEHOLDER}}`).
- Truthful to the build: all M1–M6 are Done — past tense, no invented features.
- These prompt files and the two guide files **never ship in the `.docx`.**

> Each prompt targets an agentic tool with real file access. It is scoped to a single
> `report/*.md` output and forbids touching code, migrations, and `docs/`. Confirm paths before sending.
