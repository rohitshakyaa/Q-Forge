# QForge Report — Per-Section Writing Quality Rubric

> **Companion to [`GUIDE.md`](GUIDE.md).** The GUIDE says *what* goes in each chapter and *where the
> facts live*. This file says *how well* each section must be written, distilled from the sources the
> supervisor provided: the TU/CDCSIT *Structuring Report, Presentation and Evaluation* guide
> (J. Bhatta), and the journal writing-tips papers on the [abstract], [introduction], [problem
> statement], [literature review] and [avoiding plagiarism]. Use it as a **checklist to grade a draft
> against before it is considered done**. It changes no locked decision in GUIDE §0; it sharpens the
> prose. Like the GUIDE, **this file never ships in the `.docx`.**

---

## 0. The examiner's lens (write toward this)

The report is marked against these criteria (Bhatta, "Assessment Criteria for Report"). Every drafting
choice should serve them:

1. **Clarity** of content presentation; no typos, no mistakes.
2. **Hierarchical structure** — correct chapter distribution and content placement (right thing in the
   right section; don't put design in analysis, don't put results in implementation).
3. **Consistency** between parts of the report (terminology, tense, diagrams-vs-text, numbers).
4. **Relevance** — how far the content actually supports *this* project (no filler, no generic theory
   that never touches QForge — GUIDE §5 rule 1).
5. **Ability to differentiate others' thoughts from your own** — visible, correct citation of external
   ideas; original contribution written in own voice (GUIDE §5A).
6. **Ability to handle references and citations** — IEEE done correctly (§ below + `references.md`).

**Evaluation weighting** (for context, not report content): Proposal 10% · Midterm 20% · Final defense
70%. Evaluators: Supervisor 60 · HOD/Coordinator 10 · Internal 10 · External 20. The **report itself**,
**level of work/understanding** (analysis→design→implementation→testing→result analysis), **viva**,
**demonstration**, and **teamwork** are all scored — so the report must make the depth of work legible.

**Standing rules that override style everywhere** (Bhatta, red-flag slides):
- **Avoid basic definitions.** Never define "what is a database / algorithm / API". Contextualise to
  QForge within two sentences (GUIDE §5 rule 1).
- **Either Structured *or* OO — never a mixture.** QForge is committed to **Object-Oriented** (GUIDE §0).
  Do not slip a DFD/ER-as-primary-model into the analysis spine. The ER schema appears only as the
  persistence view *inside* the OO design (GUIDE §3 Ch4).
- **The analysis/design content flexes to the nature of the project.** QForge's nature is *an
  algorithm*, so Ch4 §4.2 Algorithm Details is the centre of gravity (GUIDE §1).
- **Don't obsess over volume.** Completeness and clarity beat page count.

---

## 1. Abstract — rubric

Source: [abstract] (Cals & Kotz, *J. Clin. Epidemiol.*) + Bhatta abstract slide + the Nepal Tourism
Guide sample's abstract-with-keywords.

- **One paragraph, ~250 words** (Bhatta: "preferably no more than one paragraph"; journals cap 200–300).
- **Stand-alone** — readable with no reference to the body; no citations, no undefined abbreviations.
- **Cover the 4 Ws in order** (this is the pass/fail test):
  1. **Background / why** — manual question-paper setting is slow, error-prone, hard to balance and
     repetitive across years.
  2. **What we did (methods)** — a blueprint-driven, constraint-based generator (hybrid greedy +
     backtracking, seed-reproducible) with an optional AI top-up and a semantic-duplicate guard;
     Laravel orchestrator + Python processor + Vue UI.
  3. **What we found (results)** — valid, balanced, reproducible papers; unit coverage and
     non-repetition enforced; successful past-paper extraction; AI closes feasibility gaps.
  4. **What it means** — a working hybrid system where the deterministic algorithm, not the AI, owns
     the final paper.
- **End with a `Keywords:` line** (the accepted TU sample does this; strengthens search/indexing):
  e.g. *Question paper generation, Constraint satisfaction, Backtracking, Blueprint, Semantic
  similarity, Laravel.*
- Written **Original** (GUIDE §5A) — own synthesis, telegraphic but complete. Write it **last** and
  revise it every time the body changes.

---

## 2. Chapter 1 §1.1 Introduction — rubric (the funnel)

Source: [introduction] (Cals & Kotz) + Bhatta introduction slide.

- **Funnel shape:** broad → narrow. Open with the general context (assessment / exam paper preparation
  in TU-style courses), narrow to the specific gap (manual setting doesn't scale and can't guarantee
  balance/non-repetition), land on **QForge's aim**.
- **Sets the scene / bigger perspective** and reflects the concepts in the project *title* (Bhatta).
- **Length discipline:** the introduction is not a full literature survey — keep it to roughly
  **10–15%** of the report; the deep review belongs in Ch2.
- **The last paragraph must state the aim** and briefly say how the project answers it — this is the
  bridge into the rest of the report (mirrors §1.6 Report Organization).
- Present tense for established facts, past tense for what the project did. **Original** voice.

---

## 3. Chapter 1 §1.2 Problem Statement — rubric (the 9 attributes)

Source: [problem statement] (Bruce, *Lib. Inf. Sci. Res.* editorial — Hernon & Metoyer-Duran's nine
attributes). A problem statement is **not** a purpose, hypothesis, or summary. Check all nine:

1. **Clarity and precision** — no sweeping generalisations, no irresponsible claims.
2. States **what is studied**, avoiding value-laden words.
3. Identifies an **overarching question** and the **key factors/variables** (here: coverage, mark
   distribution, question-type mix, non-repetition, time).
