# Chapter 1: Introduction

## 1.1 Introduction

Examinations remain the primary instrument by which universities certify what a student has
learned, and the question paper is the artefact that carries that judgement. In Tribhuvan
University–style courses, a paper is not written freely: it must obey a defined structure — a fixed
set of sections, a prescribed number of questions of each type, an exact total of marks — while at
the same time drawing across the breadth of the syllabus so that no unit is examined and none is
quietly ignored. A well-made paper therefore balances several competing requirements at once, and a
teacher setting one must hold all of them in mind together.

In practice this work is done by hand. A teacher assembles each paper individually, choosing
questions from memory, from personal files, or from previous years' papers, and then checking by eye
that the counts, the marks, the type mix, and the unit spread all come out right. The approach does
not scale: it is slow, it is easy to make an arithmetic or coverage error under time pressure, and it
offers no systematic guard against repetition, so a question — or a lightly reworded version of it —
can reappear across consecutive years without anyone noticing. Nothing about the manual process
records *why* a given paper is valid, which makes a paper hard to audit and hard to reproduce, and
the effort of setting one from a blank page is repeated in full every examination cycle.

QForge was built to remove that manual burden without removing the teacher's control over the
result. The aim of the project was to produce a working system in which a teacher describes the
*shape* of an examination once, as a reusable **blueprint**, and the system then assembles a valid,
balanced paper from a bank of approved questions automatically. At its centre is a deterministic
constraint-based generation algorithm, written from scratch, that treats paper setting as a
constraint-satisfaction problem — filling every position, or **slot**, in the paper with a question
that honours the hard rules of type, marks, allowed unit, coverage, and non-repetition, while
preferring variety where a choice remains. An optional artificial-intelligence component can enlarge
the question bank when it is too thin to satisfy a blueprint, but it only ever proposes candidate
questions; the algorithm alone decides what appears on the paper. The remainder of this report sets
out the problem in detail, the objectives that followed from it, and the analysis, design,
implementation, and testing of the system that met them.

## 1.2 Problem Statement

Preparing a question paper by hand is a constraint-laden task that becomes error-prone and
labour-intensive precisely because several requirements must be satisfied simultaneously and checked
against one another. The problem this project addresses is how to generate an examination paper that
is *structurally correct, balanced across the syllabus, free of recent repetition, and reproducible*,
without depending on a teacher to reconcile all of those requirements manually for every paper.

The requirement has several interacting factors. A paper must satisfy its **structure** — the right
number of questions of each type in each section, summing to the intended total marks. It must
achieve **unit coverage** — every unit the blueprint admits should be represented, so the examination
is not skewed toward whichever topics the setter recalled most readily. It must respect a
**mark distribution** and a **question-type mix** fixed in advance. It must avoid **repetition** —
questions used in recent papers, and questions from recent real examinations, should not resurface.
And it must do all of this under a practical **time** constraint, since a teacher's effort is finite
and the setting cycle recurs. Manual setting couples these factors together in one person's head,
where a change to satisfy one requirement can silently break another.

The scope of the problem is bounded deliberately. The concern is the generation of TU-style written
papers from a curated, institution-owned question bank; it is not automated grading, not proctoring,
and not the authoring of question content itself, which remains a human responsibility subject to
review. Within those boundaries, however, the problem is general rather than specific to one course:
the same structural, coverage, and repetition pressures apply to any subject whose examinations are
organised into units and marks, so a solution framed at the level of blueprints and constraints
applies across a department's offerings rather than to a single paper.

The justification for solving it is direct. Setting papers manually consumes hours of skilled staff
time each cycle for a result that is neither auditable nor guaranteed correct, and the absence of any
systematic repetition guard is a genuine fairness and integrity risk when students can obtain and
memorise recent papers. A system that produces balanced papers automatically, proves each paper
correct against an explicit checklist, and enforces non-repetition therefore addresses a real,
recurring, and non-trivial cost — it does more than describe the difficulty of paper setting; it
frames it as a problem to be solved reliably and repeatably.

## 1.3 Objectives

The objectives of the project, each of which the delivered system meets and each of which maps to a
factor in the problem above, were:

- To build a **constraint-based, blueprint-driven generator** that produces a valid, balanced paper
  from a reusable blueprint — every slot filled, the required per-section counts and total marks
  satisfied — or, when no such paper exists, a precise account of what is missing.
