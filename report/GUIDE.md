# QForge Project Report — Authoring GUIDE

> **Audience: Claude (and any AI assistant).** This is the standing instruction set for writing the
> CSC412 Project Work final report for **QForge — Smart Question Paper Generator**. When the user
> says *"write chapter N"*, *"draft the report"*, or *"generate the docx"*, read this file first,
> then follow it exactly. It encodes the university format, the chapter-by-chapter content plan,
> where every fact comes from in this repo, the modelling approach, the writing rules, and the docx
> build recipe. **This GUIDE is not part of the report** — it never ships in the `.docx`.

---

## 0. Locked decisions (do not re-litigate)

| Decision | Value | Consequence |
|---|---|---|
| Modelling approach | **Object-Oriented**, consistently across Ch3 (Analysis) and Ch4 (Design) | Use case, class, object, state, sequence, activity diagrams in analysis; refined class/sequence/activity + component + deployment in design. The ER schema is retained *inside* design as the persistence model, but the report's spine is OO. |
| DOCX production | **Clean Markdown → manual styling in Word** | Chapters are written as clean, semantic Markdown. The final `.docx` is produced by pasting into a pre-styled Word template and applying paragraph/heading styles by hand (see §7). An optional Pandoc automation recipe is documented for when the user explicitly asks me to *generate* the file. |
| File layout | **Per-chapter Markdown files under `report/`** | See §2. One file per chapter + front-matter + references + appendices. Never a single monolith. |
| Citations | **IEEE** numeric style `[n]` | In-text bracketed numbers; a single ordered References list. Bibliography (uncited-but-read) is separate. See §6. |
| Voice | Formal, past-tense for completed work, third person | See §5. |

**When any fact is unknown** (author names, roll numbers, college, supervisor, dates), insert a
`{{PLACEHOLDER}}` token — never invent institutional facts. Collect all placeholders in §8.

---

## 1. What the report is

A Tribhuvan University, IOST, BSc CSIT **Semester VII, CSC412 Project Work** final report
(Full Marks 80+20). QForge is a real, built, working system (M1–M6 all `Done` — see
[`../docs/MILESTONES.md`](../docs/MILESTONES.md) Progress table), so the report is written in the
**past tense as a completed system**, not a proposal. The syllabus (`projectworksyllabus-1.pdf`,
pp. 101–107) is the binding spec for structure and format; §3 and §7 below transcribe it.

**The academic centerpiece is the constraint-based paper-generation algorithm** (hybrid
greedy + backtracking, seed-parameterised, with a soft semantic-duplicate guard). The syllabus
says: *"Students are highly recommended to implement relevant algorithms."* Chapters 4 and 5 must
foreground this. AI is **supportive, not authoritative** — say so explicitly; the algorithm always
controls the final paper.

---

## 2. File layout under `report/`

```
report/
  GUIDE.md              ← this file (never ships)
  00-front-matter.md    ← cover, certificate, approval, acknowledgement, abstract, TOC,
                          lists of abbreviations/figures/tables  (roman page numbers i, ii, …)
  01-introduction.md    ← Chapter 1
  02-background.md       ← Chapter 2
  03-system-analysis.md  ← Chapter 3
  04-system-design.md    ← Chapter 4
  05-implementation-testing.md ← Chapter 5
  06-conclusion.md       ← Chapter 6
  references.md         ← References (IEEE) + Bibliography
  appendices.md         ← screenshots, source snippets, supervisor visit log
  figures/              ← exported PNG/SVG diagrams referenced by the chapters
```

Chapter files are concatenated in the numeric order above at build time. Keep each file
self-contained and semantically clean (headings, tables, figure references) — no Word-specific
markup in the Markdown.

---

## 3. Report structure & per-section content plan

Follows the syllabus "Prescribed chapters in the main report" (pp. 104–105) **exactly**. Heading
numbers below are the report's own numbering. For each section: what to write, and **[source]** =
where the material lives in this repo.

> **Standing rule from the syllabus (p. 106):** *avoid basic textbook definitions; relate and
> contextualise every concept to QForge.* Do not define "what is an algorithm" — explain *QForge's*
> algorithm. Every general concept must land on a concrete QForge specific within two sentences.

