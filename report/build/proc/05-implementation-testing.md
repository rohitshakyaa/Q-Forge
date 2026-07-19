# Chapter 5: Implementation and Testing

This chapter records how the design of Chapter 4 was realised as a working system and how that
system was verified. It first names the tools that were used and the role each played in QForge,
then walks the principal modules — foregrounding the constraint-based generation engine that is the
project's academic centerpiece — and finally presents the test suite as a table of concrete cases,
mapped to the algorithm's phases, and evaluates the outcome against the objectives set out in
Section 1.3.

## 5.1 Implementation

### 5.1.1 Tools Used

The tools below were not chosen in the abstract; each was selected for a specific responsibility in
QForge's service-oriented architecture, in which **Laravel is the orchestrator, Python a stateless
processor, and Vue a decoupled presentation layer**. They are described here in terms of that role.

**Laravel 12 (PHP 8.2).** Laravel is the source of truth. It owns the MySQL schema, the question
bank, blueprints, papers, and — critically — the deterministic generation algorithm, which lives in
plain PHP service classes under `app/Services/PaperGeneration/` rather than in any framework
machinery. Laravel also exposes the entire public API, authenticates it with **Laravel Sanctum**
token guards, and gates every route by role. It is the only service that talks to the database and
the only one that initiates calls to Python.

**Vue 3 with Pinia and Tailwind CSS 4.** The single-page frontend is presentation only and speaks
exclusively to the Laravel API over `axios`; it never contacts the Python service directly. **Pinia**
holds the client-side state (the authenticated session, the working blueprint, the current paper
preview), **Vue Router** drives the admin and teacher screens, and **Tailwind CSS** supplies the
utility-first styling for the bank, blueprint editor, generate/preview, review-queue, and export
views.

**FastAPI (Python).** The Python service is a self-contained document- and AI-processing worker with
no business logic and no access to the main database. It exposes typed endpoints — `/extract`,
`/generate-questions`, and `/embed` — whose request and response shapes are declared as **Pydantic**
models and returned inside a uniform `Envelope`, so that Laravel always receives structured, validated
JSON rather than free text.

**MySQL.** The relational store behind Laravel holds the domain tables introduced across the
milestones (`subjects`, `units`, `questions`, `blueprints`, `papers`, `paper_questions`,
`document_uploads`, `content_chunks`, and the `question_unit` pivot). Schema evolution is managed
entirely through Laravel migrations, keeping the persistence model versioned alongside the code.

**Redis with Laravel Horizon.** Heavy work is never run inside a web request. PDF extraction and
AI bank-expansion are dispatched as queued jobs onto **Redis**, and **Horizon** supervises the queue
workers and provides visibility into job throughput and failures. This is what lets an upload return
immediately while OCR and parsing proceed in the background, with the frontend polling for progress.

**Qdrant.** The vector database stores 768-dimensional embeddings of approved questions and of
syllabus content chunks. QForge queries it for four purposes: dropping semantic near-duplicates
during AI expansion, flagging look-alike candidates in the review queue, retrieving grounding context
for generation, and — through the within-paper similarity guard — steering the greedy pass away from
placing two near-identical questions in the same paper.

**Ollama.** All AI inference is self-hosted through Ollama, which removes any per-call cost and keeps
question content on-premises. Two models are used: `qwen2.5:3b-instruct` for candidate-question
generation and `nomic-embed-text` for embeddings. This local-model choice is what makes the AI
top-up economically viable for a single institution and is reflected in the feasibility analysis of
Section 3.1.2.

**Docker Compose.** Every service — Laravel, the Vue build, FastAPI, MySQL, Redis, Qdrant, and
Ollama — runs as a container on a shared Compose network, addressed by service name (for example,
Laravel reaches Python through `config('services.python.base_url')`, never a hardcoded URL). This
gives the whole system a single reproducible bring-up and underpins the portability claim among the
non-functional requirements.

The following libraries carry specific processing responsibilities:

- **barryvdh/laravel-dompdf** renders a saved paper's view-model to a print-ready PDF.
- **phpoffice/phpword** renders the same view-model to an editable DOCX, so that a paper-setter can
  make final manual edits after export.
