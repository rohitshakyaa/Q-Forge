# QForge Paper-Generation Algorithm (M2)

> The academic centerpiece. A **deterministic greedy + backtracking** engine that turns a
> blueprint into a valid question paper — or, when the bank is too thin, reports exactly what it
> lacks. Pure PHP, no external solver, fully unit-tested.
>
> Scope: originally written at **Milestone 2**, updated through **Post-M5.1**. Later-milestone
> changes are called out inline: cross-paper "exclude last N" + `used_count` bumping (M3),
> multi-unit questions / union coverage (Post-M5), coverage-aware greedy + per-unit maximums +
> the corrected structural-infeasibility rule (Post-M5.1).

Source: [`code/app/Services/PaperGeneration/`](../code/app/Services/PaperGeneration/)

---

## 1. Vocabulary

| Term | Meaning |
|---|---|
| **Blueprint** | A teacher-owned template: `sections` (the paper structure), `unitRules` (allowed units), `unitAllocations` (per-unit **max** caps since Post-M5.1; `marks` display-only), `exclusionRules`. Stored as JSON in `blueprints.definition`. |
| **Slot** | One position in the paper to be filled by exactly one question, carrying `(sectionLabel, type, marks, displayNo)`. Sections are flattened into an ordered list of slots. |
| **Candidate** | An **approved** question matching a slot's `subject + type + marks`, within the allowed units. |
| **Coverage** | The hard rule that *every* allowed unit (`unitRules`) appears at least once in the finished paper. |
| **GenerationResult** | The output: either a valid paper + all-pass `constraint_results`, or a best-effort partial + `missing_slots` naming the shortfall. |

**Design stance.** `sections` are authoritative for structure. `unitRules` is *both* the hard
candidate filter *and* the coverage rule. `unitAllocations` was a **soft** hint in M2; since
Post-M5.1 its `count` is enforced as a per-unit **maximum** (unset = uncapped, `marks` stays
display-only). The greedy step was deliberately unit-agnostic in M2 (pure least-recently-used) —
which is what let a naïve pass starve a unit and what the backtracking pass repaired. Since
Post-M5.1 the greedy is **coverage-aware** (uncovered-unit-first, same rank the backtracker always
used), so most papers cover all units on the first pass; backtracking remains the repair pass for
what greedy's myopia still misses (cap and marks-shape conflicts across later slots). Selection is
rule-based on purpose — coverage is a hard rank, repetition a hard exclusion window, overload a
hard cap — rather than a tuned additive score whose weights could trade a unit away.

---

## 2. The components (single responsibility each)

| Class | Responsibility |
|---|---|
| `BlueprintCompiler` | `blueprint.definition` → `CompiledBlueprint` (ordered **slots**, `allowedUnitIds`, per-unit **max caps**, `lastNPapers`, structural-feasibility predicates). |
| `CandidateFilter` | For a slot, query approved questions matching subject/type/marks with **any tagged unit** in the allowed set, excluding already-chosen ids. (Cross-paper "last N" is an injectable closure — **no-op in M2**.) |
| `GreedySelector` | Pick the best candidate **coverage-first**: covers-an-uncovered-unit, then `used_count` asc, then `id` asc. Deterministic; degenerates to pure LRU with no unit rules. |
| `ConstraintValidator` | Score selections → per-section counts, total marks, **unit coverage**, **unit maximums**, no in-paper repetition → `ConstraintResult[]`. |
| `BacktrackingResolver` | On an invalid greedy result, search for a fully-valid assignment via a bounded, deterministic DFS (uncovered-unit-first, cap-busting candidates pruned). |
| `PaperGenerator` | Facade that orchestrates all of the above and returns a `GenerationResult`. Pure — **no DB writes**. |

Persistence (draft `papers` + `paper_questions`) is the **controller's** job, not the engine's — this
keeps the engine unit-testable in isolation.

---

## 3. The algorithm, step by step

### Phase 0 — Compile (`BlueprintCompiler::compile`)
1. Read `definition.sections`. For each section of `count` n, emit **n slots**, each carrying the
   section label, the normalised `type` (`"Short Answer"` → `short`, `"Long Answer"` → `long`,
   `"MCQ"` → `mcq`), `marksEach`, and a running 1-based `displayNo`. Slots stay in section order.
2. Resolve `definition.unitRules` (the truthy unit **names**) to `allowedUnitIds` within the subject.
   *Empty ⇒ no unit restriction and no coverage requirement.*
3. Compile `unitAllocations` counts into **per-unit max caps** (`unitId => Σcount`; only allowed
   units, entries ≤ 0 dropped ⇒ uncapped) and capture `exclusionRules.lastNPapers`.
