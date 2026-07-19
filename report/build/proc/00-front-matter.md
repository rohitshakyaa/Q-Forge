<!-- Front matter — roman-numeral pagination (i, ii, …) in Word; body restarts at Arabic 1 from Chapter 1. -->
<!-- Every institutional fact is a {{PLACEHOLDER}}; collect against GUIDE §8 before the final build. -->

# {{PROJECT_TITLE}}

**A Project Report**

Submitted in partial fulfillment of the requirements for the degree of
{{PROGRAM}} (Semester {{SEMESTER}}), under the course {{COURSE}}

Submitted by:

{{AUTHOR_1_NAME}} ({{AUTHOR_1_ROLL_NO}})
{{AUTHOR_2_NAME}} ({{AUTHOR_2_ROLL_NO}})
{{AUTHOR_3_NAME}} ({{AUTHOR_3_ROLL_NO}})

Submitted to:

{{DEPARTMENT}}
{{COLLEGE_CAMPUS}}
Institute of Science and Technology (IOST)
Tribhuvan University
Kathmandu, Nepal

{{SUBMISSION_DATE}}

<!-- Cover repeats as the title page. -->

---

# Student's Declaration

We hereby declare that the work presented in this report titled *{{PROJECT_TITLE}}*, submitted in partial fulfillment of the requirements for {{COURSE}} of the {{PROGRAM}} program at {{COLLEGE_CAMPUS}}, {{DEPARTMENT}}, Institute of Science and Technology, Tribhuvan University, is our own original work. It has been carried out under the supervision of {{SUPERVISOR_NAME}}. The material contained in this report has not been submitted, in whole or in part, to this or any other institution for the award of any degree or diploma. All sources of information and prior work used in this report have been duly acknowledged and cited.

| Name | Exam / Roll Number | Signature |
|---|---|---|
| {{AUTHOR_1_NAME}} | {{AUTHOR_1_EXAM_NO}} | ......................... |
| {{AUTHOR_2_NAME}} | {{AUTHOR_2_EXAM_NO}} | ......................... |
| {{AUTHOR_3_NAME}} | {{AUTHOR_3_EXAM_NO}} | ......................... |

Date: {{SUBMISSION_DATE}}

---

# Certificate

## Supervisor's Recommendation

I hereby recommend that this report, prepared under my supervision by {{AUTHOR_1_NAME}}, {{AUTHOR_2_NAME}}, and {{AUTHOR_3_NAME}}, titled *{{PROJECT_TITLE}}*, be accepted for evaluation in partial fulfillment of the requirements for {{COURSE}} of the {{PROGRAM}} program. To the best of my knowledge, the work embodied in this report is the candidates' own and has been carried out satisfactorily.

<br>

.........................................
{{SUPERVISOR_NAME}}
Supervisor
{{DEPARTMENT}}
{{COLLEGE_CAMPUS}}

<!-- pagebreak -->

## Letter of Approval

This is to certify that this report titled *{{PROJECT_TITLE}}*, submitted by {{AUTHOR_1_NAME}}, {{AUTHOR_2_NAME}}, and {{AUTHOR_3_NAME}} in partial fulfillment of the requirements for {{COURSE}} of the {{PROGRAM}} program, has been examined and approved in its present form.

<br>

| | |
|---|---|
| ......................................... | ......................................... |
| {{HOD_COORDINATOR}} | {{SUPERVISOR_NAME}} |
| Head of Department / Coordinator | Supervisor |
| {{DEPARTMENT}} | {{DEPARTMENT}} |
| {{COLLEGE_CAMPUS}} | {{COLLEGE_CAMPUS}} |
| | |
| ......................................... | ......................................... |
| {{INTERNAL_EXAMINER}} | {{EXTERNAL_EXAMINER}} |
| Internal Examiner | External Examiner |

Date: {{SUBMISSION_DATE}}

---

# Acknowledgement

We express our sincere gratitude to {{DEPARTMENT}}, {{COLLEGE_CAMPUS}}, Institute of Science and Technology, Tribhuvan University, for including project work in the curriculum and for providing the environment in which this project could be undertaken.

We are especially indebted to our supervisor, {{SUPERVISOR_NAME}}, for the continual guidance, technical feedback, and encouragement that shaped this work at every stage. We also thank {{HOD_COORDINATOR}}, Head of Department / Coordinator, and the faculty members of the department for their valuable suggestions during the review sessions.

Finally, we thank our friends and families for their support throughout the development of QForge. Any errors that remain in this report are our own.

{{AUTHOR_1_NAME}}
{{AUTHOR_2_NAME}}
{{AUTHOR_3_NAME}}

---

# Abstract

Preparing examination question papers by hand is slow, error-prone, and difficult to balance: a setter must cover every unit of a course, respect a fixed distribution of marks and question types, and avoid repeating questions used in recent years, all at once and under time pressure. QForge is a smart question-paper generation platform built to automate this task while keeping the setter in control. A teacher describes the shape of an examination once as a reusable blueprint of slots, and QForge assembles a valid paper from a bank of approved questions using a deterministic, constraint-based algorithm implemented from scratch. The algorithm combines a greedy selection pass with backtracking repair and is parameterised by a seed, so the same inputs reproduce the same paper and every outcome can be explained. When the question bank is too thin to satisfy a blueprint, an optional, locally hosted artificial-intelligence model proposes candidate questions to top up the bank, while a retrieval-augmented semantic-similarity guard suppresses near-duplicates; the artificial intelligence is supportive only, and the algorithm always decides the final paper. The system is organised as a service-oriented architecture with a Laravel orchestrator, a Python processing service for extraction and generation, and a Vue interface. The implemented system produces valid, balanced, reproducible papers, enforces unit coverage and non-repetition, extracts and imports past papers under review, and closes feasibility gaps through the top-up path, with the full test suite passing. The result is a working hybrid system in which deterministic logic, not the model, owns the examination paper.

