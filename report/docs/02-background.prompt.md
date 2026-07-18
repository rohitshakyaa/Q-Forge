# Prompt — Chapter 2: Background Study & Literature Review

Paste the block below into a new Claude Code session at the repo root.

```
## Objective
Draft Chapter 2 (Background Study + Literature Review) of the QForge CSC412 report into report/02-background.md, matching GUIDE §3 (Ch2). This is the ONE chapter that is heavy paraphrase-and-cite.

## Context
Read first: report/GUIDE.md (§3 Ch2, §5 rule 1 "no bare definitions", §5A — Ch2 mode = Paraphrase + cite, §6 IEEE), report/WRITING-QUALITY.md (§5 literature-review rubric, §9 plagiarism), and report/references.md (the verified reference bank [1]–[7] + the IEEE format templates + the unconfirmed candidates).
Sources for the concepts: docs/Algorithm.md, docs/VIVA-GUIDE.md (§3, §5.5, §5.6, §6, §6.5), docs/RAG-GUIDE.md.

## Target State
report/02-background.md with:
- 2.1 Background Study — the fundamental concepts QForge rests on, each contextualised (no bare definition): constraint satisfaction / greedy heuristics + backtracking; blueprint/test-specification in assessment; OCR & digital PDF text extraction; embeddings & semantic similarity (RAG) for duplicate detection; LLM-assisted generation. Cite the matching verified sources ([2]–[7]) via paraphrase.
- 2.2 Literature Review — synthesised BY THEME (random / GA-ACO / backtracking / AI-LLM approaches to paper generation), comparing prior systems and ending with the gap statement that positions QForge's hybrid design. Use [1] and any candidate sources only after confirming their details in references.md; otherwise mark {{CITATION NEEDED}}.

## Scope
- Write only: report/02-background.md. You MAY append newly-verified references to report/references.md (with confidence noted) if you confirm a source — otherwise leave gaps as {{CITATION NEEDED}}.
- Do NOT touch: code, docs/, other report chapters, GUIDE.md, WRITING-QUALITY.md.

## Constraints
- Paraphrase every external idea into genuinely new structure AND wording, then cite [n] — never lift an abstract, never patchwrite (synonym-swap on the original skeleton).
- Do NOT fabricate a citation or invent bibliographic details. Only cite sources present-and-verified in references.md; everything else is {{CITATION NEEDED}}.
- No source-by-source "In [1]… In [2]…" wall — synthesise and compare.
- No basic textbook definitions; tie every concept to where QForge uses it.
- Only produce Chapter 2.

## Acceptance Criteria
- [ ] 2.1 covers all listed concepts, each contextualised to QForge, each carrying its [n].
- [ ] 2.2 is organised by theme, compares approaches, and ends with an explicit gap statement.
- [ ] Every citation resolves to a verified entry in references.md; unverifiable claims are {{CITATION NEEDED}}, not invented.
- [ ] No lifted/patchwritten text; no bare definitions.
- [ ] Only report/02-background.md (and possibly verified additions to references.md) changed.

## Stop Conditions
Stop and ask before: adding any citation whose details you could not verify; editing anything under code/ or docs/; drafting another chapter.

## Progress
After each section: ✅ [section] — report/02-background.md

Think carefully before writing each paraphrase — new structure, not new synonyms.
```
