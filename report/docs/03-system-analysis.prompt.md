# Prompt — Chapter 3: System Analysis

Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft Chapter 3 (System Analysis) of the QForge CSC412 report into report/03-system-analysis.md, using the Object-Oriented approach, so it matches GUIDE §3 (Ch3) exactly.

## Context
Read first, in order:
- report/GUIDE.md — §0 (locked: OO throughout, never structured/DFD-primary), §3 Ch3 content plan, §4 diagram inventory, §5 writing rules, §5A paraphrasing (Ch3 mode = Original: rewrite repo docs for register, cite only a borrowed method definition).
- report/WRITING-QUALITY.md — §0 examiner lens + §6 analysis/design rubric.
- Sources for the content: PLAN.md (Laravel API section = features; Data Model; Locked Design Decisions), docs/ROLES.md, docs/MILESTONES.md (per-milestone demos + timeline for the Gantt), docs/diagrams/ (seq-generate.mmd, seq-extract.mmd, algorithm-m2.mmd exist; m6-schema.mmd for class translation), code/app/Models/ for the real domain classes.
QForge is fully built (M1–M6 Done) — write in past/present as a completed system.

## Target State
report/03-system-analysis.md containing, with the report's own heading numbers:
- 3.1.1 Requirement Analysis → Functional Requirements (prose + a use-case diagram described/referenced + use-case descriptions; actors Admin, Teacher, System/Python) and Non-Functional Requirements (determinism/seeded reproducibility, performance, security = Sanctum + role middleware, maintainability, portability = Docker).
- 3.1.2 Feasibility Analysis → Technical, Operational, Economic (self-hosted Ollama = no per-call cost), Schedule (real M1–M6 Gantt). Contextualised, not generic.
- 3.1.3 Analysis (Object-Oriented) → class diagram (analysis level), one object/instance diagram, state diagrams (Question, Paper, document_uploads), sequence diagrams (generate, extract), activity diagram (generation control flow).
For each diagram: reference it in text before it appears, number + caption it (figure caption below), and either embed a Mermaid source under report/figures/ or leave a clearly-marked `{{FIGURE: …}}` placeholder that names its docs/diagrams source. Do not invent a diagram that contradicts the code — reconcile against code/ first.

## Scope
- Write only: report/03-system-analysis.md (and new Mermaid files under report/figures/ if you create diagrams).
- Do NOT touch: any code, migrations, docs/diagrams/ canonical sources (copy, don't edit), other report/*.md, GUIDE.md, WRITING-QUALITY.md.

## Constraints
- Object-Oriented only. No DFD/ER as the primary model; the ER schema belongs in Ch4 as the persistence view.
- Right content in the right chapter: requirements + problem-level models here; solution refinement + architecture go in Ch4.
- Paraphrase repo docs into formal report prose — never paste. Keep fixed vocabulary exact (blueprint, slot, candidate, unit coverage, seed, similarity guard, top-up).
- Unknown institutional facts → {{PLACEHOLDER}}; needed-but-unverified external claim → {{CITATION NEEDED}}. Never fabricate.
- Only produce Chapter 3. Do not draft other chapters or refactor anything.

## Acceptance Criteria
- [ ] All sections 3.1.1–3.1.3 present with report heading numbers.
- [ ] Functional requirements are backed by a use-case diagram + descriptions; actors match ROLES.md.
- [ ] Every diagram is text-referenced before it appears, numbered and captioned; sources reconciled against code.
- [ ] Feasibility is contextualised to QForge (real Gantt, real cost story) — no textbook filler.
- [ ] No structured-approach artefacts; no invented features; fixed terms kept exact.
- [ ] Only report/03-system-analysis.md (+ any report/figures/*.mmd) changed.

## Stop Conditions
Stop and ask before: editing any file under code/, python-service/, docs/diagrams/, or database/; creating a placeholder for a fact you could verify from the repo instead; deviating from the OO decision.

## Progress
After each section: ✅ [section] — report/03-system-analysis.md

Think carefully and step-by-step before starting; reconcile each model against the actual code.
```