### Front matter (`00-front-matter.md`) — roman numerals from `i`
1. **Cover & Title Page** — project title, author(s), submission line to Dept/Campus, TU/IOST, date. `{{PLACEHOLDERS}}`.
2. **Student's Declaration** *(optional — not in the syllabus's prescribed content flow, p. 104; include only if the college requires it, as some do)* — signed statement that the work is the authors' own and has not previously been presented to this or any other institution for assessment; per-author signature line with name + exam/roll number. Template text; names `{{PLACEHOLDER}}`, signatures inserted at print.
3. **Certificate Page** — (i) Supervisor's Recommendation, (ii) Approval Letter with signature blocks for Head/Coordinator, Supervisor, Internal Examiner, External Examiner. Template text; names `{{PLACEHOLDER}}`.
4. **Acknowledgement** — brief, formal. `{{PLACEHOLDER}}` for named people.
5. **Abstract** — ~250 words: problem (manual paper-setting), what QForge does (blueprint-driven constraint generation + optional AI top-up), the algorithm, tech stack, outcome. **[source]** VIVA-GUIDE §1 elevator pitch, PLAN.md Context.
6. **Table of Contents**, **List of Abbreviations**, **List of Figures**, **List of Tables** — generated last, after content is stable.

### Chapter 1 — Introduction (`01-introduction.md`)
- **1.1 Introduction** — domain (exam question-paper preparation for TU-style courses), what QForge is. **[source]** PLAN.md Context; README.md; CLAUDE.md Project Overview.
- **1.2 Problem Statement** — manual paper-setting is slow, error-prone, hard to balance across units/marks/types, and prone to repetition across years. **[source]** VIVA-GUIDE §1, §7 talking points; proposal framing.
- **1.3 Objectives** — 3–5 bullet objectives (e.g. *build a constraint-based generator that produces valid, balanced papers from a blueprint; enforce unit coverage & non-repetition; support past-paper ingestion; provide AI bank top-up; export PDF/DOCX*). Keep measurable.
- **1.4 Scope and Limitation** — in scope: blueprint→paper generation, question bank, extraction, AI top-up, export. Limitations: AI is supportive only; extraction needs admin review; single-institution assumptions. **[source]** VIVA-GUIDE §8 honest weak points.
- **1.5 Development Methodology** — iterative, **milestone-first (M1–M6)**, algorithm-first sequencing. **[source]** MILESTONES.md "How to read this" + Progress table.
- **1.6 Report Organization** — one short paragraph per remaining chapter.

### Chapter 2 — Background Study & Literature Review (`02-background.md`)
- **2.1 Background Study** — the *fundamental concepts QForge rests on*, contextualised: constraint satisfaction / greedy heuristics + backtracking; blueprint/test-specification in assessment; OCR & digital PDF text extraction; embeddings & semantic similarity (RAG) for duplicate detection; LLM-assisted generation. **[source]** Algorithm.md; VIVA-GUIDE §3, §5.5, §5.6, §6, §6.5; RAG-GUIDE.md. **No bare definitions** — each concept tied to where QForge uses it.
- **2.2 Literature Review** — review similar/relevant systems and prior work (existing exam/quiz generators, question-bank tools, automatic item generation, CSP-based scheduling analogues). Cite in IEEE. **This needs external sources** the user must supply or approve; mark gaps with `{{CITATION NEEDED}}`. Do not fabricate citations.

### Chapter 3 — System Analysis (`03-system-analysis.md`) — **Object-Oriented**
- **3.1.1 Requirement Analysis**
  - **Functional Requirements** — illustrated with a **use case diagram** + use case descriptions. Actors: Admin, Teacher, (System/Python service). Use cases: manage subjects/units/questions, upload past paper, review queue, import syllabus, create blueprint, generate paper, expand bank with AI, export. **[source]** PLAN.md "Laravel API" section (routes = features); ROLES.md; MILESTONES.md per-milestone demos.
  - **Non-Functional Requirements** — determinism/reproducibility (seeded), performance, security (Sanctum + role middleware), maintainability (service boundaries), portability (Docker). **[source]** PLAN.md Locked Design Decisions; VIVA-GUIDE §5.
