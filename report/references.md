# References & Bibliography — working bank

> **Working file, not final copy.** This is the reference bank the GUIDE (§6) calls for. It holds
> the `[n] → source` map, each entry's **confidence**, **what it supports in QForge**, and the
> **chapter section** it belongs to. When a chapter cites a source, use its `[n]` here. Before the
> final build: (a) confirm the two Tier-B textbook editions against your physical copies, (b) resolve
> or drop the Tier-C candidates, (c) renumber `[n]` in order of *first appearance in the text*.
>
> **Rule:** every entry here is a real, checked source. Never add a citation that hasn't been
> verified. Mark any gap `{{CITATION NEEDED}}` in the chapter, not here.

---

## `[n] → source` map (confidence · QForge relevance · where it's cited)

| `[n]` | Source (short) | Confidence | Supports in QForge | Report section |
|---|---|---|---|---|
| [1] | Liu, Wang & Zheng — Ant Colony test-paper generation (J. Software, 2013) | **Verified** (title page read) | Prior automated paper-generation systems; its random/backtracking/AI taxonomy mirrors QForge's hybrid design | 2.2 Literature Review |
| [2] | Kurdi et al. — Systematic review of automatic question generation (IJAIED, 2020) | **Verified** (search) | Survey grounding the AI question-generation field behind the AI top-up | 2.1 Background / 2.2 Lit Review |
| [3] | Gierl & Haladyna (eds.) — Automatic Item Generation (Routledge, 2012) | **Verified** (search) | Theory of AI-generated assessment items (QForge's `/generate-questions`) | 2.1 Background |
| [4] | Smith — Overview of the Tesseract OCR Engine (ICDAR, 2007) | **Verified** (search) | The OCR fallback in the Python `/extract` pipeline | 2.1 Background |
| [5] | Reimers & Gurevych — Sentence-BERT (EMNLP-IJCNLP, 2019) | **Verified** (search) | Sentence embeddings + cosine similarity behind the RAG semantic-duplicate guard | 2.1 Background |
| [6] | Russell & Norvig — AIMA, 4th ed. (Pearson, 2021) | **Real; verify edition** | CSP + backtracking foundations of the generator | 2.1 Background |
| [7] | Cormen, Leiserson, Rivest & Stein — CLRS, 4th ed. (MIT Press, 2022) | **Real; verify edition** | Greedy-selection foundations | 2.1 Background |

---

## References (IEEE draft — cited items only)

Renumber in order of first appearance before the final build.

[1] D. Liu, J. Wang, and L. Zheng, "Automatic test paper generation based on ant colony algorithm," *Journal of Software*, vol. 8, no. 10, pp. 2600–2606, Oct. 2013, doi: 10.4304/jsw.8.10.2600-2606.

[2] G. Kurdi, J. Leo, B. Parsia, U. Sattler, and S. Al-Emari, "A systematic review of automatic question generation for educational purposes," *International Journal of Artificial Intelligence in Education*, vol. 30, no. 1, pp. 121–204, Mar. 2020, doi: 10.1007/s40593-019-00186-y.

[3] M. J. Gierl and T. M. Haladyna, Eds., *Automatic Item Generation: Theory and Practice*. New York, NY, USA: Routledge, 2012.

[4] R. Smith, "An overview of the Tesseract OCR engine," in *Proc. 9th Int. Conf. Document Analysis and Recognition (ICDAR)*, Curitiba, Brazil, Sep. 2007, pp. 629–633, doi: 10.1109/ICDAR.2007.4376991.

[5] N. Reimers and I. Gurevych, "Sentence-BERT: Sentence embeddings using Siamese BERT-networks," in *Proc. 2019 Conf. Empirical Methods in Natural Language Processing and 9th Int. Joint Conf. Natural Language Processing (EMNLP-IJCNLP)*, Hong Kong, China, Nov. 2019, pp. 3982–3992.

[6] S. J. Russell and P. Norvig, *Artificial Intelligence: A Modern Approach*, 4th ed. Hoboken, NJ, USA: Pearson, 2021.

[7] T. H. Cormen, C. E. Leiserson, R. L. Rivest, and C. Stein, *Introduction to Algorithms*, 4th ed. Cambridge, MA, USA: MIT Press, 2022.

---

## Candidates — real but details unconfirmed (resolve or drop before final)

Do **not** cite these in a chapter until their bibliographic details are confirmed (author list, year,
pages). Listed here so they aren't lost.

- **Improved genetic algorithm for test-paper generation** — "Application of improved genetic algorithm in automatic test paper generation," IEEE Xplore doc. 7382551. *(Authors/year/pages unconfirmed — IEEE Xplore blocked automated fetch. A GA alternative to cite as related work in 2.2 if verified.)*
- **Adaptive question bank system** — "Designing an Adaptive Question Bank and Question Paper Generation Management System," Springer (LNNS), doi: 10.1007/978-981-15-3514-7_72, 2020. *(Authors/exact pages unconfirmed. Good "similar system" for 2.2 if verified.)*

---

## Bibliography (read but not cited)

*(Add here any source consulted but not cited in the text — syllabus p. 106 permits a separate
Bibliography. Empty for now.)*
