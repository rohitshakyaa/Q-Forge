# Chapter 6: Conclusion and Future Recommendations

## 6.1 Conclusion

This project set out to remove the manual burden of examination paper setting without removing the
teacher's control over the result. Preparing a question paper by hand was identified as a
constraint-laden task that becomes slow and error-prone precisely because structure, unit coverage,
mark distribution, question-type mix, and non-repetition must all be satisfied at once and checked
against one another — coupled together in one person's head, where a change to satisfy one
requirement can silently break another. QForge was built to answer that problem by letting a teacher
describe the *shape* of an examination once, as a reusable blueprint, and then assembling a valid,
balanced paper from a bank of approved questions automatically, with a deterministic algorithm — not
an AI model — in final control of every selection.

The system was built iteratively through six principal milestones, sequenced algorithm-first so that
the highest-risk component, the constraint-based generation engine, was implemented and proven early
against seeded data before the messier document-processing and AI pipelines were layered on top of a
foundation already known to be correct. The generation engine was written from scratch as a set of
framework-light, individually testable classes: a compiler that flattens a blueprint into ordered
slots, a candidate filter, a coverage-aware greedy selector, a bounded backtracking resolver, and a
constraint validator. This hybrid greedy-plus-backtracking design — a fast path with a guaranteed
fallback — was the deliberate choice over pure-random selection, brute force, and an external
constraint solver, because it is at once fast on the common case, complete when a valid paper exists
within its iteration bound, and, above all, deterministic and explainable rather than a black box.
Around that engine the project delivered the full path from a curated question bank to a finished
paper: role-based catalog and bank management, a paper lifecycle with preview-then-save and PDF and
DOCX export, an admin-reviewed extraction pipeline for past-paper and syllabus PDFs, an optional
local-AI top-up for thin banks, and a retrieval-augmented layer that grounds that top-up and guards
against semantic duplicates.

The project's contribution is a working demonstration that a **hybrid system** — deterministic,
rule-based logic augmented by supportive AI — can produce examination papers that are provably valid
without surrendering authority to a language model. The centrepiece is the generation algorithm
itself: a from-scratch, seed-parameterised constraint solver that treats paper setting as a
constraint-satisfaction problem, enforces coverage and repetition as hard rules rather than
after-the-fact checks, and reports for every paper exactly which constraints passed and, for every
failure, exactly what the bank was missing. The AI is deliberately positioned as supportive, not
authoritative: it only ever proposes candidate question text, which is then stamped with type, marks,
and unit and subjected to the same constraints as any other question, so it can never cause an
invalid paper.

Each of the objectives stated in Section 1.3 was met by the delivered system:

- **A constraint-based, blueprint-driven generator was built.** The engine compiles a reusable
  blueprint into slots and produces a paper in which every slot is filled and the required
  per-section counts and total marks are satisfied; when no such paper exists, it returns a precise
  account of the shortfall as named missing slots rather than failing silently. The correctness of
  both the feasible and infeasible paths is pinned by the unit-test suite.

- **Unit coverage, per-unit maximum caps, and cross-paper non-repetition are enforced as hard rules
  of generation.** Coverage is driven into the greedy pass and guaranteed by the backtracker; per-unit
  maximums bound unit dominance; and questions used in recent saved papers — and, optionally, in
  recent examination years — are excluded at candidate-selection time, so structure, balance, and
  freedom from recent repetition are guaranteed by construction rather than checked afterwards.

- **Generation was made reproducible and explainable.** A run is deterministic given a recorded seed,
  which the system draws, echoes back, and can accept again to reproduce a paper exactly; and every
  paper carries a per-constraint pass/fail checklist, so any result can be audited and any failure is
  stated as named missing slots.

- **Past-paper and syllabus documents can be ingested into an admin-reviewed bank.** The extraction
  pipeline reads digital text with an optical-character-recognition fallback and heuristic parsing,
  turning past papers into candidate questions and syllabi into subjects and units; nothing becomes
  examinable until an admin approves it, so the bank grows from existing material without unverified
  questions entering it.

- **An optional AI top-up and export were delivered.** When a blueprint is otherwise infeasible, a
  local language model expands the bank with grounded candidate questions, guarded against semantic
  duplicates and kept strictly under the algorithm's control; and finished papers export to both PDF
  and DOCX. No objective was left unmet.