- **pdfplumber** and **pypdf** perform digital text and table extraction from uploaded PDFs — the
  fast path taken when a document already carries a real text layer.
- **pytesseract** (with **Pillow**) provides the per-page OCR fallback that recovers text from
  scanned, image-only past papers when the digital path yields nothing.

### 5.1.2 Implementation Details of Modules

This section describes the real classes and methods that make up QForge. Representative source
listings for the major components are reproduced in Appendix {{APPENDIX_SOURCE_SNIPPETS}}; the
descriptions here are deliberately at the level of responsibilities and control flow rather than
full code.

#### The generation engine

The generation engine is a pipeline of small, single-responsibility classes under
`app/Services/PaperGeneration/`, coordinated by `PaperGenerator`. Its public entry point,
`PaperGenerator::generate(Blueprint $blueprint, ?int $seed, ?SimilarityGuard $guard)`, returns a
`GenerationResult` value object and executes the five phases established in Section 4.2.

**Compilation.** `BlueprintCompiler::compile()` turns the stored blueprint JSON into a
`CompiledBlueprint` — an ordered list of `Slot` objects (each with a question type and mark value),
the set of allowed unit identifiers resolved from unit *names*, and the per-unit maximum caps. It
also records whether the blueprint is *structurally* infeasible (coverage demanding more units than
the slots can carry, or capped units whose caps sum to fewer than the slots), a determination the
generator later uses to avoid a futile search.

**Candidate filtering.** `CandidateFilter::for()` builds the eligible question pool for each slot,
applying the composed exclusion closures — the last-*N*-papers rule (`lastNExclusion`) and the recent
exam-year rule (`examYearExclusion`), which `PaperGenerator::composeExclusions()` merges into a single
closure applied to *both* the greedy and backtracking pools so that repair can never reintroduce an
excluded question.

**Greedy fill.** `GreedySelector::pick()` selects one question per slot, ranking candidates by the
deterministic tuple *(uncovered-unit-first, `used_count`, tie-break key)* so the pass both spreads
coverage across units and behaves as a least-recently-used chooser. Where a real similarity guard is
supplied, the greedy pass *prefers* candidates that are not near-duplicates of questions already
placed in the paper — a soft preference that never empties a pool.

**Validation.** `ConstraintValidator::validate()` checks a completed assignment against every hard
rule — slot counts, total marks, unit coverage, unit maximums, and within-paper non-repetition —
and returns a list of `ConstraintResult` lines, each a named pass/fail. `allPass()` collapses them to
a single verdict. This is what makes a paper *explainable*: the constraint checklist is surfaced to
the user rather than hidden inside the engine.

**Backtracking repair.** When the greedy assignment is incomplete or fails validation and the
blueprint is not structurally infeasible, `BacktrackingResolver::resolve()` runs a depth-first search
over the per-slot candidate pools, pruning branches that violate a cap and confirming full unit
coverage before accepting an assignment. It recovers valid papers that the naive greedy pass alone
cannot find. Similarity is deliberately *not* enforced here, so that a soft preference can never turn
a satisfiable paper infeasible.

**Shortfall explanation.** If no valid assignment exists, `PaperGenerator::computeMissingSlots()`
returns a precise account of what is missing as an ordered list of `MissingSlot` objects — pairing
the scarcest units when the blueprint asks for more units than it has slots — rather than a bare
failure.

**Determinism and the soft guard.** `Support/TieBreaker::key(?int $seed, int $id)` resolves the final
tie between otherwise-equal candidates: a null seed preserves the legacy identifier order (so existing
tests are unaffected), while any given seed produces a reproducible per-seed shuffle. This is what
lets two paper-setters, or a "regenerate" click, obtain different but each fully reproducible papers
from one blueprint. The optional within-paper duplicate guard is defined by the
`Contracts/SimilarityGuard` interface with two implementations: `NullSimilarityGuard`, the default
no-op, and `VectorSimilarityGuard`, which primes itself with the vectors of the chosen questions
(`prime()`) and answers `tooSimilar()` by cosine comparison against Qdrant. The guard fails open — if
RAG is unavailable it simply behaves as the null guard.

#### API controllers