- **3.1.2 Feasibility Analysis** — Technical (stack maturity), Operational (fits a college workflow), Economic (open-source, self-hosted Ollama — no per-call cost), Schedule (the M1–M6 timeline; include a **Gantt chart**). **[source]** MILESTONES progress; CONVENTIONS.md.
- **3.1.3 Analysis (Object-Oriented)** — the OO models of the *problem*:
  - **Class diagram** (domain/analysis level) — entities & relationships. **[source]** PLAN.md Data Model + Eloquent models in `code/app/Models/`; ER sources `docs/diagrams/m6-schema.mmd` translated to classes.
  - **Object diagram** — one instance-level snapshot of the class model (e.g. a concrete `Blueprint` with its `Slot` objects and selected `Question` instances for a sample paper). The syllabus (3.1.3) names *Class **and Object** Diagrams* explicitly, so include at least one. **[source]** a worked example from the generator's own output.
  - **State diagrams** — `Question` (pending→approved/rejected), `Paper` (draft→saved→exported), `document_uploads` (uploaded→processing→parsed/failed). **[source]** PLAN.md Data Model status enums.
  - **Sequence diagrams** — "generate a paper" and "extract from PDF". **[source]** `docs/diagrams/seq-generate.mmd`, `docs/diagrams/seq-extract.mmd`; VIVA-GUIDE §2 A-to-Z flow.
  - **Activity diagram** — the generation algorithm's control flow (greedy → validate → backtrack → shortfall). **[source]** Algorithm.md §6 flowchart (`docs/diagrams/algorithm-m2.mmd`).

### Chapter 4 — System Design (`04-system-design.md`) — **Object-Oriented, refined**
- **4.1 Design** — refinements of the Ch3 models to implementation level:
  - Refined **class diagram** with the `PaperGeneration` service classes (`BlueprintCompiler`, `CandidateFilter`, `GreedySelector`, `ConstraintValidator`, `BacktrackingResolver`, `PaperGenerator`, `SimilarityGuard`/`VectorSimilarityGuard`/`NullSimilarityGuard`, `Support/TieBreaker`). **[source]** `code/app/Services/PaperGeneration/`; PLAN.md "The Algorithm".
  - Refined **object/state/sequence/activity** diagrams (add AI top-up path, RAG guard). The syllabus (4.1) lists *Refinement of Class, **Object**, State, Sequence and Activity diagrams* — carry the object-level snapshot through to the design level too.
  - **Component diagram** — Laravel (orchestrator) ↔ Python (processor) ↔ Vue (presentation) ↔ MySQL/Redis/Qdrant/Ollama. **[source]** `docs/diagrams/architecture.mmd`; CLAUDE.md Architecture Principles.
  - **Deployment diagram** — the Docker Compose topology (containers, networks, volumes, ports). **[source]** `docker-compose.yml`; CONVENTIONS.md.
  - **Database design** (persistence view under the OO design) — the relational schema and its normalization note. **[source]** `docs/diagrams/m6-schema.mmd`; migrations in `code/database/migrations/`.
- **4.2 Algorithm Details** — **the centerpiece.** Full prose + pseudocode of the 5 phases (compile, greedy fill, validate, backtrack, explain shortfall), seed-parameterised determinism, the soft semantic-duplicate guard, complexity. **[source]** Algorithm.md (all sections, esp. §3, §4, §5, §6); VIVA-GUIDE §3.

