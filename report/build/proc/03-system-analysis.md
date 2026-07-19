# Chapter 3: System Analysis

System analysis fixes *what* QForge must do and models the problem it solves, before any
implementation detail is settled. QForge was built as a hybrid question-paper generator: a
deterministic, blueprint-driven engine owns the final paper, while an optional artificial-intelligence
component only supplements the question bank when the engine cannot otherwise satisfy a request. This
chapter records the requirements that shaped the system, the feasibility of building it under the
project's constraints, and the object-oriented models of the problem domain. Because QForge is a
completed system, the analysis is presented as realised rather than proposed, and every model is drawn
from the delivered code rather than from an early sketch. The solution-level refinements — the service
class structure, the architecture, and the relational persistence schema — are deferred to the design
chapter, where they belong.

## 3.1 System Analysis

### 3.1.1 Requirement Analysis

Requirement analysis separates the behaviour the system exposes to its users (functional
requirements) from the qualities that behaviour must exhibit (non-functional requirements). QForge's
requirements were derived from the two operating roles it serves and from the end-to-end workflow of
turning a syllabus and a bank of past questions into a balanced examination paper.

#### Functional Requirements

QForge recognises exactly two human roles, stored on each user account and derived on the server at
login: the **Admin**, who acts as the content steward, and the **Teacher**, who authors papers. A
third, secondary actor — the **System (the Python processing service)** — participates only when
Laravel delegates document processing or text generation to it; it never initiates work and is never
reached directly by a user. The frontend communicates only with the Laravel application, and Laravel
in turn orchestrates the Python service, so the artificial-intelligence contribution remains
supportive rather than authoritative: the deterministic engine always controls the paper that is
produced.

The Admin curates everything the generator later draws upon. An Admin provisions user accounts and
assigns roles, maintains the catalog of subjects and their units, and owns the question bank with full
create, read, update, and delete access. Because much of the bank originates from real examinations,
the Admin also uploads past-paper and syllabus documents, and works the resulting review queue —
approving or rejecting the candidates the extraction pipeline proposes, and confirming a parsed
syllabus into subjects and units before it is written to the catalog. There is no public
registration; every account is created by an Admin.

The Teacher consumes that curated material to produce papers. A Teacher builds a **blueprint** — the
specification of the paper's sections, per-section question counts and marks, allowed units, unit
coverage rules, per-unit maximums, and repetition exclusions — and then requests generation from it.
When the approved bank is too thin to satisfy a blueprint, the Teacher may trigger an AI **top-up**
that expands the bank with newly generated questions and then regenerate. A produced paper can be
saved, edited, exported to PDF or DOCX, and reviewed later through history and analytics screens.
Blueprints and papers are private to their owner: a Teacher sees only their own.

These capabilities and the actors that exercise them are summarised in the use case diagram of Figure
3.1, which also shows where one use case draws in another — extraction is included by every document
upload, AI question generation is included by the bank top-up, and the top-up itself extends
generation as an optional recovery path.

![](/var/www/Q-Forge/report/build/img/fig-3.1-uc-analysis.png)

*Figure 3.1: Use case diagram — actors and use cases of QForge.*

The principal use cases are described in Table 3.1, which records each actor, the use case, and its
behaviour.

*Table 3.1: Use case descriptions.*

| Actor | Use case | Description |
|---|---|---|
| Admin | Manage users & roles | Create, update, and remove accounts and assign the admin/teacher role; the last remaining admin cannot be demoted or self-deleted. |
| Admin | Manage subjects & units | Maintain the catalog of subjects (keyed by public code) and the ordered units within each. |
| Admin | Manage question bank | Full CRUD over questions, including type, marks, unit tagging, and status. |
| Admin | Upload past-paper / syllabus PDF | Submit a document for asynchronous processing; includes *extract candidates from PDF*. |
| Admin | Review extracted candidates | Approve or reject parsed candidates (individually or in bulk); approval extends the bank. |
| Admin | Import syllabus into catalog | Confirm a parsed syllabus proposal, creating the subject and any missing units. |
| Teacher | Build blueprint | Define sections, counts, marks, allowed units, coverage rules, per-unit maximums, and exclusions. |
| Teacher | Generate paper | Run the deterministic engine against a blueprint to obtain a paper preview or a precise shortfall. |
| Teacher | Expand bank with AI (top-up) | On an infeasible blueprint, queue AI generation of the missing questions; includes *generate AI questions*. |
| Teacher | Save & edit paper | Persist a generated preview and adjust it. |
| Teacher | Export paper | Render a saved paper to PDF or DOCX. |
| Teacher | View history & analytics | Browse previously generated papers and usage aggregates. |
| System | Extract candidates from PDF | Parse an uploaded document into question candidates, with OCR fallback for scanned pages. |
| System | Generate AI questions | Produce grounded candidate questions for named missing slots via the local language model. |

#### Non-Functional Requirements

The qualities QForge had to exhibit follow directly from its purpose as an examination tool that must
be trustworthy, defensible, and deployable inside a single institution.

