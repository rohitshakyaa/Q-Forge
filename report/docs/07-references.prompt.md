# Prompt — References & Bibliography (finalise)

Run this LAST, after all chapters are drafted, so citations can be reconciled.
Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Finalise report/references.md: reconcile every in-text [n] across the drafted chapters into a single IEEE References list in order of first appearance, keep the Bibliography (read-but-not-cited) separate, and surface every unresolved gap — matching GUIDE §6 and the IEEE templates already in references.md.

## Context
Read first: report/GUIDE.md (§6 citation/referencing), report/references.md (the reference bank, the IEEE format-templates table, the unconfirmed candidates), report/WRITING-QUALITY.md (§0 examiner criterion 6, §9 plagiarism).
Then scan report/01-introduction.md through report/06-conclusion.md and report/00-front-matter.md for every [n] citation and every {{CITATION NEEDED}} marker.

## Target State
report/references.md updated so that:
- Every [n] used in a chapter has a matching, IEEE-formatted entry (use the templates table for the correct per-type format).
- References are RENUMBERED in order of first appearance across the concatenated report (front-matter has no citations; order follows Ch1→Ch6). Produce a short old→new mapping so the chapters can be updated.
- Sources verified during Ch2 drafting are folded in with confidence noted; unconfirmed candidates are either resolved or clearly quarantined (not placed in the live list).
- A Bibliography section lists any read-but-not-cited sources.
- Every remaining gap is listed explicitly as {{CITATION NEEDED}} with the chapter/section that needs it — NOT filled with an invented source.

## Scope
- Write only: report/references.md. You MAY output (as text, not edits) the old→new [n] mapping so the user updates chapter files themselves — do NOT edit the chapter files in this session.
- Do NOT touch: code, docs/, chapter report/*.md, the guide files.

## Constraints
- IEEE format exactly, per the templates table; abbreviate authors with et al. only at six or more.
- NEVER fabricate a citation or bibliographic detail. Unverifiable → stays {{CITATION NEEDED}}.
- Renumbering must be by first appearance in reading order.
- Only touch references.md.

## Acceptance Criteria
- [ ] Every chapter [n] maps to exactly one IEEE entry; numbering is first-appearance order.
- [ ] An old→new [n] mapping is provided for updating chapters.
- [ ] Bibliography separated from References; candidates resolved or quarantined.
- [ ] All gaps remain {{CITATION NEEDED}} — none invented.
- [ ] Only report/references.md changed.

## Stop Conditions
Stop and ask before: adding any source you could not verify; editing chapter files to swap citation numbers (hand the mapping to the user instead).

## Progress
✅ [step] — report/references.md
```
