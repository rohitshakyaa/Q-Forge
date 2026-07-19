# References & Bibliography

> **Reconciled, final-order list.** Every `[n]` used in the drafted chapters (Ch1–Ch6) is reconciled
> into the single ordered **References** list below, **renumbered in order of first appearance** across
> the concatenated report. The front matter, Ch1, Ch3, Ch5, and Ch6 contain no numeric citations; all
> in-text `[n]` markers occur in Ch2 (§2.1–§2.2) and Ch4 (§4.2, which reuses two Ch2 sources). A
> **renumbering map** (old draft `[n]` → new `[n]`) is given so the chapter files can be updated.
> The working bank with per-source confidence stays in [`references.md`](references.md); this file is
> the shippable list.
>
> **Rule (GUIDE §5 rule 6, §6):** every entry in the live References list is a real, checked source.
> Nothing is fabricated. Sources whose bibliographic details could not be verified are held in the
> **Candidates (quarantined)** section, *not* the live list, and their in-text slots remain
> `{{CITATION NEEDED}}`. Read-but-not-cited sources go in the **Bibliography**.

---

## Renumbering map (old draft `[n]` → new `[n]`)

Apply these swaps to the chapter files. Only Ch2 and Ch4 are affected.

| Old `[n]` (draft) | New `[n]` (final) | Source (short) | First appears |
|---|---|---|---|
| [7] | **[1]** | Cormen et al. — CLRS (greedy selection) | Ch2 §2.1.1 |
| [6] | **[2]** | Russell & Norvig — AIMA (CSP / backtracking) | Ch2 §2.1.1 |
| [4] | **[3]** | Smith — Tesseract OCR | Ch2 §2.1.3 |
| [5] | **[4]** | Reimers & Gurevych — Sentence-BERT | Ch2 §2.1.4 |
| [2] | **[5]** | Kurdi et al. — Automatic question generation survey | Ch2 §2.1.5 |
| [3] | **[6]** | Gierl & Haladyna — Automatic Item Generation | Ch2 §2.1.5 |
| [1] | **[7]** | Liu, Wang & Zheng — Ant-colony test-paper generation | Ch2 §2.2 |

**Per-file occurrences to update:**

- **`02-background.md`** — replace, in this reading order: `[7]→[1]` (greedy, §2.1.1), `[6]→[2]`
  (backtracking, §2.1.1), `[4]→[3]` (OCR, §2.1.3), `[5]→[4]` (SBERT, §2.1.4), `[2]→[5]` and `[3]→[6]`
  (AQG/AIG, §2.1.5), `[1]→[7]` (random/metaheuristic baseline, §2.2). Later reuses in §2.2 —
  `[1]→[7]` (metaheuristic), `[6]→[2]` (CSP foundations), and `[2], [3]→[5], [6]` (generative family)
  — take the same new numbers.
- **`04-system-design.md`** — `[7]→[1]` (greedy, §4.2), `[6]→[2]` (backtracking, §4.2, three
  occurrences: engine intro, `BacktrackingResolver`, complexity).

---

## References (IEEE — cited items only, first-appearance order)

[1] T. H. Cormen, C. E. Leiserson, R. L. Rivest, and C. Stein, *Introduction to Algorithms*, 4th ed. Cambridge, MA, USA: MIT Press, 2022.

[2] S. J. Russell and P. Norvig, *Artificial Intelligence: A Modern Approach*, 4th ed. Hoboken, NJ, USA: Pearson, 2021.

[3] R. Smith, "An overview of the Tesseract OCR engine," in *Proc. 9th Int. Conf. Document Analysis and Recognition (ICDAR)*, Curitiba, Brazil, Sep. 2007, pp. 629–633, doi: 10.1109/ICDAR.2007.4376991.

[4] N. Reimers and I. Gurevych, "Sentence-BERT: Sentence embeddings using Siamese BERT-networks," in *Proc. 2019 Conf. Empirical Methods in Natural Language Processing and 9th Int. Joint Conf. Natural Language Processing (EMNLP-IJCNLP)*, Hong Kong, China, Nov. 2019, pp. 3982–3992.

[5] G. Kurdi, J. Leo, B. Parsia, U. Sattler, and S. Al-Emari, "A systematic review of automatic question generation for educational purposes," *International Journal of Artificial Intelligence in Education*, vol. 30, no. 1, pp. 121–204, Mar. 2020, doi: 10.1007/s40593-019-00186-y.

[6] M. J. Gierl and T. M. Haladyna, Eds., *Automatic Item Generation: Theory and Practice*. New York, NY, USA: Routledge, 2012.

[7] D. Liu, J. Wang, and L. Zheng, "Automatic test paper generation based on ant colony algorithm," *Journal of Software*, vol. 8, no. 10, pp. 2600–2606, Oct. 2013, doi: 10.4304/jsw.8.10.2600-2606.

> **Note on [1] and [2]:** both are standard textbooks confirmed to exist; the *edition and year*
> shown are the intended ones but should be checked against the physical copies before the final
> build (GUIDE §5 rule 3). They remain in the live list because the works themselves are certain —
> only the edition detail is pending confirmation.

---

## Outstanding citation gaps (keep `{{CITATION NEEDED}}` in the chapter — do NOT invent)

These in-text markers have **no verified source** and must stay as `{{CITATION NEEDED}}` in the
chapter until the user supplies or approves a real reference.

| Chapter / section | Claim needing a source | Status / lead |
|---|---|---|
| `02-background.md` §2.1.2 (Blueprints & Test Specifications) | The assessment-theory notion of a *test blueprint / table of specifications* as a device for sampling intended content in intended proportions. | **No source in bank.** Needs a standard assessment/measurement reference. User must supply/approve — do not invent. |
| `02-background.md` §2.2 (metaheuristic family) | Related studies pursuing test-paper generation "through refined genetic operators." | **Quarantined candidate** — see "Improved genetic algorithm" below. Confirm author list/year/pages before citing. |
| `02-background.md` §2.2 (integrated systems) | Integrated question-bank + paper-generation systems that package storage, selection, and assembly into one administrative tool. | **Quarantined candidate** — see "Adaptive question bank system" below. Confirm authors/pages before citing. |

---

## Candidates — real but details unconfirmed (resolve or drop before final)

Do **not** cite these in a chapter until their bibliographic details are confirmed (author list,
year, pages). Held here, out of the live References list, so they aren't lost. If confirmed, each
would be inserted at its first-appearance position in §2.2 and every later `[n]` renumbered
accordingly.

- **Improved genetic algorithm for test-paper generation** — "Application of improved genetic
  algorithm in automatic test paper generation," IEEE Xplore doc. 7382551. *(Authors/year/pages
  unconfirmed — IEEE Xplore blocked automated fetch.)* Target slot: `02-background.md` §2.2, the
  "refined genetic operators" gap.
- **Adaptive question bank system** — "Designing an Adaptive Question Bank and Question Paper
  Generation Management System," Springer (LNNS), doi: 10.1007/978-981-15-3514-7_72, 2020.
  *(Authors/exact pages unconfirmed.)* Target slot: `02-background.md` §2.2, the "integrated systems"
  gap.

---

## Bibliography (read but not cited)

*(Sources consulted but not cited in the text — syllabus p. 106 permits a separate Bibliography.
None at present: every verified source consulted is cited in the References list above.)*