- To **enforce unit coverage, per-unit maximum caps, and cross-paper non-repetition** as hard rules
  of generation, so that structure, balance, and freedom from recent repetition are guaranteed rather
  than checked after the fact.
- To make generation **reproducible and explainable** — deterministic given a recorded **seed**, and
  accompanied by a per-constraint pass/fail result so that any paper can be reproduced exactly and any
  failure is stated as named **missing slots**.
- To **ingest past-paper and syllabus documents** through an extraction pipeline (digital text with
  optical-character-recognition fallback and heuristic parsing) into an **admin-reviewed** question
  bank, so the bank can be grown from existing material without unverified questions entering it.
- To provide an **optional AI top-up** that expands the bank with grounded candidate questions when a
  blueprint is otherwise infeasible — guarded against semantic duplicates and kept strictly
  supportive of the algorithm — and to **export** finished papers to PDF and DOCX.

## 1.4 Scope and Limitation

The system covers the full path from a curated question bank to a finished paper. In scope are the
authoring of blueprints; deterministic generation of papers from them with coverage, cap, and
repetition enforcement; management of the question bank; ingestion of past-paper and syllabus PDFs
into that bank through admin-reviewed extraction; an optional AI top-up that enlarges the bank when a
blueprint cannot otherwise be satisfied; and export of saved papers to PDF and DOCX. Two operating
roles are supported — an Admin who stewards the catalog and the bank, and a Teacher who authors
blueprints and papers.

The limitations are stated honestly and reflect deliberate design choices rather than gaps. The
artificial-intelligence component is **supportive only**: it drafts candidate question text, which
the system then stamps with type, marks, and unit and subjects to the same constraints as any other
question — the algorithm, not the model, owns every selection, so the AI cannot cause an invalid
paper. Document extraction is heuristic by design and therefore **requires admin review**: parsed
candidates enter the bank only after a human approves them, which trades full automation for control
over what becomes examinable. Semantic duplicate detection is threshold-based and so catches typical
paraphrases but is not an absolute guarantee. Finally, the system assumes a **single-institution**
deployment with TU-style, unit-and-marks-structured papers and an English-language question corpus;
multi-institution operation and other examination formats are outside the present scope.

## 1.5 Development Methodology

The project followed an **iterative, milestone-first** methodology in which each milestone delivered
an independently demonstrable slice of the system, working end-to-end against the live backend rather
than as an isolated component. Crucially, the sequencing was **algorithm-first**: because the
constraint-based generation engine is the academic centrepiece and the highest-risk part of the
build, it was implemented and proven early — against seeded, in-memory data — before the messier
document-processing and AI pipelines were added on top of a foundation already known to be correct.

The build proceeded through six principal milestones. The first established the domain foundation —
the schema, the CRUD interfaces, and role-based access. The second delivered the generation engine
itself. The third added the paper lifecycle, export, and cross-paper repetition control. The fourth
built the PDF extraction pipeline and, in a follow-on, syllabus import. The fifth added AI bank
expansion, and the sixth added retrieval-augmented grounding with the semantic-duplicate guard. Each
milestone left the system in a working, demoable state, and the algorithm's correctness was pinned by
a growing unit-test suite throughout, so that later additions could not silently regress the engine
that underpins the whole system.

## 1.6 Report Organization

The remainder of this report is organised as follows. **Chapter 2** reviews the fundamental concepts
the system rests on — constraint satisfaction, greedy heuristics and backtracking, document
extraction, semantic similarity, and AI-assisted generation — and surveys prior work on automated
paper and question generation, positioning QForge's hybrid design against it. **Chapter 3** presents
the system analysis: the functional and non-functional requirements, a feasibility assessment, and
the object-oriented models of the problem domain. **Chapter 4** presents the system design, refining
those models to the level at which they were implemented, adding the component, deployment, and
persistence views, and giving a full treatment of the generation algorithm — the centre of gravity of
the report. **Chapter 5** describes the implementation of the real modules and the testing strategy,
including the unit and system test suites and an analysis of the results against the objectives.
**Chapter 6** concludes by restating how the objectives were met and setting out honest
recommendations for future work.

## Progress

- ✅ 1.1 Introduction — report/01-introduction.md
- ✅ 1.2 Problem Statement — report/01-introduction.md
- ✅ 1.3 Objectives — report/01-introduction.md
- ✅ 1.4 Scope and Limitation — report/01-introduction.md
- ✅ 1.5 Development Methodology — report/01-introduction.md
- ✅ 1.6 Report Organization — report/01-introduction.md