Keywords: Question paper generation, Constraint satisfaction, Backtracking, Blueprint, Semantic similarity, Retrieval-augmented generation, Laravel.

---

# Table of Contents

<!-- Page numbers are finalised via Word's automatic TOC field; do not hand-number. -->

**Front Matter** *(roman numerals)*
- Student's Declaration
- Certificate (Supervisor's Recommendation, Letter of Approval)
- Acknowledgement
- Abstract
- Table of Contents
- List of Abbreviations
- List of Figures
- List of Tables

**Chapter 1: Introduction**
- 1.1 Introduction
- 1.2 Problem Statement
- 1.3 Objectives
- 1.4 Scope and Limitation
- 1.5 Development Methodology
- 1.6 Report Organization

**Chapter 2: Background Study and Literature Review**
- 2.1 Background Study
  - 2.1.1 Constraint Satisfaction, Greedy Selection, and Backtracking
  - 2.1.2 Blueprints and Test Specifications in Assessment
  - 2.1.3 Optical Character Recognition and PDF Text Extraction
  - 2.1.4 Embeddings and Semantic Similarity for Duplicate Detection
  - 2.1.5 Large-Language-Model-Assisted Question Generation
- 2.2 Literature Review

**Chapter 3: System Analysis**
- 3.1 System Analysis
  - 3.1.1 Requirement Analysis
  - 3.1.2 Feasibility Analysis
  - 3.1.3 Analysis (Object-Oriented)

**Chapter 4: System Design**
- 4.1 Design
  - Refined Class Diagram
  - Refined Object Diagram
  - Refined State Diagram
  - Refined Sequence Diagram
  - Refined Activity Diagram
  - Component Diagram
  - Deployment Diagram
  - Database Design
- 4.2 Algorithm Details

**Chapter 5: Implementation and Testing**
- 5.1 Implementation
  - 5.1.1 Tools Used
  - 5.1.2 Implementation Details of Modules
- 5.2 Testing
  - 5.2.1 Unit Testing
  - 5.2.2 System Testing
- 5.3 Result Analysis

**Chapter 6: Conclusion and Future Recommendations**
- 6.1 Conclusion
- 6.2 Future Recommendations

**References**

**Appendices** — {{STUB: appendices.md not yet drafted (screenshots, source snippets, supervisor visit log per GUIDE §3); add to TOC when written.}}

---

# List of Abbreviations

| Abbreviation | Expansion |
|---|---|
| AI | Artificial Intelligence |
| AIG | Automatic Item Generation |
| API | Application Programming Interface |
| BSc CSIT | Bachelor of Science in Computer Science and Information Technology |
| CRUD | Create, Read, Update, Delete |
| CSP | Constraint Satisfaction Problem |
| DFS | Depth-First Search |
| DOCX | Office Open XML Document format |
| ER | Entity–Relationship |
| FK | Foreign Key |
| HTTP | Hypertext Transfer Protocol |
| IOST | Institute of Science and Technology |
| JSON | JavaScript Object Notation |
| LLM | Large Language Model |
| LRU | Least Recently Used (cache eviction policy) |
| OCR | Optical Character Recognition |
| ORM | Object–Relational Mapping |
| PDF | Portable Document Format |
| PK | Primary Key |
| RAG | Retrieval-Augmented Generation |
| REST | Representational State Transfer |
| SBERT | Sentence-BERT (sentence embeddings) |
| SPA | Single-Page Application |
| TU | Tribhuvan University |
| UI | User Interface |
| UML | Unified Modeling Language |

---

# List of Figures

<!-- Page numbers finalised via Word's List of Figures field. -->

| Figure | Caption |
|---|---|
| Figure 3.1 | Use case diagram — actors and use cases of QForge. |
| Figure 3.2 | Project schedule — the algorithm-first milestone sequence. |
| Figure 3.3 | Analysis-level class diagram of the QForge problem domain. |
| Figure 3.4 | Object diagram — a worked instance snapshot of one generated paper. |
| Figure 3.5 | State diagram — Question approval lifecycle. |
| Figure 3.6 | State diagram — Paper preview/save/export lifecycle. |
| Figure 3.7 | State diagram — document_uploads processing lifecycle. |
| Figure 3.8 | Sequence diagram — generating a paper (deterministic, synchronous, preview-first). |
| Figure 3.9 | Sequence diagram — extracting question candidates from an uploaded document. |
| Figure 3.10 | Activity diagram — control flow of the paper-generation algorithm. |
| Figure 4.1 | Refined class diagram — the PaperGeneration service classes and their collaborations. |
| Figure 4.2 | Refined object diagram — the collaborating objects of one generation run. |
| Figure 4.3 | Refined state diagram — generation outcomes and the AI top-up recovery path. |
| Figure 4.4 | Refined sequence diagram — generation with the RAG similarity guard and the AI top-up path. |
| Figure 4.5 | Refined activity diagram — the five phases of generation with the structural short-circuit and preview/save lifecycle. |
| Figure 4.6 | Component diagram — Laravel orchestrator, Python processor, Vue presentation, and the backing stores. |
| Figure 4.7 | Deployment diagram — the Docker Compose container topology, ports, and named volumes. |
| Figure 4.8 | Relational schema — the persistence view of the object-oriented design. |

---

# List of Tables

<!-- Page numbers finalised via Word's List of Tables field. -->

| Table | Caption |
|---|---|
| Table 3.1 | Use case descriptions. |
| Table 5.1 | Unit test cases for the generation engine. |
| Table 5.2 | System test cases (API feature tests and Python extraction). |