**Determinism and reproducibility.** Because an examination paper must be auditable and repeatable,
the generation engine is a pure function of the question bank and a single integer **seed**. A null
seed reproduces the original identifier-ordered paper, which keeps the test suite stable; a supplied
seed yields a different but equally valid paper, so a regenerate action and two teachers working at
once no longer collide on one frozen result. Any given seed always reproduces the same paper, which is
the property that makes the output defensible.

**Performance and responsiveness.** Generation itself runs synchronously in memory as plain
server-side logic, so a Teacher receives a paper preview without waiting on external services. Every
heavy or slow task — PDF text extraction, optical character recognition, AI question generation, and
embedding — is instead dispatched to a background queue and polled for completion, so a single slow
document never blocks the interface.

**Security.** Access is authenticated with token-based bearer credentials issued at login, and every
protected route runs behind role middleware that admits only the permitted role and refuses everyone
else. There is no public signup, roles are decided server-side rather than trusted from the client,
and blueprints and papers are owner-scoped so one Teacher cannot read another's work.

**Maintainability.** The system keeps strict service boundaries: Laravel is the orchestrator that owns
the database, the business logic, and the generation algorithm; the Python service is a stateless
processor with no database of its own; and the frontend is presentation only. Keeping the language
model behind a swappable provider and the paper renderers behind one shared view-model localises
future change.

**Portability.** The whole system — application, worker, database, cache, vector index, language
model, and frontend — is described as a set of containers, so a reviewer or an institution can stand
the platform up reproducibly on their own hardware without per-machine configuration.
### 3.1.2 Feasibility Analysis

Feasibility was assessed against the resources and constraints of an undergraduate project delivered
by a small team on institution-scale hardware, rather than in the abstract.

**Technical feasibility.** Every component of QForge is built on a mature, well-documented technology
with a large support base: a PHP application framework for the orchestrator and API, a reactive
JavaScript framework for the interface, a Python web framework for the processing service, a
relational database for the source of truth, an in-memory store with a queue dashboard for background
work, a vector index for semantic retrieval, and a self-hostable language-model runtime. Critically,
the academic centrepiece — the constraint-based generation engine — is implemented in plain
application code with no dependency on an external constraint solver, so its behaviour is fully owned,
inspectable, and unit-testable. This removed the main technical risk: correctness of the algorithm did
not hinge on a third-party black box.

**Operational feasibility.** The design mirrors how examination papers are actually prepared in a
department. Content stewardship (the catalog and the bank) is separated from paper authoring, matching
the real division between those who maintain question stores and those who set papers. Extraction and
AI generation never write directly into the usable bank: a human reviews extracted candidates before
approval, so the automation assists staff without displacing their judgement. The workflow therefore
slots into an existing process rather than demanding a new one.

**Economic feasibility.** QForge is composed entirely of open-source software, so there are no
licensing costs. The decision that most affects running cost is that language-model inference is served
by a **self-hosted** runtime rather than a commercial API: AI question generation and embedding incur
no per-call charge and send no institutional content to an outside vendor. The recurring cost is
therefore limited to the institution's own hardware and electricity, which makes routine and
repeated use economically sustainable.

**Schedule feasibility.** The project was sequenced algorithm-first and delivered as a series of
independently demonstrable milestones, so that the highest-risk, highest-value component — the
generation engine — was proven early against seeded data before the messier document and AI pipelines
were added. The milestone order was: the domain foundation and CRUD (M1); the generation engine (M2);
the paper lifecycle, export, and repetition control (M3); the PDF extraction pipeline (M4) and
syllabus import (M4.1); AI bank expansion (M5); and semantic retrieval with the duplicate guard (M6).
This ordering is shown as a schedule in Figure 3.2. The milestone sequence is real; the calendar dates
on each bar are left as placeholders to be completed with the project's actual dates before the final
report is produced.


> **[FIGURE PLACEHOLDER — Figure3.2.]** diagram source did not render (contains unresolved `{{PLACEHOLDER}}` tokens); insert the finished figure here before submission.


