# Prompt — Appendices (snippets, screenshots, supervisor visit log)

Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft report/appendices.md for the QForge CSC412 report, matching GUIDE §3 (References & Appendices) — the syllabus requires all three of: screenshots, snippets of major source components, and a log of supervisor visits.

## Context
Read first: report/GUIDE.md (§3 Appendices, §5A — Appendices mode = Verbatim allowed: snippets/screenshots are exhibits, shown as-is), report/WRITING-QUALITY.md (§9 plagiarism: own code shown as exhibit is fine; label everything).
Sources: the real major components — code/app/Services/PaperGeneration/ (esp. GreedySelector, BacktrackingResolver, ConstraintValidator, PaperGenerator), the key API controller(s), python-service/app/ (/extract, /generate-questions), the jobs; screenshots the user will supply.

## Target State
report/appendices.md with:
- Appendix A — Screenshots: a captioned placeholder list ({{SCREENSHOT: …}}) for the key UI flows (blueprint editor, generate-paper result, question bank/review queue, export). One caption per figure, numbered.
- Appendix B — Source Code Snippets: SELECTED, clearly-labelled excerpts of the major components (not whole files) — each with a short heading saying which class/method and file it is from. Pick the algorithm-critical methods (greedy selection, backtracking, constraint validation) so the appendix evidences the centrepiece. Keep excerpts short; they are exhibits.
- Appendix C — Log of Supervisor Visits: a {{SUPERVISOR_VISIT_LOG}} table with columns [Date | Discussion / Feedback | Action taken] — left as a placeholder table for the user to fill.

## Scope
- Write only: report/appendices.md.
- Do NOT touch: code, python-service, docs/, chapter report/*.md, the guide files. COPY snippets in — never edit the source.

## Constraints
- Snippets are verbatim excerpts of QForge's OWN code, each labelled with its source path — this is by design (exhibit), but keep them short and representative, not whole files.
- Screenshots and the visit log are {{PLACEHOLDER}} the user supplies; do not invent visit dates or feedback.
- Do not paste secrets/.env values into any snippet — strip if encountered.
- Only produce the appendices file.

## Acceptance Criteria
- [ ] All three required appendices present (screenshots, source snippets, supervisor visit log).
- [ ] Snippets are short labelled excerpts of the real major components (algorithm methods included), each naming its file.
- [ ] Screenshot list and visit log are placeholders; nothing fabricated.
- [ ] No secrets in any snippet.
- [ ] Only report/appendices.md changed.

## Stop Conditions
Stop and ask before: editing any source file; inventing supervisor-visit entries; including a full file instead of an excerpt.

## Progress
After each appendix: ✅ [appendix] — report/appendices.md
```