`PaperController` exposes the generation lifecycle. Its `generate()` action draws a fresh
`random_int` seed per request (or accepts a client-supplied one), runs the engine, and returns an
*unsaved* preview (`id: null`) together with the seed and blueprint id — it persists nothing.
`store()` requires a seed and re-generates deterministically from it before persisting the paper as
`status = saved`, bumping `used_count` and `last_used_at` only at that point; because the engine is a
pure function of (blueprint, seed, bank), the saved paper matches the preview. The controller also
serves the owner-scoped `index`, `show`, `update`, `destroy`, `analytics`, and `export` actions, each
gated by role and ownership. The resource controllers for subjects, units, questions, and blueprints
follow the same Sanctum-authenticated, role-gated pattern.

#### Python endpoints

`POST /extract` accepts a path (validated to lie inside the shared volume, so traversal is refused),
extracts a digital text and table layer with pdfplumber, falls back to per-page Tesseract OCR when a
page carries no usable text, runs the heuristic parser to segment the text into candidate questions
with detected marks, types, and unit hints, and returns them — with any parsed syllabus courses —
inside a typed `Envelope`. `POST /generate-questions` validates a bounded request (rejecting a count
out of range or more than two units), grounds the prompt, and delegates to an env-selected
`LLMProvider` — `OllamaProvider` in production or `StubProvider` under test — returning structured
questions, with any malformed model item isolated into an `errors` list rather than allowed to
corrupt the `data`.

#### Background jobs

Two queued jobs keep heavy work off the request path. `ProcessDocumentUpload::handle()` calls the
Python `/extract` endpoint for an uploaded document, records progress on the `document_uploads` row,
and imports the returned candidates into the review queue via `CandidateImporter` — never directly
into the approved bank. `ExpandQuestionBank::handle()` is dispatched as a batch from
`POST /blueprints/{id}/expand-bank`; it re-derives the missing slots server-side, builds grounding
context with `GroundingBuilder` (unit content, syllabus, and up to a few approved exemplars), asks
Python for slightly more questions than needed, drops semantic near-duplicates through
`DuplicateDetector`, stamps each survivor's type and marks from the slot, resolves its unit tags, and
stores it as `source = ai, status = approved` — closing a feasibility gap while keeping the AI strictly
supportive of the algorithm.

#### Export

`Support/PaperViewModel` wraps a saved `Paper` and exposes a rendering-agnostic view of it —
`header()`, `sections()`, `toArray()`, and a `filename()` — so that both export formats draw from one
source. `PaperController::export()` selects the renderer by the `format` query parameter: dompdf for
PDF and PhpWord for DOCX. An unsupported format is rejected rather than silently defaulted.

#### Supporting RAG infrastructure

The retrieval components that surround the engine — `Services/Rag/QdrantClient`, the queued
`SyncQuestionEmbedding`/`SyncSubjectChunks` synchronisers driven by model observers, the
`ContentChunker`/`ContentIndexer` grounding index, and the `SimilarQuestionFinder` review-queue
flagger — are named here as the supporting cast that keeps the vector index consistent with the
approved bank and the syllabus corpus. All of them fail open behind a `RAG_ENABLED` switch, so a
degraded RAG layer never blocks core generation.

## 5.2 Testing

Testing followed the milestone-first build: each milestone shipped with its own green suite, and the
whole was exercised end to end at every stage. The completed system carries **225 Laravel tests and
153 pytest tests, all green** (Post-M6, as recorded in the milestone log). The two subsections below
present the cases as tables, mapping the unit tests to the algorithm's phases and the system tests to
the API and extraction pipeline; each row names the actual test method that backs it, and every case
listed is passing. Both deliberate positive and deliberate negative cases are included — an infeasible
blueprint, an over-capped blueprint, an invalid upload, an unsupported export format, and a
path-traversal attempt are all asserted to fail in the intended way.

### 5.2.1 Unit Testing

The unit suite under `tests/Unit/PaperGeneration/` isolates the generation engine from HTTP and the
database factories, driving it directly so that each algorithm phase is verified on its own. Table 5.1
maps these cases to the phases of Section 4.2.

**Table 5.1: Unit test cases for the generation engine**