*Figure 3.2: Project schedule — the algorithm-first milestone sequence (calendar dates are
placeholders pending the project's actual timeline).*
### 3.1.3 Analysis (Object-Oriented)

The problem domain is modelled with the object-oriented approach used consistently across the
analysis and design of QForge. This section presents the problem-level models: the classes and their
relationships, one concrete instance snapshot, the lifecycle of the key stateful entities, the two
principal interaction sequences, and the control flow of the generation algorithm. Each model was
reconciled against the delivered code before it was drawn; the refined service classes, the deployment
topology, and the relational schema are treated in the design chapter.

#### Class Diagram

The domain revolves around a small set of collaborating classes. A `Subject` owns an ordered set of
`Unit` objects and scopes a bank of `Question` objects; a `Question` is filed under one primary `Unit`
but may be tagged with several units at once, so a question that genuinely spans topics remains
eligible whenever any of its units is allowed. A `Blueprint`, owned by a user and targeting a subject,
holds its specification in a structured definition. A `Paper` is generated from a blueprint for a
subject and is composed of `PaperQuestion` entries, each a snapshot of the chosen question at the
moment of generation — recording its section, display number, marks, unit label, and whether it came
from AI. A `DocumentUpload` records an uploaded file and the progress of its asynchronous processing.
These classes and their multiplicities are shown in Figure 3.3.

![](/var/www/Q-Forge/report/build/img/fig-3.3-class-analysis.png)

*Figure 3.3: Analysis-level class diagram of the QForge problem domain.*

#### Object Diagram

To make the class model concrete, Figure 3.4 shows a single runtime snapshot. A blueprint for the
subject `CS301` is compiled into an ordered set of **slots**; the generation engine fills those slots
with specific approved `Question` instances from the bank; and the resulting saved `Paper` records each
choice as a `PaperQuestion` snapshot. The slot is a transient object produced during generation rather
than a stored entity, which is why it appears here as an instance without a persistent counterpart in
the class diagram.

![](/var/www/Q-Forge/report/build/img/fig-3.4-object-snapshot.png)

*Figure 3.4: Object diagram — a worked instance snapshot of one generated paper.*

#### State Diagrams

Three entities carry a lifecycle worth modelling explicitly. The first is the `Question`, whose
`status` governs whether the engine may use it. As Figure 3.5 shows, an extracted or manually created
question enters as *pending* and becomes usable only when an Admin approves it, or is set aside when
rejected. Questions produced by the AI top-up are the exception: they are stored directly as
*approved*, because a pending question is invisible to the candidate filter and could therefore never
close the shortfall it was generated to fix. Only approved questions are eligible for generation.

![](/var/www/Q-Forge/report/build/img/fig-3.5-state-question.png)

*Figure 3.5: State diagram — Question approval lifecycle.*

The second is the `Paper`. As Figure 3.6 shows, generation produces an unsaved **preview** that holds
its seed but is persisted to nothing; regenerating simply draws a fresh seed and produces another
preview. Only an explicit save re-runs the engine deterministically from the seed and persists the
paper, after which it may be exported. This preview-first lifecycle keeps discarded attempts out of a
teacher's history. (An earlier *draft* state, retained as a legacy value, is no longer produced by the
current flow.)

![](/var/www/Q-Forge/report/build/img/fig-3.6-state-paper.png)

*Figure 3.6: State diagram — Paper preview/save/export lifecycle.*

The third is the `document_uploads` record, which tracks an asynchronous extraction. As Figure 3.7
shows, an upload begins as *uploaded*, moves to *processing* when the background job picks it up, and
finishes either as *parsed*, when the processing service returns candidates, or as *failed*. Both end
states are terminal.

![](/var/www/Q-Forge/report/build/img/fig-3.7-state-upload.png)

*Figure 3.7: State diagram — document_uploads processing lifecycle.*

#### Sequence Diagrams

The two interactions that define the system are generating a paper and extracting questions from a
document. Figure 3.8 traces generation: the Teacher requests a paper from a blueprint, the compiler
turns the blueprint into ordered slots, the engine filters and greedily selects a **candidate** for
each slot, validation checks the assembled paper, and the result is returned as an unsaved preview
together with its seed. If the greedy pass and validation cannot produce a valid paper, backtracking
attempts alternative selections; if the bank is genuinely too thin, the response instead names the
exact **missing slots**. Persistence occurs only on a subsequent, explicit save.


![](/var/www/Q-Forge/report/build/img/fig-3.8-seq-generate.png)


*Figure 3.8: Sequence diagram — generating a paper (deterministic, synchronous, preview-first).*

Figure 3.9 traces extraction, which is asynchronous. The Admin uploads a document; the API records it
and dispatches a background job, returning immediately while the frontend polls for progress. The job
calls the Python service, which extracts text — falling back to optical character recognition on pages
that carry little or no digital text — and parses the content into candidates. Those candidates are
stored as pending questions, and the Admin later approves them into the bank through the review queue.

![](/var/www/Q-Forge/report/build/img/fig-3.9-seq-extract.png)

*Figure 3.9: Sequence diagram — extracting question candidates from an uploaded document.*

#### Activity Diagram

Finally, Figure 3.10 models the control flow of the generation algorithm itself. The blueprint is
compiled into ordered slots; a greedy pass then walks the slots, filtering the bank for each and
selecting a candidate. Once every slot has been attempted, the constraint validator checks the whole
paper. If all constraints hold, the run succeeds and returns a preview. If not, a bounded backtracking
search tries alternative assignments; only when that too fails does the engine compute and return the
precise **missing slots** describing what the bank lacks. This is the analysis-level view of the
control flow; the detailed treatment of the algorithm's phases, its seed-parameterised determinism,
and the **similarity guard** is the subject of the design chapter.

![](/var/www/Q-Forge/report/build/img/fig-3.10-activity-generate.png)

*Figure 3.10: Activity diagram — control flow of the paper-generation algorithm.*