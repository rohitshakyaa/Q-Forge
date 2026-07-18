# Prompt — Front Matter (cover → abstract → TOC & lists)

Run this AFTER the body chapters are drafted (the TOC and lists depend on stable content).
Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft the front matter of the QForge CSC412 report into report/00-front-matter.md, matching GUIDE §3 (Front matter) and WRITING-QUALITY §1 (abstract rubric).

## Context
Read first: report/GUIDE.md (§3 Front matter, §7 format incl. roman-numeral pagination note, §8 placeholders), report/WRITING-QUALITY.md (§1 abstract 4 Ws + Keywords line).
Sources: docs/VIVA-GUIDE.md (§1 elevator pitch), PLAN.md (Context) for the abstract; the drafted report/01–06 files for the TOC / List of Figures / List of Tables / List of Abbreviations.

## Target State
report/00-front-matter.md containing, in order:
1. Cover & Title Page — title, author(s), submission line to Dept/Campus, TU/IOST, date — all institutional facts as {{PLACEHOLDER}}.
2. Student's Declaration — template text; per-author name + exam/roll number as {{PLACEHOLDER}}; signatures inserted at print. (Include; some colleges require it.)
3. Certificate Page — (i) Supervisor's Recommendation, (ii) Approval Letter with signature blocks for Head/Coordinator, Supervisor, Internal Examiner, External Examiner. Template text; names {{PLACEHOLDER}}.
4. Acknowledgement — brief, formal; named people as {{PLACEHOLDER}}.
5. Abstract — ONE paragraph, ~250 words, covering the 4 Ws (background/why → what we did → what we found → what it means), stand-alone (no citations/abbreviations), ending with a `Keywords:` line.
6. Table of Contents, List of Abbreviations, List of Figures, List of Tables — generated from the drafted chapters; if a chapter is missing, leave a marked stub and note it.

## Scope
- Write only: report/00-front-matter.md.
- Do NOT touch: code, docs/, body chapters, the guide files.

## Constraints
- Never invent institutional facts — every author/college/supervisor/examiner/date/roll number is a {{PLACEHOLDER}} (collect them against GUIDE §8).
- Abstract is Original, telegraphic-but-complete; no citations inside it.
- TOC/lists must reflect the ACTUAL drafted chapters and their figure/table captions — read the report/*.md files; do not fabricate entries or page numbers (page numbers are finalised in Word).
- Only produce the front matter.

## Acceptance Criteria
- [ ] All six front-matter units present and ordered per GUIDE §3.
- [ ] Abstract is one paragraph, ~250 words, hits the 4 Ws, ends with Keywords, stands alone.
- [ ] Every institutional fact is a {{PLACEHOLDER}}; none invented.
- [ ] TOC + three lists are built from the real drafted chapters/captions (stubs marked where a chapter is missing).
- [ ] Only report/00-front-matter.md changed.

## Stop Conditions
Stop and ask before: inventing any name/date/number; editing body chapters to fix a caption (report the mismatch instead); editing code or docs/.

## Progress
After each unit: ✅ [unit] — report/00-front-matter.md
```