### Chapter 5 — Implementation and Testing (`05-implementation-testing.md`)
- **5.1.1 Tools Used** — Laravel/PHP, Vue 3 + Pinia + Tailwind, FastAPI/Python, MySQL, Redis + Horizon, Qdrant, Ollama, Docker; libraries: dompdf, phpword, pdfplumber/pypdf, pytesseract. **[source]** PLAN.md Infra/Config; CONVENTIONS.md; `composer.json`, `requirements.txt`, `package.json`.
- **5.1.2 Implementation Details of Modules** — walk the real modules: the generation service classes; the API controllers; the Python `/extract` and `/generate-questions`; the jobs (`ProcessDocumentUpload`, `ExpandQuestionBank`); export view-model. Describe classes/methods, don't paste whole files (snippets go to Appendix). **[source]** the code under `code/app/`, `python-service/app/`; VIVA-GUIDE §2, §5.5, §5.6, §6.
- **5.2 Testing** — **5.2.1 Unit** (map the `tests/Unit/PaperGeneration/*` suite to the algorithm phases — feasible→valid, unit-coverage, repetition exclusion, infeasible→missing_slots, backtracking recovery, seeded variation, within-paper dedup) and **5.2.2 System** (feature tests hitting the API; pytest for extraction). Present as a **test-case table** (id, scenario, input, expected, result). **[source]** Algorithm.md §7 test mapping; MILESTONES test counts (112 Laravel + 97 pytest green); actual test files.
- **5.3 Result Analysis** — what the system achieves: valid balanced papers, reproducibility, non-repetition across papers, successful extraction/import, AI top-up closing feasibility gaps. Include representative outputs/screenshots (Appendix cross-ref). **[source]** MILESTONES per-milestone demos; VIVA-GUIDE §7.

### Chapter 6 — Conclusion and Future Recommendations (`06-conclusion.md`)
- **6.1 Conclusion** — restate problem → solution → that objectives were met.
- **6.2 Future Recommendations** — honest roadmap. **[source]** VIVA-GUIDE §8 weak points reframed as future work.

### References & Appendices
- **`references.md`** — IEEE-numbered **References** (cited only) + **Bibliography** (read, uncited).
- **`appendices.md`** — screenshots, **snippets of major source components**, and the **log of supervisor visits** (syllabus p. 104 requires all three). Visit log is `{{PLACEHOLDER}}` table.

---

## 4. Object-Oriented diagram inventory

Track each required diagram, its source, and status. Diagrams live as Mermaid where possible
(`report/figures/*.mmd`) and are exported to PNG/SVG for the Word file.

| Diagram | Chapter | Source in repo | Status |
|---|---|---|---|
| Use case | 3.1.1 | derive from PLAN.md API + ROLES.md | to create |
| Class (analysis) | 3.1.3 | `diagrams/m6-schema.mmd` + `code/app/Models/` | to create (translate ER→classes) |
| Object (instance snapshot) | 3.1.3 / 4.1 | worked example from generator output | to create |
| State (Question/Paper/Upload) | 3.1.3 | PLAN.md status enums | to create |
| Sequence — generate | 3.1.3 / 4.1 | `diagrams/seq-generate.mmd` | **exists** |
| Sequence — extract | 3.1.3 / 4.1 | `diagrams/seq-extract.mmd` | **exists** |
| Activity — algorithm | 3.1.3 / 4.2 | `diagrams/algorithm-m2.mmd` | **exists** (adapt) |
| Class (design, with services) | 4.1 | `code/app/Services/PaperGeneration/` | to create |
| Component | 4.1 | `diagrams/architecture.mmd` | **exists** (adapt) |
| Deployment | 4.1 | `docker-compose.yml` | to create |
| ER / relational schema | 4.1 | `diagrams/m6-schema.mmd` | **exists** |
| Gantt (schedule) | 3.1.2 | MILESTONES timeline | to create |

Do not invent diagrams that contradict the code — always reconcile against the current source
before drawing. When adapting an existing `.mmd`, copy it into `report/figures/` rather than editing
the canonical `docs/diagrams/` source.

---

## 5. Writing rules

1. **Contextualise, never define generically** (syllabus p. 106). Bad: "A database is an organized
   collection of data." Good: "QForge persists its question bank and paper snapshots in MySQL via
   Laravel migrations, with …".
2. **Tense/voice** — completed work in past tense ("The generator was implemented as …"); the system's
   behaviour in present tense ("The greedy pass ranks candidates by …"). Formal, third person. No "we
   will", no marketing tone, no emoji.
3. **Truthful to the build** — every claim must be backed by the repo. All six milestones are `Done`;
   do not claim anything not in the code. If unsure, verify against source before writing, or flag
   `{{VERIFY}}`.
