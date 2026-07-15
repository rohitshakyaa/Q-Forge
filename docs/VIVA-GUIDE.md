# QForge — Viva Defense Guide

> A study companion for defending QForge in front of a supervisor and external examiners.
> Written in plain language. Every claim is grounded in the actual code; where a doc and the
> code disagree, the **code wins** and the difference is called out. Anything I could not
> fully verify is marked `[verify]`.

**How to use this:** read sections 1–6 to understand the system, drill sections 7–9 the night
before, and keep section 10 open during the viva.

---

## 1. One-paragraph elevator pitch

**QForge is a smart question-paper generator for university courses.** A teacher describes the
*shape* of an exam once — "Section A: three 10-mark long questions; Section B: five 5-mark short
questions; cover every unit; don't repeat questions from the last 3 papers" — and QForge assembles
a real, valid paper from a bank of approved questions, automatically. The heart of the system is a
**deterministic constraint-solving algorithm** (written from scratch in PHP, no external solver)
that treats paper generation as a puzzle: fill every slot with a question that satisfies the hard
rules (right type, right marks, allowed unit, no repeats) while balancing soft preferences. When the
question bank is too thin to satisfy a blueprint, QForge can call a **local AI model** to *top up*
the bank — but the AI only writes candidate questions; the algorithm always decides the final paper.
This matters because it saves teachers hours of manual work while guaranteeing the exam obeys the
rules, and it stays **explainable**: for any paper it can tell you exactly which constraints passed,
and for any failure it names exactly what the bank was missing.

**Jargon defined once:**
- **Blueprint** = the reusable template describing an exam's structure and rules.
- **Question bank** = the pool of stored, approved questions the algorithm draws from.
- **Slot** = one position in the paper that must be filled by exactly one question.
- **Constraint** = a rule the finished paper must obey (hard = must hold; soft = preferred).
- **Deterministic** = same inputs always produce the same output (no randomness) — crucial for a
  system you must be able to defend and reproduce.

---

## 2. A-to-Z request flow — "generate a paper"

This traces one click of **Generate** from the browser all the way to the database and back. File
and method names are real.

### The hops

