You are an expert ML engineer and patient teacher who specializes in applied retrieval-augmented
generation (RAG) with small local LLMs. Teach me RAG from the ground up, grounded in MY project so
every concept connects to something real I'm building.

## My context
I'm a BSc Computer Science student building "QForge", a smart question-paper generator. I'm a
competent programmer but new to AI/LLMs. The architecture is locked:
- Laravel (PHP) is the orchestrator + database (MySQL) + a deterministic question-selection
  algorithm. It owns the workflow.
- A Python (FastAPI) service does AI/processing only — no database access.
- A LOCAL LLM runs via Ollama (e.g. Qwen2.5-7B / Llama 3.1 8B). No OpenAI, for cost reasons.
- AI is SUPPORTIVE only: the deterministic algorithm produces the final paper. The LLM is invoked
  ONLY when the algorithm can't fill the paper and reports a precise shortfall, e.g.
  missing_slots: [{ unit: "JDBC", type: "long", marks: 10, need: 3 }] — so the generation task is
  narrow: "write 3 long, 10-mark questions on JDBC for course CSC409."
- I already store, in MySQL: a per-subject syllabus with structured subtopics (e.g. "4.1 JDBC
  Architecture, Driver Types, Managing Connections, Statements, ResultSet…") and a question bank
  (each question has text, type, marks, unit, source = manual|extracted|ai).
My goal: use RAG so the LLM generates on-syllabus, non-duplicate questions that match this course's
style — NOT generic ones. I've decided AGAINST fine-tuning; help me understand why RAG is the right
call here.

## Teach me ALL of these, in this order, building each on the last
1. The core problem: plain prompting vs RAG vs fine-tuning — what each is, the trade-offs, and WHY
   RAG fits a small, per-subject, frequently-changing corpus like mine.
2. Embeddings: what a text embedding is, why "similar meaning → nearby vectors", what an embedding
   model is (e.g. nomic-embed-text / bge), and how it differs from the generation LLM.
3. Similarity search: cosine similarity, top-k retrieval, similarity thresholds.
4. What to index and "chunking": for me that's syllabus subtopics and past questions — how to split
   and store them.
5. The retrieval pipeline end to end: query → embed → search → top-k → assemble context.
6. Vector storage options: in-memory cosine vs a real vector store (FAISS / pgvector / Qdrant) —
   and honestly when I do NOT need one yet given my data size.
7. Grounding the prompt: how to inject retrieved syllabus context + 2–3 example past questions as
   few-shot anchors, and how a good system prompt keeps the model on-syllabus and reduces
   hallucination.
8. Structured output: getting JSON candidates out of the LLM and validating them (type/marks/unit
   correct, well-formed) — why I must validate in Laravel and not trust raw text.
9. Embedding-based de-duplication: rejecting a generated question that's too similar to one already
   in the bank.
10. Serving: how Ollama fits, a swappable "LLMProvider" abstraction, temperature, and latency.
11. Evaluation: how to judge if generated questions are good — relevance, faithfulness/groundedness,
    and how to spot hallucination.
12. Where every piece lives in MY architecture: which parts run in the Python /generate-questions
    endpoint vs Laravel, and how missing_slots becomes the generation request.

## How to teach
- Go one topic at a time. After each, give a tiny concrete example using my QForge data (CSC409,
  the JDBC unit, the missing_slots spec), then ask me 1–2 questions to check I understood before
  moving on. Wait for my answer.
- Explain the WHY, not just the what. Use plain analogies first, then the precise version.
- Be blunt about what is overkill for a student project on a deadline, and what's genuinely worth it.
- If you're unsure of a factual detail (a library, a default, a number), say so instead of inventing.
- Start now with topic 1, and end the whole course by sketching the minimal RAG pipeline you'd
  actually build for QForge.


































You are an expert algorithms teacher who explains by building intuition first, then formalizing.
Teach me greedy algorithms and backtracking from fundamentals, grounded in the real system I built
so the theory maps onto my own code.

## My context
I'm a BSc Computer Science student. I built "QForge", which generates an exam paper by selecting
questions from a bank to satisfy a teacher's "blueprint" (paper pattern). The selection engine is
deterministic, pure PHP, no external solver, and uses a HYBRID greedy + backtracking design. Its
parts:
- BlueprintCompiler → flattens the blueprint into an ordered list of SLOTS (each slot needs a
  question of a given type + marks, from allowed units).
- CandidateFilter → for a slot, the pool of eligible questions (matching subject/type/marks/unit,
  excluding ones already used).
- GreedySelector → picks the "best" candidate for a slot using a heuristic: unit balance + least-
  recently-used (lowest used_count). It is deliberately unit-AGNOSTIC (LRU only).
- ConstraintValidator → checks the finished paper: right count per group, total marks, UNIT
  COVERAGE (every allowed unit must appear), and no repeated questions.
- BacktrackingResolver → if the greedy pass produces an invalid paper, it reverses recent picks
  and tries alternatives until valid or exhausted.
The signature teaching case in my code: because greedy is LRU-only, it can fill all slots from one
unit and STARVE another unit even though a valid covering assignment exists — and backtracking then
recovers it.

## Teach me ALL of these, building each on the last
1. Greedy algorithms: the "locally optimal choice" idea, the greedy-choice property, and optimal
   substructure — in plain terms first.
2. Classic greedy examples to build intuition (e.g. activity selection / coin change / Huffman) —
   including a case where greedy gives the WRONG answer, so I learn its limits.
3. Selection heuristics / priority functions: how a greedy algorithm decides "best" — and map this
   to my GreedySelector's "unit balance + least-recently-used".
4. Constraint Satisfaction Problems (CSP): variables, domains, constraints — and how my slot-filling
   IS a CSP (slots = variables, candidate pools = domains, blueprint rules = constraints).
5. Backtracking: systematic search over a state-space tree, recursion, undoing the last choice,
   trying the next candidate; termination and worst-case complexity.
6. Pruning / why backtracking isn't brute force; how constraints cut the search early.
7. WHY a HYBRID: greedy as a fast first pass, backtracking as repair — the engineering trade-off
   (speed vs completeness) and why I didn't just use a full solver.
8. Map every concept to my classes (BlueprintCompiler, CandidateFilter, GreedySelector,
   ConstraintValidator, BacktrackingResolver) and walk the "greedy starves a unit → backtracking
   recovers coverage" scenario as a worked example on a tiny dataset.
9. Complexity + correctness: roughly how expensive backtracking can get here, and what guarantees
   the hybrid does and does NOT give.

## How to teach
- One topic at a time. After each, give a SMALL worked example (5–8 slots/questions is plenty),
  then ask me 1–2 check questions and wait for my answer before continuing.
- Build intuition with a plain example first, then state the precise definition.
- For topic 8, actually trace the steps: show the greedy picks, show the failed validation (unit
  uncovered), then show backtracking undoing and re-picking to a valid paper.
- If a claim depends on a detail you're unsure of, flag it rather than guessing.
- End by giving me 3 practice exercises (increasing difficulty) to test my understanding, and a
  one-paragraph summary of when to reach for greedy, backtracking, or the hybrid.


