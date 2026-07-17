# OCR / Extraction test prompt (reusable)

Paste the prompt below into a Claude Code chat whenever you want to verify that PDF extraction
(question papers and/or syllabi) still works — e.g. after touching `python-service/app/services/`
(`pdf.py`, `ocr.py`, `parser.py`, `syllabus.py`), bumping a dependency, or when a new batch of
real TU PDFs is available. Attach the PDFs to the chat message itself.

---

## The prompt (copy from here)

> I've attached syllabus and/or question-paper PDFs. Test QForge's extraction pipeline against
> them and tell me whether it's working properly on our project.
>
> **Procedure:**
> 1. The attached PDFs also exist on disk — look for them by filename under `~/Downloads`
>    (including subfolders). If you can't find a file locally, ask me for the path instead of
>    skipping it; the pipeline needs real files, not the chat attachments.
> 2. Copy them into the shared volume (`code/storage/app/test-extract/`) and run the **real**
>    pipeline inside the `qforge_python` container — `pdf.extract_pages` + `parser.parse_pages`
>    for past papers, `syllabus.parse_courses` for syllabi. Don't reimplement the logic; import
>    the app's own modules via a small throwaway harness script.
> 3. For each past paper report: pages, which pages went through OCR, candidate count, question
>    numbers found (flag gaps and duplicates), how many candidates have `marks=None`, and a few
>    text snippets so garbling is visible. For each syllabus report: courses found, per-course
>    code/title, unit count, unit names + hours, and whether back matter (References, Lab Works)
>    leaked in.
> 4. **Compare against the attachments** — you can read the PDFs in chat, so check the extracted
>    output against what the paper actually prints: every top-level question present? marks
>    correct (including section directives like `[10 × 5 = 50]`)? text readable, not scanner-OCR
>    gibberish? unit/section hints right?
> 5. Diagnose any failure to its root cause (embedded junk text layer vs. Tesseract segmentation
>    vs. parser regex vs. genuinely unrecoverable scan). The known decision rules: OCR triggers
>    per page when the text layer is < `ocr_char_threshold` (40) chars **or** image coverage ≥
>    `ocr_image_coverage` (0.8); Tesseract runs at 300 DPI with `--psm 4`; OCR pages get the
>    loose question-number pattern (`l,` / `I.` / `1 1.`), digital pages the strict one.
> 6. If a fix is warranted, make it, add a regression test in `python-service/tests/`
>    (`test_parser.py`, `test_extract.py`, or `test_syllabus.py` — follow the existing fixture
>    style in `conftest.py`), and re-run the whole pytest suite in the container plus the PDF
>    harness again to show before/after numbers. Prefer missing over wrong: never make the parser
>    guess aggressively to inflate counts.
> 7. Clean up when done: delete the throwaway harness scripts and remove
>    `code/storage/app/test-extract/`.
> 8. **If you changed how OCR or extraction behaves, update `docs/VIVA-GUIDE.md`** — §5.6 (the
>    OCR stack, triggers, PSM, numbering sections and its one-line defenses), viva question
>    **Q16c**, and cheat-sheet row **16b** — including refreshed benchmark numbers. Also update
>    the honest-weaknesses list if a limit was removed or discovered. If nothing changed in
>    behavior, leave the guide alone.
>
> Everything runs in Docker with sudo:
> `echo 'rohitshakya' | sudo -S docker compose exec -T qforge_python <cmd>`.

---

## Reference: last benchmark (2026-07-17)

29 real TU past papers (SPM, Data Mining, Principles of Management, Advanced Java; 2069–2083;
mixed born-digital and photocopied scans), after the scan-detection + PSM 4 + numbering fixes:

| Metric | Before | After |
|---|---|---|
| Total candidates | 241 | 368 |
| Candidates missing marks | 44 | 17 |
| Papers yielding their complete question set | 13 / 29 | 26 / 29 |

Known honest limits (not bugs): pen marks over the numerals defeat Tesseract (`2.` → `KR`), and a
paper that prints no per-question marks anywhere correctly yields `marks=null` for the reviewer.
Future runs should stay at or above these numbers; a drop on the same corpus is a regression.

Also check tables: on **digital** pages a ruled table must appear as a markdown table inside its
question (`| A | 8 | | 200 |` — empty cells preserved, prose above/below in reading order); on
**scans** flattened rows are the accepted behavior (Tesseract has no cell concept).