4. **AI framing** — always state AI is *supportive, not authoritative*; the deterministic algorithm
   owns the final paper. This is both true and a defensive talking point.
5. **Figures & tables** — every figure/table is numbered ("Figure 3.1", "Table 5.2"), captioned, and
   **referenced in the text before it appears**. Figure captions below, table captions above (§7).
6. **Citations** — IEEE `[n]` inline; never fabricate a source. Real, checkable references only. If a
   claim needs a citation the user hasn't supplied, mark `{{CITATION NEEDED}}` rather than inventing.
7. **Consistency of terms** — use the repo's vocabulary exactly: *blueprint, slot, candidate, unit
   coverage, missing slot, seed, similarity guard, top-up*. A glossary/abbreviations list keeps them
   stable.

---

## 5A. Paraphrasing discipline (apply *while* drafting — not as a later pass)

Every section is written in the report's own formal voice from the first draft. Nothing is
copy-pasted — not from external sources, and not from this repo's own docs. Two distinct obligations
hide under the word "paraphrase"; keep them separate:

- **External sources → paraphrase *and* cite `[n]`.** An academic-integrity obligation. Restate the
  source's idea in genuinely new sentence structure and wording, keep its meaning intact, and attach
  the IEEE citation. This governs Chapter 2 above all (and any cited claim elsewhere).
- **QForge's own internal docs (PLAN.md, Algorithm.md, VIVA-GUIDE.md, MILESTONES.md, README, code
  comments) → rewrite for register, no citation.** These are the *system being described*, not
  literature (§6). They are informal, present-tense, sometimes marketing-toned and emoji-laden.
  Transform them into formal, past/present report prose (§5.2). Pasting them verbatim is wrong on
  voice *and* is self-duplication a similarity checker will flag against the repo.

**What proper paraphrasing is** (both cases): change the *structure*, not just the words. Reorder the
logic, merge or split sentences, switch voice (active↔passive), recast the grammar (a bullet list →
prose, noun-heavy → verb-led). **Synonym-swapping on the original sentence skeleton is patchwriting**
— checkers and examiners both catch it. Do not do it.

**What is *never* paraphrased** (keep verbatim/exact):
- Technical terms, class/method/service names, table/column names, tech-stack names — keep
  *cosine similarity, FastAPI, Qdrant, `GreedySelector`, `paper_questions`* exactly. You paraphrase
  the explanation *around* the term, never the term.
- Direct quotations you deliberately choose to quote — those stay in quotation marks with a citation
  and a page/section locator. Prefer paraphrase; quote only when the exact wording matters.
- The syllabus's binding requirements when transcribed as spec (§3, §7). Those are structure, not
  prose to reword.
- QForge's fixed vocabulary from §5 rule 7 (*blueprint, slot, candidate, …*) — consistency beats
  variation; do not "elegant-variation" these into synonyms.

**Your original contribution is not paraphrase at all.** The algorithm, the architecture, the data
model, the design decisions, the results — these are written fresh in your own voice with no source
to restate. Most of the report is this.

### Per-section paraphrasing mode

| Report section | Mode | Notes |
|---|---|---|
| Abstract, Acknowledgement, Declaration | **Original** | Own synthesis of the built system; no external restatement. |
| Ch1 Introduction (all of 1.1–1.6) | **Original** | Domain framing, problem, objectives, scope, methodology are the team's own. Rewrite VIVA-GUIDE/PLAN framing into formal prose (register), not copy. |
| Ch2 §2.1 Background Study | **Paraphrase + cite** (heavy) | Every fundamental concept (CSP/greedy/backtracking, OCR, embeddings/SBERT, AIG) is restated in own words *and* carries its `[n]` from `references.md` [2]–[7]. No bare textbook definitions (§5 rule 1). |
| Ch2 §2.2 Literature Review | **Paraphrase + cite** (heavy) | Reviews of prior systems [1] and any candidate sources — paraphrase each system's approach, never lift its abstract. Gaps → `{{CITATION NEEDED}}`. |
| Ch3 System Analysis | **Original** | Requirements, use cases, OO models are the team's own analysis of QForge. Rewrite PLAN.md/ROLES.md into report prose (register). Cite only if a *method definition* leans on an external source. |
| Ch4 System Design (incl. 4.2 Algorithm) | **Original** | The centerpiece — pure own-voice writing from `code/` + Algorithm.md, rewritten formally (register). Cite a source only for a borrowed *technique* (e.g. backtracking [6], greedy [7]) where a nod is warranted. |
| Ch5 Implementation & Testing | **Original** | Describe the real modules and tests in own words; do not paste whole files (snippets → Appendix). Register-rewrite of code/VIVA-GUIDE. |
| Ch6 Conclusion & Future Work | **Original** | Own reflection; VIVA-GUIDE weak points reframed in formal prose. |
| Appendices | **Verbatim allowed** | Source snippets and screenshots are shown *as-is by design* — they are exhibits, not prose, so no paraphrase (and no similarity concern). |