| Test ID | Scenario | Input | Expected | Actual | Result |
|---|---|---|---|---|---|
| UT-01 | Feasible blueprint yields an all-green paper (`PaperGeneratorTest::test_generates_a_valid_paper_with_all_constraints_passing_for_a_feasible_blueprint`) | Feasible blueprint; bank large enough to fill every slot | Complete paper; every `constraint_results` line passes; `missing_slots` empty | As expected | Pass |
| UT-02 | Unit coverage enforced (`PaperGeneratorTest::test_enforces_unit_coverage_every_allowed_unit_appears`) | Blueprint whose allowed units must all appear | Each allowed unit is represented in the selected questions | As expected | Pass |
| UT-03 | No question repeats within one paper (`PaperGeneratorTest::test_never_repeats_a_question_within_a_single_paper`) | Blueprint with more slots than distinct near-duplicates | All selected question ids are distinct | As expected | Pass |
| UT-04 *(negative)* | Thin bank reports a precise shortfall (`PaperGeneratorTest::test_reports_a_precise_shortfall_when_the_bank_is_too_thin`) | Blueprint requiring more questions than the bank holds | `satisfiable = false` with populated, named `missing_slots` | As expected | Pass |
| UT-05 | Backtracking recovers a paper greedy misses (`PaperGeneratorTest::test_backtracking_recovers_a_valid_paper_the_naive_greedy_pass_fails_to_find`) | Bank arranged so a greedy pick strands a later slot | A fully valid assignment is found via repair | As expected | Pass |
| UT-06 | Per-unit maximum cap honoured (`PaperGeneratorTest::test_unit_cap_limits_how_many_questions_a_unit_contributes`) | Blueprint capping one unit's contribution | No unit exceeds its cap; "Unit maximums" line passes | As expected | Pass |
| UT-07 *(negative)* | Caps summing below slots are infeasible (`PaperGeneratorTest::test_caps_summing_below_the_slot_count_are_structurally_infeasible`) | Every unit capped; Σcaps < slots | Flagged structurally infeasible; no backtrack attempted | As expected | Pass |
| UT-08 | Cross-paper exclusion of recent papers (`PaperGeneratorTest::test_excludes_questions_used_in_the_last_n_papers`) | Blueprint with a last-*N*-papers window and prior papers | Questions from the last *N* papers never reappear | As expected | Pass |
| UT-09 | Recent exam-year exclusion (`PaperGeneratorTest::test_excludes_questions_from_a_recent_exam_year`) | Rule excluding questions from the last *N* exam years | Year-tagged questions in the window are dropped; year-less ones stay eligible | As expected | Pass |
| UT-10 | Seeded variation is reproducible (`PaperGeneratorTest::test_same_seed_is_reproducible_and_different_seeds_vary_the_paper`; `TieBreakerTest::test_a_given_seed_is_stable_across_calls`, `::test_different_seeds_reorder_the_same_ids`) | One blueprint generated under equal and differing seeds | Same seed → identical paper; different seed → a different valid paper | As expected | Pass |
| UT-11 | Soft within-paper dedup never causes a shortfall (`PaperGeneratorTest::test_greedy_pass_avoids_a_near_duplicate_when_an_alternative_exists`, `::test_duplicate_avoidance_never_causes_a_shortfall`) | Bank with a near-duplicate plus a distinct alternative | The distinct alternative is preferred; paper still fills | As expected | Pass |
| UT-12 | Similarity guard fails open and flags by cosine (`VectorSimilarityGuardTest::test_fails_open_when_rag_is_disabled`, `::test_flags_near_duplicates_by_cosine_over_the_threshold`, `::test_distinct_directions_are_not_duplicates`) | Guard with RAG disabled, then with vectors above/below threshold | Disabled → no-op; above threshold → flagged; distinct → not flagged | As expected | Pass |

### 5.2.2 System Testing

The system suite exercises the running services through their public surfaces: Laravel feature tests
issue authenticated HTTP requests against the API (asserting behaviour, persistence, role gating, and
export bytes), while the pytest suite drives the Python service against real digital and scanned PDF
fixtures, including OCR. Table 5.2 lists representative cases; the `ST-` rows are Laravel feature
tests and the `PY-` rows are pytest cases.

**Table 5.2: System test cases (API feature tests and Python extraction)**