The major findings of the work follow directly from these outcomes. A hand-written constraint solver,
kept deterministic and self-contained, was sufficient to generate valid, balanced papers for
realistic blueprints without any external solver dependency. The greedy-plus-backtracking split
proved to be the right shape: the coverage-aware greedy pass solves most papers outright in a single
linear sweep, and the backtracker is exercised only as a correctness safety net. Making the engine
pure with respect to writes — reading the bank but never persisting — kept it unit-testable in
isolation and allowed later additions, including the entire AI and retrieval-augmented layer, to be
built on top without regressing the core, a property confirmed by a test suite that grew to 225
Laravel tests and 153 Python tests, all green. Finally, the document-processing work showed that
robust extraction from real, messy TU papers is achievable with disciplined heuristics and a
mandatory human review step, at the cost of full automation — a trade the design accepts by choice.
Taken together, the results confirm the project's central claim: a hybrid system in which the
deterministic algorithm, not the AI, owns the final paper is both buildable and defensible.

## 6.2 Future Recommendations

The system as delivered is complete against its objectives, but several deliberate design choices and
honest limitations mark out a roadmap for future work.

- **A smarter or exact solver behind the same interface.** The backtracking resolver is worst-case
  exponential and is therefore bounded by a fixed iteration cap, which guarantees termination but
  could, on a very large bank with tightly coupled coverage rules, return a best-effort partial result
  even where a solution exists deep in the search tree. Because the resolver sits behind a narrow
  interface, a stronger variable-ordering heuristic or a proper constraint-programming solver could be
  substituted without disturbing the rest of the engine if scale ever demands it.

- **Optimal multi-unit coverage in the AI top-up.** When a blueprint mandates more units than it has
  slots, the top-up pairs the scarcest units greedily so that each requested question can cover two
  units at once. This pairing is a heuristic rather than a provably optimal set cover; it is
  self-correcting across expand-then-regenerate rounds, but a future version could compute a minimal
  covering assignment directly and could relax the two-units-per-question bound where genuinely
  integrative questions are appropriate.

- **Routing AI questions through human review.** For the demonstration loop, AI-generated questions
  are stored as approved so that a regenerate immediately succeeds. A production deployment should
  route them through the same admin review queue that extracted questions already pass through, so
  that a human confirms every AI-authored question before it can appear on an examination, closing the
  gap that AI question quality is currently grounded but not human-verified.

- **Model and provider flexibility.** The AI path depends on a single local model, though it already
  sits behind a provider abstraction with a stub implementation, so the model is swappable by
  configuration alone. Future work could support multiple providers and models, and could compare
  their output quality, while preserving the property that the entire AI path remains optional and the
  system functions fully without it.

- **Stronger and multilingual semantic duplicate detection.** The semantic-duplicate guard is
  threshold-based: it catches typical paraphrases but is not an absolute guarantee, and it fails open,
  so during an outage of the vector index or embedder a paraphrase could slip in until the next
  re-index. The embedding model is also fixed and tuned for an English corpus. Future work could add a
  more robust duplicate check that does not degrade silently, and could adopt a multilingual embedding
  model — a change the system is already prepared for, since every stored vector records the model
  that produced it.

- **Broader testing and more resilient extraction.** The algorithm core is thoroughly unit-tested, but
  broader end-to-end and Python-side coverage — especially against unusual and heavily degraded PDF
  layouts — is the honest gap and a natural next area to grow. The extraction parser is regex- and
  heuristic-based by design; extending it to more document templates, and optionally introducing
  AI-assisted extraction behind the existing mandatory review step, would widen the range of material
  the bank can absorb without weakening the human-in-the-loop guarantee.

- **Multi-institution and multi-format operation.** The present system assumes a single-institution
  deployment with TU-style, unit-and-marks-structured papers and an English-language corpus. Future
  work could generalise it to serve multiple institutions and to accommodate other examination formats,
  and could take the queue infrastructure — single-node here, which suits a college deployment — to a
  clustered, multi-worker configuration for larger scale, a change the chosen stack supports by
  configuration rather than redesign.

Each of these directions builds on, rather than revises, the architecture the project established: a
deterministic algorithm at the centre, AI in a strictly supportive role, and clear service boundaries
that keep each concern independently improvable.

## Progress

- ✅ 6.1 Conclusion — report/06-conclusion.md
- ✅ 6.2 Future Recommendations — report/06-conclusion.md