**Rule of thumb while drafting a section:** if the fact came from *outside* the repo, paraphrase it
and cite it; if it came from *inside* the repo, rewrite it into formal report voice; if it is a name
or a fixed term, leave it exact.

---

## 6. Citation & referencing (IEEE)

- In-text: bracketed numbers in order of first appearance, e.g. "… backtracking search [3]."
- **References** section: numbered list, IEEE format, cited items only.
- **Bibliography**: read-but-not-cited items, separate list (syllabus allows this).
- Keep a running map of `[n] → source` at the top of `references.md` while drafting.
- QForge's own internal docs (PLAN.md, Algorithm.md) are **not** external references — they are the
  system being described, not literature. Cite external books/papers/URLs only.

---

## 7. Format standards & DOCX production

### 7.1 The binding format (syllabus p. 106) — apply in Word
- **Page size:** A4. **Margins:** Top 1in (2.54cm), Bottom 1in (2.54cm), **Left 1.25in (3.17cm)**, Right 1in (2.54cm).
- **Body font:** Times New Roman, **12 pt**. **Paragraphs:** justified, **1.5 line spacing**.
- **Headings (bold):** chapter heading **16 pt**, section heading **14 pt**, sub-section **12 pt**.
- **Page numbers:** bottom, centred. **Roman (i, ii, …)** from the Certificate page through the
  lists; **Arabic (1, 2, …)** from Chapter 1 onward. (Two Word *sections* with a section break; front
  matter uses roman, body restarts at 1.)
- **Figures/tables:** centred; **figure caption centred *below*** the figure; **table caption centred
  *above*** the table; captions **bold, 12 pt**.
- **Binding (final):** 3 copies, golden embossing on black binding (production detail, not our concern
  until print).

### 7.2 Primary path — Markdown → manual Word (the chosen method)
1. Keep each chapter's Markdown clean and semantic.
2. Prepare a `report/tu-template.docx` **once**: define Word styles — *Heading 1* = TNR 16 bold,
   *Heading 2* = TNR 14 bold, *Heading 3* = TNR 12 bold, *Normal* = TNR 12, justified, 1.5 spacing;
   set A4 + the margins above; set up two sections with roman→arabic page numbering.
3. Paste each chapter's content into the template and apply styles (Markdown `#/##/###` map to
   Heading 1/2/3). Insert exported diagrams from `report/figures/`, centre them, add captions.
4. Build the TOC and the Lists of Figures/Tables/Abbreviations via Word's automatic fields (they rely
   on the heading styles + caption fields being applied).
5. Proofread against §5 rules and the syllabus checklist.

### 7.3 Optional automation — Pandoc (only when the user asks me to *generate* the docx)
```bash
# concatenates chapters in order into one styled docx against the reference template
pandoc report/00-front-matter.md report/01-introduction.md report/02-background.md \
       report/03-system-analysis.md report/04-system-design.md \
       report/05-implementation-testing.md report/06-conclusion.md \
       report/references.md report/appendices.md \
       -o report/QForge-Report.docx \
       --reference-doc=report/tu-template.docx \
       --toc --number-sections
```
Caveats to warn the user about: Pandoc cannot cleanly do the **dual roman→arabic page numbering** or
per-figure caption placement — those remain manual finishing steps in Word. `--number-sections`
gives "1.1"-style numbering; confirm the user wants that vs. Word field numbering. Never run this
without confirming `tu-template.docx` exists and that the user wants a generated (vs. hand-styled)
file.