1. **Frontend (Vue 3 + Pinia).** The teacher clicks Generate on the Generate screen. The Pinia
   store [`frontend/src/stores/papers.ts`](../frontend/src/stores/papers.ts) method `generate(blueprintId)`
   sends `POST /papers/generate { blueprint_id }` through the shared axios client
   [`frontend/src/api/client/axios.ts`](../frontend/src/api/client/axios.ts). That client's request
   interceptor attaches the Sanctum bearer token from `localStorage`. *(Pinia = Vue's official state
   store; Sanctum = Laravel's API-token auth.)*

2. **Laravel routing + auth.** [`code/routes/api.php`](../code/routes/api.php) maps the route inside
   the `auth:sanctum` + `role:teacher` middleware group to
   [`PaperController::generate`](../code/app/Http/Controllers/Api/PaperController.php).

3. **Controller guards.** `PaperController::generate` validates that `blueprint_id` exists, loads the
   `Blueprint` with its `subject`, and does an **ownership check** — `abort_unless($blueprint->owner_id
   === $request->user()->id, 403)`. A teacher cannot generate from someone else's blueprint.

4. **The algorithm runs.** The controller calls
   [`PaperGenerator::generate($blueprint)`](../code/app/Services/PaperGeneration/PaperGenerator.php).
   This is the centerpiece (fully explained in §3). It:
   - compiles the blueprint JSON into ordered **slots** (`BlueprintCompiler`),
   - builds the cross-paper repetition exclusion (`lastNExclusion`),
   - does a greedy first pass (`greedyFill` → `CandidateFilter` + `GreedySelector`),
   - validates (`ConstraintValidator`),
   - and if that isn't fully valid, runs the `BacktrackingResolver` to repair it.
   It returns a `GenerationResult` value object.

5. **Python? Only on demand — not in this flow.** A plain generate does **not** call Python. Python
   is only involved if the bank can't satisfy the blueprint *and* the teacher then clicks "Expand
   bank with AI" (§6). So the normal generate path is pure Laravel + MySQL.

6. **Persist (on success).** If `result->satisfiable` is true, `PaperController::persist()` writes a
   `papers` row (`status=draft`) and one `paper_questions` row per chosen question inside a DB
   transaction, then increments `used_count` on the chosen questions.

7. **Response → Frontend.** The controller returns JSON: either
   `{ satisfiable:true, paper, constraint_results }`, or on failure
   `{ satisfiable:false, expandable, shortfall_reason, paper (partial), missing_slots, constraint_results }`.
   The `papers.ts` store renders the paper, the green/red constraint checklist, and — on failure —
   the shortfall panel with an "Expand bank with AI" button.

> **Discrepancy to know (code vs. comment):** the docblock above `PaperController::generate` says
> `used_count` is *not* incremented at generate time ("deferred to Save in M3"). The **actual code**
> in `persist()` *does* `Question::whereIn('id', $pickedIds)->increment('used_count')`. The code is
> authoritative and matches the M3 milestone note ("`used_count` now incremented on generate-persist");
> the docblock is a stale M2-era comment. If an examiner asks, say: *"the comment is out of date — we
> increment on persist; repetition control itself doesn't rely on that column anyway, it's derived
> from `paper_questions`."*

### Sequence diagram

```mermaid
sequenceDiagram
    actor T as Teacher
    participant FE as Frontend (papers.ts)
    participant API as PaperController
    participant PG as PaperGenerator
    participant DB as MySQL

    T->>FE: Click "Generate"
    FE->>API: POST /papers/generate { blueprint_id } (+Bearer token)
    API->>API: validate + owner check (403 if not owner)
    API->>PG: generate(blueprint)
    PG->>PG: BlueprintCompiler → slots
    PG->>DB: build last-N exclusion (recent papers → question ids)
    loop each slot
        PG->>DB: CandidateFilter (approved, subject/type/marks,<br/>allowed units, exclude used-in-paper + last-N)
        DB-->>PG: candidate pool (LRU-ordered)
        PG->>PG: GreedySelector.pick (lowest used_count, then id)
    end
    PG->>PG: ConstraintValidator.validate
    alt complete & all constraints pass
        PG-->>API: GenerationResult(satisfiable=true)
    else greedy invalid (e.g. a unit starved)
        PG->>DB: poolsBySlot (full pools + last-N)
        PG->>PG: BacktrackingResolver.resolve (bounded DFS)
        alt resolved
            PG-->>API: GenerationResult(satisfiable=true)
        else bank too thin
            PG->>PG: computeMissingSlots
            PG-->>API: GenerationResult(satisfiable=false, missing_slots)
        end
    end
    alt satisfiable
        API->>DB: persist paper + paper_questions, increment used_count
        API-->>FE: 200 { satisfiable:true, paper, constraint_results }
        FE-->>T: Render paper + all-green checklist
    else infeasible
        API-->>FE: 200 { satisfiable:false, missing_slots, expandable, ... }
        FE-->>T: Show shortfall + "Expand bank with AI"
    end
```

---

## 3. The algorithm, deeply explained (the centerpiece)

The engine lives in [`code/app/Services/PaperGeneration/`](../code/app/Services/PaperGeneration/).
It is deliberately **framework-light** (plain classes + value objects) so it can be unit-tested in
isolation, and it is **pure with respect to writes** — it only *reads* the question bank; saving is
the controller's job. The orchestrator is `PaperGenerator`; it wires five stages together.

**The five stages, in order:**

| Stage | Class | One-line job |
|---|---|---|
| 1. Compile | `BlueprintCompiler` | Turn the blueprint's JSON into ordered slots + rules |
| 2. Filter | `CandidateFilter` | For one slot, fetch the questions that legally fit it |
| 3. Select | `GreedySelector` | Pick the single best candidate for a slot (LRU) |
| 4. Repair | `BacktrackingResolver` | If greedy produced an invalid paper, search for a valid one |
| 5. Validate | `ConstraintValidator` | Score the finished set against every constraint |

The **Support/** folder holds the value objects passed between stages: `CompiledBlueprint`, `Slot`,
`GenerationResult`, `MissingSlot`, `ConstraintResult`, `PaperViewModel`.

### Stage 1 — Blueprint compile (`BlueprintCompiler`)

**Problem in plain English:** the blueprint is stored as free-form JSON shaped like the frontend
form. The engine needs a clean, ordered to-do list. Compilation flattens the human structure into a
list of single-question **slots**.

**Inputs / outputs:**
- **Input:** a `Blueprint` model. Its `definition` JSON has `sections` (each: `name`, `type`,
  `marksEach`, `count`), `unitRules` (`{ unitName: true|false }`), `unitAllocations` (soft hint), and
  `exclusionRules.lastNPapers`.
- **Output:** a `CompiledBlueprint` value object holding: `slots` (a `Slot[]`, one per question),
  `allowedUnitIds` (the hard unit filter *and* the coverage rule), `unitNames` (id→name for
  reporting), `unitAllocations` (soft, unused as a hard rule in this version), and `lastNPapers`.

A `Slot` (see [`Support/Slot.php`](../code/app/Services/PaperGeneration/Support/Slot.php)) carries
`index`, `sectionLabel`, `type`, `marks`, `displayNo`. A section of `count: 3` becomes **3 slots**.

**Key design decision:** `sections` are *authoritative* for structure; `unitRules` is both the hard
candidate filter and the coverage requirement; `unitAllocations` is only a **soft** hint (never
enforced). This is why the seed data's "42 vs 50" allocation/section mismatch is harmless — the code
comment in `BlueprintCompiler` calls this out explicitly.

### Stage 2 — Candidate filter (`CandidateFilter`)

**Problem:** given one slot, which stored questions are even *legal* to put there?

**Inputs / outputs:**
- **Input:** a `Slot`, the `CompiledBlueprint`, the ids already used in *this* paper, and an optional
  "last-N papers" exclusion closure.
- **Output:** a `Collection<Question>` — the candidate pool, ordered least-recently-used first.

**The exact query** (from the code): `subject_id` matches, `status = 'approved'`, `type` matches the
slot, `marks` matches the slot, `whereIn('unit_id', allowedUnitIds)` *if* the blueprint restricts
units, `whereNotIn('id', ...)` for questions already chosen in this paper, then the injected last-N
closure, finally `orderBy('used_count')->orderBy('id')`.

**Two things worth saying out loud in a viva:**
1. `status = 'approved'` is why AI-generated questions are stored as `approved` (not `pending`) — a
   pending question is *invisible* here, so the AI top-up would silently fail (see §6).
2. The `whereNotIn` (in-paper) and the last-N closure (cross-paper) are the two repetition guards.
   The last-N rule is injected as a closure so it plugs in without changing this class — it's a
   no-op when the blueprint sets `lastNPapers <= 0`.

### Stage 3 — Greedy select (`GreedySelector`)

**Problem:** from a legal pool, pick one.

**"Greedy" defined:** a greedy algorithm makes the choice that looks best *right now*, without
looking ahead. Here "best" = **least-recently-used (LRU)**: lowest `used_count`, ties broken by `id`
(so it's deterministic). *(LRU = the question used the fewest times gets picked first, which spreads
usage across the bank over time.)*

**Inputs / outputs:** input is the candidate `Collection`; output is one `Question` (or `null` if the
pool is empty).

**The deliberately clever bit:** `GreedySelector` is **unit-agnostic** — it does *not* try to spread
questions across units. The code comment says this is intentional: unit coverage is treated as a
*validated constraint*, not baked into greedy. That means a naive greedy pass **can** starve a unit
even when a valid, covering assignment exists — and *recovering that case is exactly what the
backtracking stage demonstrates.* This contrast is the academic point of the project.

### Stage 4 — Backtracking (`BacktrackingResolver`)

**Problem:** the greedy paper failed validation (usually because a required unit wasn't covered). Is
there *any* assignment that fills every slot with distinct questions **and** covers every allowed
unit? Find it.

**"Backtracking" defined:** a systematic trial-and-error search. You place a choice, move forward; if
you hit a dead end, you *undo* (backtrack) the last choice and try the next option. It explores a
tree of possibilities depth-first but prunes as soon as a branch can't work.

**How this one works (from the code):**
- A bounded **depth-first search** (`search()`), one recursion level per slot.
- At each slot it orders candidates **uncovered-unit-first**, then by `used_count`, then `id` — i.e.
  it prefers a question whose unit isn't covered yet, to drive toward full coverage.
- It skips any question already used (distinctness).
- **Base case:** when every slot is filled, accept the assignment *only if* it covers all allowed
  units (`coversAllUnits`); otherwise reject and backtrack.
- **Safety cap:** `MAX_ITERATIONS = 50000`. If the search blows the cap, it returns `null` and the
  caller falls back to a best-effort partial result. This guarantees the algorithm always
  terminates.

**Inputs / outputs:** input is the `CompiledBlueprint` + a full candidate pool per slot; output is
`array<slotIndex, Question>` (a valid assignment) or `null`.

### Stage 5 — Constraint validation (`ConstraintValidator`)

**Problem:** prove (or disprove) that a set of selections is a valid paper, and produce a
human-readable checklist.

**Inputs / outputs:** input is the `CompiledBlueprint` + the selections; output is a
`ConstraintResult[]` — one `{ label, expected, got, pass }` line per rule. This array is returned to
the frontend as the green/red checklist.

**The constraints it checks:**
1. **Per-section count** — did each section get exactly the number of questions it asked for?
2. **Total marks** — do the marks sum to the blueprint's `total_marks`?
3. **Unit coverage** — (only if the blueprint restricts units) are all allowed units represented?
4. **No repeated questions** — no question appears twice in the paper.

`allPass()` returns true only if every line passed. `PaperGenerator` accepts the greedy result only
when it is both *complete* (every slot filled) and `allPass`.

### Why greedy + backtracking (and not the alternatives)?

This is a classic viva question — here is the defense:

| Alternative | Why not |
|---|---|
| **Pure random** | Not reproducible, can't guarantee constraints, can't explain a failure. QForge must be defensible and deterministic. |
| **Brute force** (try every combination) | Combinatorial explosion — with dozens of questions per slot it's astronomically large. Wasteful when a greedy pass usually succeeds outright. |
| **ILP / constraint solver** (e.g. an external LP/CP solver) | Powerful, but adds a heavy external dependency, is a "black box" that's hard to explain to examiners, and is overkill for this scale. A locked design decision (PLAN.md) was *"from scratch, no external solver."* |
| **Greedy + backtracking (chosen)** | Greedy is fast and solves the common case in one linear pass. Backtracking is a *correctness safety net*: it guarantees that if a valid paper exists (within the iteration cap), it will be found. Deterministic, explainable, self-contained, unit-testable. |

The design is essentially **"fast path + guaranteed fallback"**: greedy for speed, backtracking for
completeness.

### Time / space behavior (intuitive)

- **Greedy pass:** roughly linear in (number of slots × pool size) — one DB query + one pick per slot.
  Fast; this is the common path.
- **Backtracking:** worst case exponential (it's a search over combinations), which is *why* the hard
  `MAX_ITERATIONS = 50000` cap exists. In practice the uncovered-unit-first ordering steers it to a
  solution quickly, and real papers have small slot counts (a handful to a few dozen).
- **Space:** the candidate pools per slot are held in memory; recursion depth equals the number of
  slots (small).
- **Where it can fail / degrade:** a genuinely huge bank with many slots and tight coverage rules
  could, in theory, approach the iteration cap and return a "best-effort partial + missing_slots"
  even though a solution exists far down the search tree. For this project's scale that doesn't
  happen, but it's the honest limit (see §8).

### Worked example (fake data): a fill, a dead-end, a backtrack

**Blueprint:** two sections, coverage of two units required.
- Section A: **1 long** question, 10 marks.
- Section B: **1 short** question, 5 marks.
- `unitRules`: Unit A ✅ and Unit B ✅ → **both units must be covered.**

So there are **2 slots**: `slot0` = long/10, `slot1` = short/5.

**Bank (all approved):**

| id | type | marks | unit | used_count |
|----|------|-------|------|-----------|
| L1 | long | 10 | Unit A | 0 |
| L2 | long | 10 | Unit B | 2 |
| S1 | short | 5 | Unit A | 0 |

Note: **no short question exists for Unit B.** Only the long slot can cover Unit B.

**Pass 1 — greedy:**
- `slot0` (long/10) pool = {L1, L2}, LRU order → **picks L1** (used_count 0, Unit A).
- `slot1` (short/5) pool = {S1} → **picks S1** (Unit A).
- Selections: {L1 (A), S1 (A)}. `ConstraintValidator` → Unit coverage: **1 of 2 units** ❌ (Unit B
  missing). Greedy result is invalid.

**Pass 2 — backtracking** (full pools: slot0 = {L1, L2}, slot1 = {S1}):
- **depth 0 (slot0):** uncovered = {A, B}. Order by (uncovered-first, used_count, id): L1(0), L2(2).
  **Try L1 (Unit A).**
  - **depth 1 (slot1):** covered = {A}, uncovered = {B}. Pool = {S1}. S1 is Unit A (doesn't help
    coverage). **Try S1.**
    - **depth 2 (base case):** all slots filled. `coversAllUnits`? covered = {A} only → **fail.**
      Return null.
  - slot1 has no other candidate → **dead end.** Return null.
  - **Backtrack:** `unset` L1 at slot0.
  - **Try L2 (Unit B).**
  - **depth 1 (slot1):** covered = {B}, uncovered = {A}. Pool = {S1}. S1 is Unit A → helps! **Try S1.**
    - **depth 2 (base case):** covered = {A, B} → **coversAllUnits ✅.** Return {L2, S1}.
- Result: **{slot0 = L2 (Unit B), slot1 = S1 (Unit A)}** — a valid, fully-covering paper.

This single example shows all three phenomena the examiners will look for: a **slot getting filled**
(L1, then S1), a **dead-end** (S1 can't cover Unit B under the L1 branch), and a **backtrack** (undo
L1, try L2, succeed). It also shows *why greedy alone fails here* — greedy grabbed the LRU L1 and
starved Unit B.

### Algorithm flowchart

```mermaid
flowchart TD
    A[Blueprint] --> B[BlueprintCompiler:<br/>flatten sections → ordered slots]
    B --> C[Build last-N exclusion<br/>from recent papers]
    C --> D[Greedy pass:<br/>for each slot → CandidateFilter → GreedySelector LRU pick]
    D --> E{Complete AND<br/>all constraints pass?}
    E -->|Yes| F[Return GenerationResult<br/>satisfiable = true]
    E -->|No| G[Build full candidate pool per slot]
    G --> H[BacktrackingResolver:<br/>bounded DFS, uncovered-unit-first]
    H --> I{Valid assignment<br/>found within cap?}
    I -->|Yes| J[Re-validate → Return<br/>satisfiable = true]
    I -->|No| K[computeMissingSlots:<br/>supply deficit, else uncovered units]
    K --> L[Return GenerationResult<br/>satisfiable = false + missing_slots]
    F --> M[Controller persists paper]
    J --> M
    L --> N[Controller returns shortfall<br/>persists nothing]
```

---

## 4. Data model (the real tables)

Taken from the migrations in [`code/database/migrations/`](../code/database/migrations/). Laravel
(MySQL) owns all of these.

| Table | Purpose | Key columns (real) |
|---|---|---|
| **users** | Accounts (admin-provisioned, no public signup) | `id`, `name`, `email` (unique), `role` (teacher/admin), `password` |
| **subjects** | A course | `id`, `code` (unique, route key e.g. CS302), `name`, `description`, `syllabus` (longtext markdown) |
| **units** | Chapters within a subject | `id`, `subject_id` (FK), `name`, `position`, `hours` (nullable, M4.1), `content` (nullable markdown, M4.1) |
| **questions** | The question bank | `id`, `subject_id` (FK), `unit_id` (FK, **nullable** since M4), `type`, `marks` (**nullable** since M4), `difficulty` (nullable), `text`, `source` (extracted/ai/manual), `status` (pending/approved/rejected), `attributes` (JSON), `used_count`. Composite index on `(subject_id, unit_id, type, marks, status)` for fast filtering. |
| **blueprints** | Exam templates | `id`, `owner_id` (FK users), `subject_id` (FK), `name`, `total_marks`, `duration`, `ai_assist` (bool), `definition` (JSON), `last_used_at` |
| **papers** | A generated or imported paper | `id`, `owner_id` (FK), `blueprint_id` (FK, **nullable** since M3.1), `subject_id` (FK), `name`, `total_marks`, `duration`, `status` (draft/saved/exported), `export_count`, `generated_at`, `origin` (generated/imported, M3.1) |
| **paper_questions** | Snapshot of which questions are in a paper (source of truth for repetition) | `id`, `paper_id` (FK), `question_id` (FK), `unit_id` (FK), `section_label`, `display_no`, `marks`, `is_ai` (bool) |
| **document_uploads** | Tracks async PDF extraction jobs (M4) | `id`, `uploader_id` (FK), `subject_id` (FK, nullable), `type` (syllabus/past_paper), `original_filename`, `stored_path`, `status` (uploaded/processing/parsed/failed), `error`, `meta` (JSON) |

**Important schema facts to defend:**
- `questions.unit_id` and `questions.marks` started **NOT NULL** and were made **nullable** by
  migration `2026_06_10_000002_relax_questions_for_extracted_candidates.php` — because the PDF
  extractor only produces a unit *hint* and papers often omit marks. But **approval requires both**,
  and the generator only ever selects `status = 'approved'` rows, so the algorithm never sees a null.
- **Repetition control is derived from `paper_questions`**, not from `used_count`. `used_count` is a
  denormalized display/tie-break counter only.
- All foreign keys `cascadeOnDelete` (e.g. deleting a unit deletes its questions) — this is *why*
  syllabus re-import only *adds* missing units and never deletes (M4.1 note).

### ER diagram (current, full schema)

```mermaid
erDiagram
    USERS ||--o{ BLUEPRINTS : owns
    USERS ||--o{ PAPERS : owns
    USERS ||--o{ DOCUMENT_UPLOADS : uploads
    SUBJECTS ||--o{ UNITS : has
    SUBJECTS ||--o{ QUESTIONS : scopes
    SUBJECTS ||--o{ BLUEPRINTS : targets
    SUBJECTS ||--o{ PAPERS : for
    SUBJECTS ||--o{ DOCUMENT_UPLOADS : for
    UNITS ||--o{ QUESTIONS : groups
    UNITS ||--o{ PAPER_QUESTIONS : tags
    BLUEPRINTS ||--o{ PAPERS : generates
    PAPERS ||--o{ PAPER_QUESTIONS : contains
    QUESTIONS ||--o{ PAPER_QUESTIONS : "appears in"

    USERS {
        bigint id PK
        string email UK
        enum role "teacher|admin"
    }
    SUBJECTS {
        bigint id PK
        string code UK
        longtext syllabus "markdown (M5 corpus)"
    }
    UNITS {
        bigint id PK
        bigint subject_id FK
        int hours "nullable (M4.1)"
        text content "nullable markdown (M4.1)"
    }
    QUESTIONS {
        bigint id PK
        bigint subject_id FK
        bigint unit_id FK "nullable until approved"
        string type "short|long|mcq"
        int marks "nullable until approved"
        enum source "extracted|ai|manual"
        enum status "pending|approved|rejected"
        int used_count
    }
    BLUEPRINTS {
        bigint id PK
        bigint owner_id FK
        bigint subject_id FK
        json definition "sections/unitRules/unitAllocations/exclusionRules"
    }
    PAPERS {
        bigint id PK
        bigint owner_id FK
        bigint blueprint_id FK "nullable (M3.1)"
        bigint subject_id FK
        enum status "draft|saved|exported"
        enum origin "generated|imported (M3.1)"
        timestamp generated_at
    }
    PAPER_QUESTIONS {
        bigint id PK
        bigint paper_id FK
        bigint question_id FK
        bigint unit_id FK
        string section_label
        int display_no
        bool is_ai
    }
    DOCUMENT_UPLOADS {
        bigint id PK
        bigint uploader_id FK
        bigint subject_id FK "nullable"
        enum type "syllabus|past_paper"
        enum status "uploaded|processing|parsed|failed"
    }
```

---

## 5. Service boundaries (why the architecture is shaped this way)

QForge is a monorepo of three services with one golden rule of communication:
**Frontend → Laravel → Python → Laravel → Frontend.** Each boundary buys a concrete benefit.

| Rule | Where it's enforced | Concrete benefit |
|---|---|---|
| **Laravel is the single source of truth / orchestrator** | Owns MySQL, auth, all business logic, the algorithm | One place to reason about data integrity, permissions, and workflow. The algorithm sits next to the data it needs. |
| **Python is a stateless processor — no DB, no business logic** | `python-service/app/main.py`: `/extract` and `/generate-questions` explicitly *"no DB access"*; they take input and return structured JSON | Python can be restarted, scaled, or swapped without touching data. Testable in isolation. It can never corrupt the database because it can't reach it. |
| **Frontend talks only to Laravel, never to Python** | `frontend/src/api/client/axios.ts` has base URL `/api` (Laravel) only | Security (the browser never holds Python/Ollama credentials), and a single stable API contract. Python internals can change freely. |
| **No hardcoded Python URLs** | `PythonService` uses `Http::baseUrl(config('services.python.base_url'))` | Environment-portable; the URL lives in config, per CLAUDE.md. |
| **Heavy work goes on a queue** | `ProcessDocumentUpload` and `ExpandQuestionBank` are `ShouldQueue` jobs on Redis/Horizon | OCR and LLM calls take minutes; queuing keeps the API responsive and gives retries + monitoring. |

**Say it in one line:** *"Laravel decides; Python computes; the frontend displays. Python never sees
the database and the browser never sees Python — so responsibilities never leak across boundaries."*

The Laravel↔Python contract is a thin wrapper: [`PythonService.php`](../code/app/Services/PythonService.php)
exposes `extract()` and `generateQuestions()`, both returning structured `{status, data, errors}`
envelopes. Python reports parse failures *in-band with HTTP 200* so the calling job can record the
reason instead of blindly retrying a file that will never parse.

---

## 6. AI-assisted bank expansion (M5) — AI helps, the algorithm decides

**The scenario:** a teacher's blueprint is infeasible — the bank simply doesn't have enough questions
of some (type, marks, unit). Rather than dead-ending, QForge can ask a **local LLM** (Large Language
Model — an AI that writes text) to author fresh candidate questions to fill the gap, then regenerate.

**The flow (all real):**
1. Generate returns `satisfiable:false` + `missing_slots`. The frontend shows "Expand bank with AI".
2. Teacher clicks it → `POST /blueprints/{id}/expand-bank` →
   [`BankExpansionController::expand`](../code/app/Http/Controllers/Api/BankExpansionController.php).
3. The controller **re-derives the shortfall server-side** by re-running the (side-effect-free)
   generator — it never trusts the client's claim of what's missing.
4. If it's structurally impossible (coverage needs more distinct units than the paper has questions —
   `GenerationResult::coverageStructurallyInfeasible()`), it refuses and explains why. *AI adds
   questions, not slots, so it genuinely cannot help here.*
5. Otherwise it dispatches [`ExpandQuestionBank`](../code/app/Jobs/ExpandQuestionBank.php) inside a
   `Bus::batch` and returns a `jobId`. The frontend polls `GET /jobs/{batchId}`.
6. The job, per missing slot: builds a **grounding block** with
   [`GroundingBuilder`](../code/app/Services/AiExpansion/GroundingBuilder.php) = the subject syllabus
   + the unit's content + up to 3 approved example questions of the same type. It calls
   `PythonService::generateQuestions(grounding, type, marks, need + 2)`.
7. Python (`/generate-questions`) runs the `LLMProvider` (default `OllamaProvider` → the
   `qforge_ollama` container running `qwen2.5:3b-instruct`; a `StubProvider` is used in tests). It
   returns valid questions in `data` and malformed ones in `errors` — one bad item never sinks the
   batch.
8. Back in the job, survivors are stored as ordinary `questions` rows with **`source=ai`,
   `status=approved`**, with `type` and `marks` **stamped from the slot** (not from the model).
9. The teacher regenerates; the new questions are now selectable and the paper succeeds.

**Exactly what the AI DOES decide:**
- The *wording* of candidate questions (and, for MCQs, the option strings + which is correct).

**Exactly what the AI does NOT decide:**
- ❌ It does **not** choose which questions go in the paper — the algorithm does, on regenerate.
- ❌ It does **not** set `type` or `marks` — the job stamps those from the slot (the code even logs a
  warning if the model *echoes* a mismatched value, then overrides it).
- ❌ It does **not** pick the `unit_id` — Laravel resolves it from the `MissingSlot`.
- ❌ It does **not** touch the database — Python has no DB access; Laravel validates and persists.
- ❌ It does **not** do retrieval — the "RAG" here is *deterministic SQL by primary key* in
  `GroundingBuilder`; there are no embeddings or vector store.

**The one-line defense:** *"AI is a supportive content generator behind the algorithm. It fills the
bank's gaps with grounded candidate text; Laravel stamps the hard fields, and the deterministic
algorithm still makes every selection decision. If the AI is unavailable or produces junk, the system
degrades to 'bank too thin' — it never produces an invalid paper."*

---

## 7. Strong points to emphasize (spoken talking points)

1. *"The core is a **deterministic, explainable algorithm** I wrote from scratch — no external
   solver. The same blueprint and bank always produce the same paper, and for any result I can show a
   per-constraint pass/fail checklist."*
2. *"It's a **hybrid greedy + backtracking** design: greedy handles the common case in a fast linear
   pass, and backtracking is a **correctness safety net** that guarantees a valid paper is found if
   one exists within the iteration cap."*
3. *"I made a deliberate architectural choice: the greedy selector is **unit-agnostic**, and unit
   coverage is a **validated constraint**. That means greedy can starve a unit — and my headline unit
   test proves backtracking recovers exactly that case. The weakness of greedy is turned into a
   demonstration of correctness."*
4. *"**Clean service separation**: Laravel orchestrates and owns the data, Python is a stateless
   processor with no DB access, and the frontend only ever talks to Laravel. Each boundary is a real
   security and maintainability win."*
5. *"**AI is supportive, not authoritative.** The local LLM only writes candidate question text;
   Laravel stamps type/marks/unit and the algorithm makes every selection. The system can't be
   tricked by the model into producing an invalid paper."*
6. *"The system is **honest about failure**: an infeasible blueprint returns exactly what's missing —
   'need 2× 10-mark from Unit 3' — instead of a vague error, and it even distinguishes an expandable
   bank shortfall from a structurally-impossible coverage rule."*
7. *"**Repetition control is data-driven**: 'don't reuse questions from the last N papers' is derived
   from the `paper_questions` snapshot table, and it's subject-wide — it even respects imported real
   past exams (M3.1), so generated papers avoid what students have actually seen."*
8. *"**Heavy work is queued** on Redis + Horizon (OCR extraction, AI expansion), so the API stays
   responsive and jobs get retries and monitoring."*
9. *"The engine is **unit-tested in isolation** because it's pure with respect to writes — it reads
   the bank and returns a value object; the controller persists. Those tests *are* the proof of the
   academic contribution."* *(29 tests green at M2, 112+ across the project per the milestone log.)*
10. *"It's a **realistic pipeline end to end**: upload a real past-paper PDF → OCR + heuristic parse →
    admin review → into the bank → used in generation → export to PDF/DOCX from one shared
    view-model."*

---

## 8. Honest weak points + how to answer

Being candid here reads as maturity. Each weakness is paired with a mitigation or "future work"
framing.

1. **Backtracking is worst-case exponential.**
   *Answer:* "It's bounded by a hard `MAX_ITERATIONS = 50000` cap so it always terminates; for
   real papers (small slot counts) the uncovered-first ordering finds a solution fast. Future work:
   swap in a smarter heuristic or a proper CP solver behind the same interface if scale demands it."

2. **Greedy is unit-agnostic, so it deliberately triggers backtracking on coverage.**
   *Answer:* "That's intentional and tested, but a coverage-aware greedy pass would reduce how often
   we fall back. It's a clear, low-risk future optimization — the interface wouldn't change."

3. **Single-LLM dependency (one local Ollama model).**
   *Answer:* "It's behind an `LLMProvider` abstraction with a `StubProvider` already in place, so the
   model is swappable by env var without touching call sites. And the whole AI path is optional —
   the system works fully without it."

4. **AI question quality isn't guaranteed / no human re-review of AI questions.**
   *Answer:* "AI questions are grounded on the real syllabus + exemplars, and malformed items are
   filtered into `errors`. But they're stored `approved` for the demo loop; a production system would
   route them through the same admin review queue that extracted questions use."

5. **No distributed queue / single-node.**
   *Answer:* "Redis + Horizon is single-node here, which is fine for a college deployment. Horizon
   scales to multiple workers, and Redis can be clustered — it's a config change, not a redesign."

6. **Test-coverage gaps (feature/integration breadth).**
   *Answer:* "The algorithm core is well unit-tested (the academic centerpiece), and there are feature
   tests for CRUD/generate/export/upload. Broader end-to-end and Python coverage — especially messy
   real-world PDFs — is the honest gap and the next area to grow."

7. **Heuristic PDF parsing is brittle on unusual layouts.**
   *Answer:* "It's regex/heuristic by design (a locked decision: no LLM for extraction in v1), with a
   mandatory admin review step so nothing bad enters the bank silently. Papers split by 'Section A'
   instead of 'Unit N' yield unassigned candidates for a human — deliberately."

8. **Stale docblock in `PaperController::generate`** (says `used_count` isn't incremented; the code
   increments it). *Answer:* "A leftover comment from M2; the behavior is correct and matches M3.
   Repetition control doesn't depend on that column anyway — it's derived from `paper_questions`."

---

## 9. Likely viva questions with model answers

### Algorithm (8)

**Q1. Walk me through what happens from clicking Generate to seeing a paper.**
The frontend `papers.ts` store POSTs `/papers/generate` to `PaperController::generate`, which checks
ownership and calls `PaperGenerator::generate`. That compiles the blueprint into slots, runs a greedy
LRU fill, validates; if invalid it runs the backtracking resolver; on success the controller persists
a draft paper and returns it with a constraint checklist. See §2.

**Q2. Why greedy AND backtracking — isn't one enough?**
Greedy alone is fast but can starve a required unit (it's deliberately unit-agnostic in
`GreedySelector`). Backtracking alone would be slower on the common case. Together: greedy solves the
typical paper in one linear pass, and backtracking guarantees correctness — it finds a valid,
coverage-satisfying assignment if one exists within the cap.

**Q3. What exactly is a "slot"?**
One position in the paper for exactly one question, produced by `BlueprintCompiler` flattening
`sections`. A section with `count: 3` becomes three `Slot` objects, each carrying its `type`, `marks`,
`sectionLabel`, and `displayNo` (see `Support/Slot.php`).

**Q4. How does the backtracking actually decide what to try first?**
In `BacktrackingResolver::search`, candidates for a slot are ordered **uncovered-unit-first**, then by
`used_count`, then `id`. This drives the search toward covering all units quickly. It skips
already-used questions and only accepts a full assignment if `coversAllUnits` passes.

**Q5. What stops backtracking from running forever?**
A hard `MAX_ITERATIONS = 50000` counter in `BacktrackingResolver`. If exceeded, `search` returns
`null` and `PaperGenerator` falls back to a best-effort partial + `missing_slots`. So it always
terminates.

**Q6. How does it enforce "no repeated questions across recent papers"?**
`PaperGenerator::lastNExclusion` gathers the question ids used in the most recent `lastNPapers` papers
for that subject (owner's generated papers **or** imported exams, M3.1) from `paper_questions`, and
injects a `whereNotIn` closure into `CandidateFilter`. It's applied to both the greedy and
backtracking pools so backtracking can't sneak an excluded question back in.

**Q7. What does the algorithm output when it fails, and how is that computed?**
A `GenerationResult` with `satisfiable=false` and `missing_slots`. `computeMissingSlots` first checks
raw **supply** per (section, type, marks) group — deficit = required − available. If supply is fine
everywhere but coverage still fails, it names the **uncovered units**. Each `MissingSlot` also carries
a server-set `unitId` so the M5 AI job knows exactly which unit to target.

**Q8. Is the algorithm deterministic? Prove it.**
Yes. Every ordering has a deterministic tie-break by `id`: `CandidateFilter` orders by
`used_count, id`; `GreedySelector` sorts by `[used_count, id]`; `BacktrackingResolver` by
`[uncovered-rank, used_count, id]`. No randomness anywhere — same inputs → same paper.

### Architecture (5)

**Q9. Why is Laravel the orchestrator instead of, say, Python?**
Laravel owns the database, auth, and the business rules, and the algorithm needs tight, transactional
access to that data. Keeping orchestration next to the data avoids chatty cross-service calls and
keeps one authority for integrity and permissions (CLAUDE.md architecture principle #1).

**Q10. Why can't the frontend call Python directly?**
Security and contract stability. The browser never holds Python/Ollama credentials, and Python's
internals can change without breaking the frontend, which only knows the Laravel `/api` contract
(`axios.ts` base URL is `/api`). Correct flow is Frontend → Laravel → Python → Laravel → Frontend.

**Q11. Why are OCR and AI on a queue instead of inline in the request?**
They take minutes. `ProcessDocumentUpload` and `ExpandQuestionBank` are `ShouldQueue` jobs on
Redis/Horizon, so the HTTP request returns immediately and the work runs in the background with
retries, timeouts (600–900s), and Horizon monitoring.

**Q12. How does Laravel avoid hardcoding the Python URL?**
`PythonService` uses `Http::baseUrl(config('services.python.base_url'))`. The URL lives in
`config/services.php` from an env var, so it's portable across environments (a CLAUDE.md rule).

**Q13. How is authorization handled on generate/expand?**
Routes are behind `auth:sanctum` + `role:teacher`. Controllers add an ownership check
(`abort_unless($blueprint->owner_id === $request->user()->id, 403)`). For AI job polling,
`BankExpansionController` bakes the owner id into the batch **name** and re-checks it in `jobStatus`,
so no extra table is needed to authorize the poll.

### Database (3)

**Q14. Why is repetition derived from `paper_questions` and not from `used_count`?**
`paper_questions` is a precise per-paper snapshot — it records *which* questions were on *which*
papers, so "used in the last N papers" is an exact join. `used_count` is just a denormalized counter
for display and LRU tie-breaking; it can't tell you *which* papers a question appeared in.

**Q15. Why did `questions.unit_id` and `marks` become nullable?**
Migration `2026_06_10_000002` relaxed them because the PDF extractor only yields a unit *hint* and
many past papers don't print marks. Approval requires both to be filled, and the generator only
selects `approved` rows, so a null never reaches the algorithm.

**Q16. What is the `origin` column on `papers` for?**
Added in M3.1. `generated` = a paper QForge produced; `imported` = a real historical exam recorded so
its questions feed repetition control subject-wide. History and analytics filter to
`origin=generated`, but the last-N exclusion window includes imported papers.

### AI / Python (3)

**Q17. Is this RAG? Where are the embeddings?**
There are no embeddings or vector store. `GroundingBuilder` does **deterministic SQL retrieval by
primary key** — the subject syllabus, the unit's content, and up to 3 approved exemplars. It's
"grounding," assembled in Laravel; Python just wraps it in a prompt and calls the model.

**Q18. What if the LLM returns garbage or is down?**
`OllamaProvider._coerce_items` tolerantly parses the JSON (a garbled response degrades to "generated
nothing"), and `/generate-questions` routes each invalid item to `errors` rather than failing the
batch. If Python is unreachable, `PythonService` throws and the job logs it and moves on. The worst
case is "bank still too thin" — never an invalid paper.

**Q19. How do you stop the AI from deciding marks or units?**
`ExpandQuestionBank::storeSurvivors` **stamps** `type` and `marks` from the slot, not the model (and
logs a warning if the model echoed a mismatch). `unit_id` comes from the `MissingSlot`. The model's
output is treated as *text only*.

### General / "why did you build it this way" (3)

**Q20. Why build the algorithm from scratch instead of using an existing solver?**
It was a locked design decision (PLAN.md): a from-scratch, deterministic, unit-tested engine is the
academic contribution, is fully explainable to examiners, and avoids a heavy external dependency.
Greedy + backtracking is the right complexity for this scale.

**Q21. Why algorithm-first milestones (M2 before the PDF/AI pipeline)?**
The algorithm is the centerpiece, so it was built and proven early against *seeded* data before the
messier PDF/AI work. That de-risked the project: the thing being graded works and is tested before
anything depends on it.

**Q22. What would you do differently / next?**
Route AI questions through admin review, add a coverage-aware greedy pass to reduce backtracking,
broaden Python + end-to-end tests on real-world PDFs, and make the queue multi-worker. None require a
redesign — they build on the existing seams (the `LLMProvider` abstraction, the queue, the review
queue).

---

## 10. Rapid-fire cheat sheet (memorize the night before)

| # | Fact | Answer |
|---|---|---|
| 1 | Main algorithm folder | `code/app/Services/PaperGeneration/` |
| 2 | Orchestrator / facade class | `PaperGenerator` (`generate()` returns a `GenerationResult`) |
| 3 | The five stages | Compile → Filter → Greedy select → Backtrack → Validate |
| 4 | Five stage classes | `BlueprintCompiler`, `CandidateFilter`, `GreedySelector`, `BacktrackingResolver`, `ConstraintValidator` |
| 5 | Greedy policy | Least-recently-used: lowest `used_count`, tie-break `id` (deterministic) |
| 6 | Backtracking type + cap | Bounded depth-first search, `MAX_ITERATIONS = 50000`, uncovered-unit-first ordering |
| 7 | Hard constraints | approved status, subject, type, marks, allowed unit, unit coverage, no repeats |
| 8 | Repetition source of truth | `paper_questions` table (not `used_count`); subject-wide, last-N |
| 9 | Generate endpoint | `POST /papers/generate` (teacher, owner-scoped) → `PaperController::generate` |
| 10 | Three services | Laravel (orchestrator+DB+algorithm), Vue 3 + Pinia (UI), FastAPI Python (stateless processing) |
| 11 | Communication rule | Frontend → Laravel → Python → Laravel → Frontend (browser never calls Python) |
| 12 | AI model + provider | Ollama `qwen2.5:3b-instruct` via `LLMProvider` (`OllamaProvider` + `StubProvider`) |
| 13 | What AI decides / doesn't | Decides: question *text*. Doesn't: type, marks, unit, selection, or DB writes |
| 14 | Queue stack | Redis + Horizon; jobs `ProcessDocumentUpload`, `ExpandQuestionBank` |
| 15 | Milestone status | M1–M5 (incl. M3.1, M4.1) all **Done**; 112+ Laravel tests, 97 pytest green per the milestone log |

---

*Generated as a study aid. Cross-check any figure marked from the milestone log against a fresh
`php artisan test` run before quoting exact numbers in the viva.*
