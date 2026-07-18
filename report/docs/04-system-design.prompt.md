# Prompt — Chapter 4: System Design (incl. §4.2 Algorithm — the centrepiece)

Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft Chapter 4 (System Design) of the QForge CSC412 report into report/04-system-design.md, Object-Oriented and refined to implementation level, with §4.2 Algorithm Details as the academic centre of gravity — matching GUIDE §3 (Ch4).

## Context
Read first, in order:
- report/GUIDE.md — §0 (OO, refined; ER kept inside design), §1 (algorithm is the centrepiece; AI is supportive not authoritative), §3 Ch4 plan, §4 diagram inventory, §5/§5A (Ch4 mode = Original; cite a source only for a borrowed technique, e.g. backtracking [6]/greedy [7]).
- report/WRITING-QUALITY.md — §6 design rubric.
- Sources: docs/Algorithm.md (all sections — the 5 phases: compile, greedy fill, validate, backtrack, explain shortfall; determinism; complexity; §6 flowchart; §7 test mapping), code/app/Services/PaperGeneration/ (real service classes: BlueprintCompiler, CandidateFilter, GreedySelector, ConstraintValidator, BacktrackingResolver, PaperGenerator, SimilarityGuard/VectorSimilarityGuard/NullSimilarityGuard, Support/TieBreaker), PLAN.md (The Algorithm; Data Model), docs/diagrams/architecture.mmd, docker-compose.yml, docs/diagrams/m6-schema.mmd, code/database/migrations/, docs/RAG-GUIDE.md.

## Target State
report/04-system-design.md containing:
- 4.1 Design → refined class diagram (with the PaperGeneration service classes), refined object/state/sequence/activity diagrams (add the AI top-up path and RAG guard), component diagram (Laravel orchestrator ↔ Python processor ↔ Vue ↔ MySQL/Redis/Qdrant/Ollama), deployment diagram (Docker Compose topology: containers, networks, volumes, ports), and Database design (the relational schema as the persistence view under the OO design, with a normalization note).
- 4.2 Algorithm Details → full prose + pseudocode of the 5 phases, seed-parameterised determinism, the soft semantic-duplicate guard, and complexity. This section must be the most substantial in the chapter.
Every diagram: text-referenced before it appears, numbered, captioned (figure below). Embed Mermaid under report/figures/ or mark `{{FIGURE: …}}` naming its source. Reconcile all class/service names against the actual code — names must be exact.

## Scope
- Write only: report/04-system-design.md (+ new report/figures/*.mmd).
- Do NOT touch: code, migrations, python-service, docs/diagrams/ canonical sources, other report/*.md, the two guide files.

## Constraints
- Object-Oriented, refined from Ch3 — continuity with the Ch3 models (don't re-derive; refine).
- The ER/relational schema appears ONLY here as the persistence view — not as a primary model.
- State plainly that the deterministic algorithm owns the final paper; AI is supportive only.
- Pseudocode must reflect the real phase logic in Algorithm.md/code — do not simplify away backtracking or the shortfall explanation.
- Paraphrase into formal prose; keep class/method/table names verbatim. {{PLACEHOLDER}} / {{CITATION NEEDED}} for gaps; never fabricate.
- Only produce Chapter 4.

## Acceptance Criteria
- [ ] 4.1 covers refined class + object + state + sequence + activity + component + deployment + database design.
- [ ] 4.2 gives the 5 phases in prose AND pseudocode, plus determinism, similarity guard, and complexity.
- [ ] Every service/class name matches code/app/Services/PaperGeneration/ exactly.
- [ ] AI-supportive framing stated; algorithm-authoritative made explicit.
- [ ] Diagrams referenced/numbered/captioned; sources reconciled; ER only as persistence view.
- [ ] Only report/04-system-design.md (+ report/figures/*.mmd) changed.

## Stop Conditions
Stop and ask before: editing anything under code/, python-service/, docs/diagrams/, database/; inventing a service class or phase not in the code; contradicting Algorithm.md.

## Progress
After each section: ✅ [section] — report/04-system-design.md

Think carefully and step-by-step before starting. §4.2 is the highest-value section — give it the most depth and verify it against docs/Algorithm.md and the code.
```