### 7.4 Chosen build method — Claude Desktop / claude.ai file creation

The user's selected path is **not** the local Pandoc automation above. Instead, the nine content
Markdown files are **attached to a Claude Desktop / claude.ai chat**, and Claude's **file-creation**
ability generates the `.docx` directly to the §7.1 format. The ready-made, self-contained prompt for
this is [`docs/09-build-docx.prompt.md`](docs/09-build-docx.prompt.md) — it embeds the whole §7.1
format spec (so no repo files are needed beyond the nine chapters), fixes the concatenation order,
excludes the guide/prompt files, keeps every `{{token}}` visible (never invents facts), and asks for
BUILD NOTES listing leftover tokens + any finishing step to do in Word. The Pandoc recipe in §7.3
remains the fallback if a Claude surface lacks file creation.

---

## 8. Placeholders to collect before a final build

Fill these before producing a submission-ready `.docx`; until then leave the `{{TOKEN}}` in place.

- `{{PROJECT_TITLE}}` (working: "QForge — Smart Question Paper Generator")
- `{{AUTHORS}}` — names + roll/exam numbers (group ≤ 3)
- `{{COLLEGE_CAMPUS}}`, `{{DEPARTMENT}}`, `{{UNIVERSITY}}` (IOST, Tribhuvan University)
- `{{PROGRAM}}` (BSc CSIT), `{{SEMESTER}}` (VII), `{{COURSE}}` (CSC412 Project Work)
- `{{SUPERVISOR_NAME}}`, `{{INTERNAL_EXAMINER}}`, `{{EXTERNAL_EXAMINER}}`, `{{HOD_COORDINATOR}}`
- `{{SUBMISSION_DATE}}`
- `{{SUPERVISOR_VISIT_LOG}}` (dates + technical feedback — Appendix)
- `{{LITERATURE_REVIEW_SOURCES}}` — external references for Ch2 (must be user-supplied/approved)

---

## 9. Source-document map (quick reference)

| Need | Read |
|---|---|
| One-line pitch, problem, talking points, weak points, likely Q&A | [`../docs/VIVA-GUIDE.md`](../docs/VIVA-GUIDE.md) |
| Design decisions, data model, API contract, algorithm summary | [`../PLAN.md`](../PLAN.md) |
| Milestone timeline, demos, progress, cumulative schema | [`../docs/MILESTONES.md`](../docs/MILESTONES.md) |
| Algorithm deep-dive, phases, complexity, flowchart, test mapping | [`../docs/Algorithm.md`](../docs/Algorithm.md) |
| RAG / semantic duplicate guard | [`../docs/RAG-GUIDE.md`](../docs/RAG-GUIDE.md) |
| Roles & permissions | [`../docs/ROLES.md`](../docs/ROLES.md) |
| Docker/networking/queues/conventions | [`../docs/CONVENTIONS.md`](../docs/CONVENTIONS.md) |
| Architecture decisions (imported papers, syllabus import) | [`../docs/adr/`](../docs/adr/) |
| Diagram sources | [`../docs/diagrams/`](../docs/diagrams/) |
| **Per-section writing quality rubric** (abstract/intro/problem-statement/lit-review/testing checklists, examiner criteria, plagiarism rules) | [`WRITING-QUALITY.md`](WRITING-QUALITY.md) |
| IEEE format templates for new references | [`references.md`](references.md) (bottom) |
| **Paste-ready per-section writing prompts** (one Claude Code prompt per chapter) | [`docs/`](docs/) ([index](docs/README.md)) |
| The binding format & chapter structure | `projectworksyllabus-1.pdf` (pp. 101–107) |
| Actual code to describe truthfully | `../code/`, `../python-service/`, `../frontend/` |

---

*End of GUIDE. Keep this current if the build changes (e.g. a new milestone) or the user revises a
locked decision in §0.*
