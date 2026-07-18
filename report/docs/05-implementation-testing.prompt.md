# Prompt — Chapter 5: Implementation and Testing

Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft Chapter 5 (Implementation and Testing) of the QForge CSC412 report into report/05-implementation-testing.md, matching GUIDE §3 (Ch5) and the WRITING-QUALITY testing rubric.

## Context
Read first: report/GUIDE.md (§3 Ch5, §5, §5A — Ch5 mode = Original), report/WRITING-QUALITY.md (§7 implementation/testing rubric — contextualise tools, positive+negative test cases).
Sources: PLAN.md (Infra/Config), docs/CONVENTIONS.md, composer.json, requirements.txt, package.json (for the tool list); the real modules under code/app/ (PaperGeneration services, API controllers, jobs ProcessDocumentUpload/ExpandQuestionBank, export view-model) and python-service/app/ (/extract, /generate-questions); docs/Algorithm.md §7 (test mapping); the actual test files under code/tests/ and python-service tests; docs/MILESTONES.md (test counts and per-milestone demos).

## Target State
report/05-implementation-testing.md with:
- 5.1.1 Tools Used — each tool described in the context of how QForge uses it (NO textbook blurbs): Laravel/PHP, Vue 3 + Pinia + Tailwind, FastAPI/Python, MySQL, Redis + Horizon, Qdrant, Ollama, Docker; libs dompdf, phpword, pdfplumber/pypdf, pytesseract.
- 5.1.2 Implementation Details of Modules — walk the real classes/methods/algorithms of the generation services, controllers, Python endpoints, jobs, export. Describe them; do NOT paste whole files (full snippets go to the Appendix).
- 5.2 Testing — a test-case TABLE with columns [Test ID | Scenario | Input | Expected | Actual | Result], covering 5.2.1 Unit (map to algorithm phases: feasible→valid, unit-coverage, repetition-exclusion, infeasible→missing_slots, backtracking recovery, seeded variation, within-paper dedup) and 5.2.2 System (API feature tests; pytest extraction). Include deliberate NEGATIVE cases (infeasible blueprint, invalid upload). No "what is unit testing" definitions. Reference the real green test counts from MILESTONES.
- 5.3 Result Analysis — report outcomes against the Ch1 §1.3 objectives; cross-reference representative screenshots/outputs to the Appendix.

## Scope
- Write only: report/05-implementation-testing.md.
- Do NOT touch: code, tests, python-service, docs/, other report chapters, the guide files.

## Constraints
- Original voice; register-rewrite of code/VIVA-GUIDE — never paste prose. Class/method/tool names verbatim.
- Test cases must reflect the ACTUAL test suite — verify against the test files; do not invent passing tests or counts. If a count is unverified, mark {{VERIFY}}.
- {{PLACEHOLDER}} for institutional facts; no fabrication.
- Only produce Chapter 5.

## Acceptance Criteria
- [ ] 5.1.1 tools are all contextualised to QForge, zero generic definitions.
- [ ] 5.1.2 describes real modules by name without pasting whole files.
- [ ] 5.2 is a test-case table mapping to the algorithm phases, with both positive and negative cases, backed by real tests.
- [ ] 5.3 evaluates results against the stated objectives; screenshots cross-referenced to Appendix.
- [ ] Test counts/results are truthful (or {{VERIFY}}); names verbatim.
- [ ] Only report/05-implementation-testing.md changed.

## Stop Conditions
Stop and ask before: editing any code or test file; citing a test result you did not confirm from the suite; drafting another chapter.

## Progress
After each section: ✅ [section] — report/05-implementation-testing.md
```
