# Prompt — Build the submission DOCX in Claude Desktop / claude.ai

**How to use:** open a new chat in **Claude Desktop or claude.ai** (with file creation enabled),
**attach the nine report Markdown files** (`00-front-matter.md`, `01-introduction.md`,
`02-background.md`, `03-system-analysis.md`, `04-system-design.md`,
`05-implementation-testing.md`, `06-conclusion.md`, `references.md`, `appendices.md`) — do **not**
attach `GUIDE.md`, `WRITING-QUALITY.md`, or any prompt file — then paste the block below.

Self-contained: the binding format is embedded, so Claude needs nothing but the attached files.

```
<task>
Combine the attached Markdown files into ONE downloadable Microsoft Word (.docx) file for a
Tribhuvan University / IOST BSc CSIT project report, applying the exact formatting spec below.
Use your file-creation ability to generate a real .docx I can download — not a preview, not Markdown.
</task>

<inputs>
Nine attached Markdown files. Concatenate them in THIS order, each major file starting on a new page:
1. 00-front-matter.md   2. 01-introduction.md   3. 02-background.md
4. 03-system-analysis.md   5. 04-system-design.md   6. 05-implementation-testing.md
7. 06-conclusion.md   8. references.md   9. appendices.md
If any of these is not attached, tell me which is missing and ask before building — do not fabricate it.
Ignore any other attached file (guide or prompt files must never appear in the document).
</inputs>

<format>
Page: A4. Margins: Top 1 in, Bottom 1 in, Left 1.25 in, Right 1 in.
Body text: Times New Roman, 12 pt, justified, 1.5 line spacing.
Headings (all bold, Times New Roman): level 1 (Markdown #) = 16 pt; level 2 (##) = 14 pt; level 3 (###) = 12 pt.
Tables: keep as real Word tables. Table caption centred ABOVE the table, bold 12 pt.
Figures/images: figure caption centred BELOW the figure, bold 12 pt.
Page numbers: bottom, centred. Use lower-case Roman numerals (i, ii, iii …) for the front-matter
section (file 1), then restart at Arabic 1 from Chapter 1 (file 2) onward — put a Word section break
between them so the two numbering schemes coexist.
Insert an auto-updating Table of Contents field where 00-front-matter marks the Table of Contents
(it will populate when I open the file in Word and choose "update field").
</format>

<rules>
- Leave every {{PLACEHOLDER}}, {{CITATION NEEDED}}, {{VERIFY}}, and {{FIGURE: …}} token exactly as
  written, but make them easy to spot (e.g. highlight them). Do NOT invent names, dates, roll numbers,
  citations, or figures to fill them.
- Do not rewrite, summarise, or "improve" the prose — only lay it out and format it.
- Preserve heading numbers, lists, tables, code blocks, and citation markers [n] as-is.
- Where a {{FIGURE: …}} placeholder appears, insert a labelled empty box/caption in its place so I can
  drop the image in later; keep its numbered caption.
</rules>

<output>
1. The downloadable .docx built to the spec above.
2. A short "BUILD NOTES" list: every {{token}} still in the document (with the section it's in), and any
   formatting step you could not fully do in .docx so I can finish it in Word.
</output>
```

## Note
- Requires a Claude surface with **file creation** turned on (Claude Desktop / claude.ai). If your
  plan/toggle doesn't offer file creation, Claude can only return Markdown/HTML — then use the
  Pandoc route instead (older version of this prompt in git history, or GUIDE §7.3).
- Fill the `{{PLACEHOLDER}}` institutional facts (author names, roll numbers, supervisor/examiner,
  dates — GUIDE §8) **before** this step, or they'll show up highlighted in the Word file (which is
  the intended, safe behaviour — better a visible gap than an invented fact).
- Attach the files **in the listed order** to make Claude's job unambiguous.