| Test ID | Scenario | Input | Expected | Actual | Result |
|---|---|---|---|---|---|
| ST-01 | Generate returns a preview and persists nothing (`PaperGenerateTest::test_generate_returns_a_preview_and_persists_nothing`) | `POST /papers/generate` for a feasible blueprint | Paper with `id: null` + a `seed`; no `papers` row written | As expected | Pass |
| ST-02 | Saving a preview persists it (`PaperGenerateTest::test_saving_a_preview_persists_it_and_bumps_used_count`) | `POST /papers` with the preview's seed | Paper saved as `status=saved`; `used_count` bumped | As expected | Pass |
| ST-03 *(negative)* | Infeasible blueprint returns missing slots, persists nothing (`PaperGenerateTest::test_returns_missing_slots_and_persists_nothing_for_infeasible_blueprint`) | `POST /papers/generate` for an unsatisfiable blueprint | `missing_slots` returned; no paper persisted | As expected | Pass |
| ST-04 *(negative)* | Generation is role- and owner-gated (`PaperGenerateTest::test_admin_cannot_generate_papers`, `::test_cannot_generate_from_another_teachers_blueprint`) | Admin token; another teacher's blueprint | Requests forbidden | As expected | Pass |
| ST-05 | Repeated generation excludes recent questions (`PaperGenerateTest::test_second_generation_excludes_questions_from_the_last_n_papers`) | Two successive generate→save cycles | Second paper omits the first's questions | As expected | Pass |
| ST-06 | PDF export succeeds and marks the paper (`PaperExportTest::test_export_returns_a_valid_pdf_and_marks_the_paper_exported`) | `GET /papers/{id}/export?format=pdf` on a saved paper | Valid PDF bytes; paper flagged exported | As expected | Pass |
| ST-07 | DOCX export succeeds (`PaperExportTest::test_export_returns_a_valid_docx_and_increments_export_count`) | `...export?format=docx` | Valid DOCX; export count incremented | As expected | Pass |
| ST-08 *(negative)* | Unsupported export format is rejected (`PaperExportTest::test_export_rejects_an_unsupported_format`) | `...export?format=xlsx` | Request rejected, no file produced | As expected | Pass |
| ST-09 | Upload stores the file and queues extraction (`DocumentUploadApiTest::test_upload_stores_the_file_and_dispatches_the_extraction_job`) | `POST /uploads` with a PDF | File saved to shared volume; `ProcessDocumentUpload` dispatched | As expected | Pass |
| ST-10 *(negative)* | Non-PDF upload refused (`DocumentUploadApiTest::test_only_pdfs_are_accepted`) | Upload of a non-PDF file | Validation error; nothing stored | As expected | Pass |
| ST-11 *(negative)* | Teachers cannot upload (`DocumentUploadApiTest::test_teachers_cannot_upload`) | Upload with a teacher token | Forbidden | As expected | Pass |
| ST-12 | AI expansion dispatches a batch for an infeasible blueprint (`BankExpansionTest::test_expand_dispatches_a_batch_for_an_infeasible_blueprint`) | `POST /blueprints/{id}/expand-bank` on an infeasible blueprint | Batch dispatched; `{ jobId }` returned | As expected | Pass |
| ST-13 *(negative)* | Structurally infeasible blueprint refused for expansion (`BankExpansionTest::test_expand_refuses_a_structurally_infeasible_blueprint`) | Expand on a structurally impossible blueprint | Refused as not expandable | As expected | Pass |
| PY-01 | Digital extraction without OCR (`test_extract.py::test_returns_candidates_without_ocr`) | PDF with a real text layer | Candidate questions returned via the fast path | As expected | Pass |
| PY-02 | Marks, types, and units detected (`test_extract.py::test_detects_marks_types_and_units`) | Past-paper PDF with mark and unit annotations | Candidates carry parsed marks, types, unit hints | As expected | Pass |
| PY-03 | OCR fallback recovers scanned text (`test_extract.py::test_ocr_fallback_recovers_text_from_an_image_only_pdf`) | Image-only (scanned) PDF | Text recovered by Tesseract; candidates produced | As expected | Pass |
| PY-04 *(negative)* | Letterhead is not treated as a question (`test_extract.py::test_letterhead_is_not_a_candidate`) | PDF with header/letterhead text | Non-question text excluded from candidates | As expected | Pass |
| PY-05 *(negative)* | Path traversal refused (`test_extract.py::test_traversal_out_of_the_volume_is_refused`, `::test_path_outside_the_shared_volume_is_refused`) | Path escaping the shared volume | Request refused | As expected | Pass |
| PY-06 *(negative)* | Non-PDF reports an error, does not raise (`test_extract.py::test_a_non_pdf_reports_an_error_rather_than_raising`) | A non-PDF file path | Structured error in the envelope; no crash | As expected | Pass |
| PY-07 | Generation returns valid structured questions (`test_generate.py::test_returns_valid_structured_questions`) | Valid generate request (stub provider) | Well-formed questions in `data` | As expected | Pass |
| PY-08 *(negative)* | More than two units rejected (`test_generate.py::test_rejects_more_than_two_units`) | Request naming three units | Request rejected | As expected | Pass |
| PY-09 *(negative)* | Malformed model item isolated (`test_generate.py::test_malformed_item_lands_in_errors_not_data`) | Provider returns one malformed item | Malformed item lands in `errors`, not `data` | As expected | Pass |