4. Identifies **key concepts and terms** (blueprint, slot, constraint, candidate — GUIDE §5 rule 7).
5. Articulates the **boundaries / parameters** (single-institution, TU-style papers, admin-reviewed
   extraction).
6. Has some **generalizability** (applies beyond one subject/exam).
7. Conveys **importance and justification** — answers the **"so what?"**; shows the problem is not
   trivial (this is the attribute most often missing — make it explicit).
8. **No unnecessary jargon.**
9. Conveys **more than a descriptive snapshot** — frames a problem to be *solved*, not merely described.

Non-ambiguous statement of *what* the problem is, *how* and *why* it matters (Bhatta §1.2).

---

## 4. Chapter 1 §1.3 Objectives — rubric

- **Listed as bullet points**, concise, specific to QForge (Bhatta).
- Each objective **maps to the problem** in §1.2 and is **measurable/verifiable** (so Ch5 Result
  Analysis and Ch6 Conclusion can report each as met). GUIDE §3 has the candidate list.

---

## 5. Chapter 2 §2.2 Literature Review — rubric

Source: [literature review] (Denney & Tewksbury) + Bhatta §2.1.

- A **comprehensive overview of prior work** that both shows **what is known** and **what is not yet
  known**, thereby **setting up the rationale/gap** QForge fills — the review must *lead to* QForge,
  not sit beside it.
- **Organise by themes/subthemes**, not source-by-source. A "In [1] … In [2] … In [3] …" wall (as in
  the Nepal Tourism sample) is the *weak* pattern to avoid — synthesise instead: group prior
  paper-generation approaches (random, GA/ACO, backtracking, AI/LLM), compare, then position QForge's
  hybrid design against them.
- **Paraphrase + cite every source** (GUIDE §5A) — never lift an abstract. Appropriate citation is
  essential (Bhatta). Gaps → `{{CITATION NEEDED}}`, never a fabricated source.
- End with the **gap statement** that justifies the project.

---

## 6. Chapters 3–4 System Analysis & Design — rubric

- **OO consistency** (GUIDE §0). Analysis models the *problem* (use case, class, object, state,
  sequence, activity); design *refines* them to implementation (refined class/…, component, deployment)
  + the relational schema as the persistence view. Never mix in the structured (DFD-primary) approach.
- **Right content in the right chapter** (examiner criterion 2): requirements & problem-level models in
  Ch3; solution-level refinement, architecture, and **§4.2 Algorithm Details** in Ch4.
- **Every diagram is referenced in the text before it appears**, numbered and captioned (figure caption
  **below**, table caption **above** — GUIDE §7). Reconcile every diagram against the real code before
  drawing (GUIDE §4).
- Feasibility (§3.1.2) must be **contextualised** (Bhatta's "Contextualization needed!"): schedule
  feasibility uses the real **M1–M6 Gantt**, economic feasibility notes self-hosted Ollama = no
  per-call cost.

---

## 7. Chapter 5 Implementation & Testing — rubric

- **§5.1.1 Tools Used:** describe each tool **in the context of how QForge uses it** — never a textbook
  blurb. "Avoid basic definitions" (Bhatta, twice, in red).
- **§5.1.2 Modules:** describe classes/methods/algorithms; **do not paste whole files** — full snippets
  go to the Appendix.
- **§5.2 Testing — present a test-case table** with **both positive and negative cases** (Bhatta, in
  red; the Nepal sample models the columnar format). Suggested columns:

  | Test ID | Scenario | Input | Expected | Actual | Result |
  |---|---|---|---|---|---|

  Cover each strategy: **Unit** (map to the algorithm phases — feasible→valid, unit-coverage,
  repetition-exclusion, infeasible→missing_slots, backtracking recovery, seeded variation, within-paper
  dedup) and **System** (API feature tests; pytest extraction). Include deliberate failure cases
  (infeasible blueprint, invalid upload) as negative tests. Avoid basic definitions of "what is unit
  testing".
- **§5.3 Result Analysis:** report against the §1.3 objectives; representative outputs/screenshots
  cross-referenced to the Appendix.

---

## 8. Chapter 6 Conclusion & Recommendations — rubric

- Summarise **how the project was done**, **what it achieved**, its **contribution**, and **whether the
  initial objectives were met — and if any were not, explain why** (Bhatta §6.1).
- State the **major results/findings** plainly.
- **§6.2 Recommendations / Future Work** — honest roadmap (GUIDE §3; reframe VIVA-GUIDE weak points).

---

## 9. Plagiarism & integrity — the five rules (apply while drafting)

Source: [avoiding plagiarism] (Ober, Simon & Elson) — reinforces GUIDE §5A.

1. **Don't copy.** No verbatim mimicking of any source — *including your own* previously written text
   and QForge's own internal docs. Very short quotations only, in quotation marks, cited.
2. **Write in your own words.** Paraphrasing = new structure *and* wording, cited at the end of the
   passage; not synonym-swapping (that's patchwriting — GUIDE §5A).
3. **When in doubt, cite.** But if you're citing on every line, you're not writing enough of your own —
   rewrite. Commonly-understood concepts still need attribution when discussed.
4. **Don't recycle** images/figures/tables/text from elsewhere without citing (self-plagiarism counts).
5. **Ask permission / attribute** for any external figure, table, or dataset.

Plagiarism — accidental or deliberate — is a serious academic offence and a live examiner criterion
(#5). Proper citation is the defence.

---

## 10. Final proof pass (Bhatta "Don't Forget")

Before declaring the report done: **review → proofread → check & correct**, at least a week before the
defense, with the whole document fresh in mind. Verify against the examiner's six criteria in §0,
the per-section rubrics above, and the format standards in GUIDE §7.

---

*End. Update this if the supervisor issues new guidance. Bracketed `[tags]` above map to the source
PDFs the supervisor provided.*