4. Return a `CompiledBlueprint{ subjectId, totalMarks, slots[], allowedUnitIds[], unitNames{}, unitCaps{}, lastNPapers }`,
   which also carries the structural-feasibility predicates (see Phase 3).

### Phase 1 — Greedy fill (`PaperGenerator::greedyFill`)
For each slot **in order**:
1. **Filter** (`CandidateFilter::for`): approved questions where
   `subject_id = X AND status = 'approved' AND type = slot.type AND marks = slot.marks`,
   restricted to `allowedUnitIds`, **excluding ids already chosen in this paper**, ordered
   `used_count ASC, id ASC`.
   *(The cross-paper last-N exclusion closure is wired here but inert in M2.)*
2. **Cap guard** (Post-M5.1): reject candidates whose selection would push any tagged capped unit
   past its maximum (a multi-unit question counts against every tagged capped unit).
3. **Pick** (`GreedySelector::pick`): candidates covering a **still-uncovered allowed unit** rank
   first, then lowest `used_count`, then lowest `id`. If the pool is empty, the slot is left
   unfilled.
4. Record the pick, add its id to the in-paper exclusion set (guarantees no repetition), and merge
   its tagged units into the running covered set / cap usage.

### Phase 2 — Validate (`ConstraintValidator::validate`)
Score the current selections into a `ConstraintResult[]` (`{label, expected, got, pass}`):
- **Per section:** filled count == required count.
- **Total marks:** Σ(marks of filled slots) == `blueprint.total_marks`.
- **Unit coverage** (only when units are restricted): distinct units used == |allowedUnitIds| —
  union semantics, a multi-unit question covers every allowed unit it is tagged with.
- **Unit maximums** (only when caps exist): every capped unit tagged by ≤ its max questions.
- **No repetition:** zero duplicate question ids within the paper.

If **every slot is filled** *and* **every constraint passes** → return **success**
(`satisfiable = true`). Done.

### Phase 3 — Backtracking repair (`BacktrackingResolver::resolve`)
Greedy is coverage-aware but **myopic** — it cannot see how a pick constrains later slots (cap
exhaustion, marks-shape conflicts), so an invalid greedy paper is still possible. When it is,
search for a fully-valid assignment:

0. **Structural short-circuit** (Post-M5.1): when `CompiledBlueprint::structurallyInfeasible()`
   holds — coverage demands more units than the paper can ever reach
   (`|allowedUnitIds| > MAX_AI_UNITS_PER_QUESTION(2) × |slots|`), or every allowed unit is capped
   and the caps sum below the slot count — skip the DFS entirely (it cannot succeed) and go
   straight to Phase 4.
1. Build the **full candidate pool per slot** (no per-paper exclusions; the resolver enforces
   uniqueness itself).
2. **Depth-first search** over slots, depth = slot index:
   - At each slot, order its remaining candidates **uncovered-allowed-unit first**, then `used_count`,
     then `id` — actively steering toward coverage.
   - Skip any candidate whose id is already used on this path (no repetition) and **prune** any
     that would exceed a per-unit cap on this path.
   - Recurse. On reaching the last slot, **accept only if coverage holds**; otherwise backtrack and
     try the next candidate.
3. Bounded by `MAX_ITERATIONS = 50_000` so it always terminates. Returns a complete valid assignment,
   or `null` if none exists within the budget.

If the resolver returns an assignment → re-validate → **success** (`satisfiable = true`).

### Phase 4 — Explain the shortfall (`PaperGenerator::computeMissingSlots`)
If backtracking also fails, the blueprint is infeasible. Return a **best-effort partial** (the greedy
fill — whatever *could* be placed) plus `missing_slots[]`, computed in two tiers:

1. **Supply deficit (primary):** group slots by `(section, type, marks)`; for each group,
   `deficit = required − distinct approved questions available`. Every group with `deficit > 0`
   becomes a `MissingSlot{ type, marks, need: deficit, unitIds }`, e.g. *"3× 20-mark long"*,
   targeting the scarcest allowed unit (the **two** scarcest when units outnumber slots, so an AI
   top-up question pulls coverage double duty).
2. **Coverage deficit (fallback):** if supply is adequate everywhere but the paper still can't be
   covered — when `|units| ≤ |slots|`, name each **uncovered allowed unit** as a single-unit
   missing slot; when `|units| > |slots|`, only unit-spanning questions can close the gap, so
   **pair the `2×(units − slots)` scarcest units** (one missing slot per pair, plus singles for
   any uncovered leftovers). Each `MissingSlot.unitIds` is ordered scarcest-first — the AI top-up
   makes index 0 the new question's primary unit and tags the full set.

### Phase 5 — Persist (controller: `PaperController::generate`)
- **On success:** in a transaction, insert a `papers` row (`status = draft`) and one `paper_questions`
  row per selection. **`questions.used_count` is *not* incremented** — that, and counting last-N
  repetition against *saved* papers, is deferred to **Save in M3**.