## 5.3 Result Analysis

The system meets each objective stated in Section 1.3. The results are discussed below against those
objectives in turn; representative outputs are cross-referenced to the Appendix.

**A constraint-based, blueprint-driven generator producing valid, balanced papers.** The engine
produces a complete paper in which every slot is filled and the per-section counts and total marks
are satisfied, with a per-constraint checklist rendered green (UT-01, ST-01). Where no such paper
exists, it returns a precise, named account of the shortfall rather than a partial or invalid paper
(UT-04, ST-03). A representative all-green paper with its constraint checklist is reproduced in
Appendix {{APPENDIX_PAPER_OUTPUT}} (Figure {{FIG_PAPER_OUTPUT}}).

**Enforced unit coverage, per-unit caps, and cross-paper non-repetition.** Coverage, unit maximums,
and exclusion of recently used questions are enforced *as rules of generation* rather than checked
afterwards: every allowed unit appears (UT-02), no unit exceeds its cap (UT-06), and successive papers
from one blueprint draw on disjoint questions (UT-08, ST-05). The coverage-aware greedy pass with
backtracking repair recovers valid papers a naive selection would miss (UT-05).

**Reproducible and explainable generation.** Generation is deterministic given a recorded seed: the
same seed reproduces a paper exactly, while a different seed yields a different valid paper (UT-10),
and the preview/save lifecycle re-generates from the stored seed so a saved paper matches its preview
(ST-01, ST-02). Every run carries a per-constraint pass/fail result, and every failure is expressed
as named missing slots — the property that makes any outcome auditable.

**Ingesting past-paper and syllabus documents into an admin-reviewed bank.** The extraction pipeline
recovers questions from both digital and scanned PDFs — taking the OCR fallback when needed — and
detects marks, types, and unit hints (PY-01, PY-02, PY-03), while rejecting non-documents and unsafe
paths (PY-05, PY-06). Extracted items enter a review queue rather than the approved bank, and uploads
are validated and role-gated (ST-09, ST-10, ST-11). A representative review queue with extracted
candidates is shown in Appendix {{APPENDIX_REVIEW_QUEUE}} (Figure {{FIG_REVIEW_QUEUE}}).

**Optional, supportive AI top-up and export.** When a blueprint is otherwise infeasible, AI expansion
generates grounded candidate questions, guarded against semantic duplicates, and closes the
feasibility gap without ever overriding the algorithm (ST-12); structurally impossible blueprints are
refused rather than papered over (ST-13). Finished papers export to both PDF and DOCX from a single
view-model, with an unsupported format rejected (ST-06, ST-07, ST-08). Sample PDF and DOCX exports
appear in Appendix {{APPENDIX_EXPORTS}} (Figure {{FIG_EXPORTS}}).

Taken together, the green unit and system suites — 225 Laravel and 153 pytest tests — confirm that
the deterministic algorithm owns the final paper, that AI remains strictly supportive, and that each
Section 1.3 objective was delivered and verified against the running system.