- **On infeasible:** persist **nothing**; return `{ satisfiable:false, paper:<unpersisted partial>,
  missing_slots[], constraint_results[] }`.
- Both responses are HTTP **200**; `422` is reserved for bad input.

---

## 4. Why it's deterministic (and identical between runs in M2)

Candidate ordering is a total order — `uncoveredRank, used_count ASC, id ASC` — every tie chain
ends in the unique `id`. With the same bank, the same blueprint yields the same paper. In M2 nothing mutates that ordering between runs — `used_count` is never bumped
(deferred to M3) and the last-N exclusion is inert — so consecutive generations are **identical by
design**. M3's repetition control is exactly what makes successive papers diverge.

---

## 5. Complexity

Let `S` = slots, `C` = max candidates per slot.
- **Greedy + validate:** `O(S · C)` — one filtered query + linear pick per slot.
- **Backtracking:** worst case exponential, but bounded by `MAX_ITERATIONS`; the uncovered-unit-first
  ordering reaches a covering assignment quickly in practice, so it rarely approaches the cap.

---

## 6. Flowchart

Source: [`diagrams/algorithm-m2.mmd`](diagrams/algorithm-m2.mmd)

```mermaid
flowchart TD
    A([POST /papers/generate · blueprint_id]) --> B[BlueprintCompiler:<br/>sections → ordered slots,<br/>unitRules → allowedUnitIds]

    B --> C[/Greedy pass — for each slot in order/]
    C --> D[CandidateFilter:<br/>approved · subject · type · marks<br/>any tag ∈ allowedUnits · exclude chosen<br/>reject cap-busting candidates]
    D --> E[GreedySelector:<br/>uncovered-unit-first,<br/>then used_count, then id]
    E --> F{More slots?}
    F -- yes --> C

    F -- no --> G[ConstraintValidator:<br/>counts · total marks · unit coverage ·<br/>unit maximums · no repetition]
    G --> H{All slots filled<br/>AND all constraints pass?}

    H -- yes --> S1([SUCCESS])

    H -- no --> H2{Structurally infeasible?<br/>units > 2×slots, or every unit<br/>capped and Σcaps < slots}
    H2 -- yes --> L
    H2 -- no --> I[Build full candidate pool per slot]
    I --> J[BacktrackingResolver:<br/>bounded DFS, uncovered-unit-first,<br/>distinct ids, caps pruned,<br/>coverage must hold]
    J --> K{Valid assignment<br/>found?}

    K -- yes --> G2[Re-validate selections] --> S1

    K -- no --> L[computeMissingSlots:<br/>1 supply deficit = required − available<br/>2 else uncovered allowed units<br/>paired scarcest-first when units > slots]
    L --> F2([INFEASIBLE:<br/>best-effort partial + missing_slots])

    S1 --> P[Controller: persist papers status=draft<br/>+ paper_questions in a transaction<br/>NB: used_count NOT bumped — M3]
    P --> R1([200 · paper + constraint_results])

    F2 --> R2([200 · satisfiable:false ·<br/>partial paper + missing_slots + constraint_results<br/>nothing persisted])

    classDef ok fill:#16351f,stroke:#3fae6b,color:#d7f5e3;
    classDef bad fill:#3a1620,stroke:#d65a76,color:#f7d9e1;
    class S1,R1 ok;
    class F2,R2 bad;
```

---

## 7. How the unit tests map to the phases

These live in [`code/tests/Unit/PaperGeneration/PaperGeneratorTest.php`](../code/tests/Unit/PaperGeneration/PaperGeneratorTest.php)
and *are* the proof of the contribution:

| Test | Exercises |
|---|---|
| feasible blueprint → valid paper, all pass | Phases 0–2 |
| unit coverage enforced | Phase 2 coverage rule |
| no in-paper repetition | Phase 1 exclusion set |
| infeasible → correct `missing_slots` | Phase 4 supply deficit |
| **backtracking recovers a greedy-fails case** | Phase 3 — the centerpiece (first proves the *naive LRU* greedy alone starves a unit, then the full engine recovers) |
| coverage-aware greedy covers all units first-pass | Phase 1 coverage rank (Post-M5.1) |
| unit cap limits a rich unit / multi-unit question consumes both caps | Phases 1–3 cap guard (Post-M5.1) |
| caps summing below slots → structurally infeasible | Phase 3 short-circuit + message (Post-M5.1) |
| multi-unit questions cover more units than slots | Phase 2 union coverage (Post-M5) |
| missing slots pair the scarcest units when units > slots | Phase 4 pairing (Post-M5.1) |
| empty unit rules leave coverage and caps inert | regression — engine equals plain LRU |
