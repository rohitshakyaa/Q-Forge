# Chapter 2: Background Study and Literature Review

## 2.1 Background Study

QForge draws together ideas from several distinct fields — combinatorial search, educational
assessment, document digitisation, and natural-language processing — and combines them into a single
working system. This section examines each of the underlying concepts, but always in the specific
role it plays inside QForge, rather than as an abstract topic. The intent is not to define these
ideas in general terms but to make clear *why* each one is needed and *where* the built system relies
on it.

### 2.1.1 Constraint Satisfaction, Greedy Selection, and Backtracking

At its core, assembling an examination paper from a fixed template is a constraint-satisfaction
problem: a set of positions must each be given a value, subject to rules that the finished
assignment as a whole must respect. QForge casts every position in a paper — a **slot** of a
particular type and mark value — as a variable, the pool of approved questions that legally fit it
as that variable's domain, and the blueprint's rules (correct type and marks, an allowed unit,
coverage of every unit, and no repetition) as the constraints the completed paper must satisfy.
Framing the task this way is what allows the system to report, for any produced paper, exactly which
constraints held, and for any failure, exactly which requirement could not be met.

Two complementary strategies from the study of such problems drive the generation engine. The first
is a greedy heuristic: rather than searching exhaustively, the engine walks the slots in order and
commits, at each step, to the locally most attractive candidate — the question that covers a
still-uncovered unit and has been used least often. Greedy methods of this kind are attractive
because a single forward pass is fast and, on the common case, sufficient [7]. Their known weakness
is that a choice which looks best in isolation can foreclose a valid completion later on; greedy
selection offers no guarantee of finding a solution even when one exists. QForge therefore pairs it
with a second strategy — backtracking — which supplies precisely the guarantee the greedy pass
lacks. Backtracking explores the space of assignments systematically, extending a partial solution
one slot at a time and retracting the most recent choice whenever a slot can no longer be filled or
the coverage requirement can no longer be met [6]. Because it revisits earlier decisions, it will
locate a valid paper whenever one is reachable within its search budget, recovering exactly the cases
the myopic greedy pass mishandles. The engine runs the fast greedy fill first and invokes the bounded
backtracking search only as a repair pass when validation fails, so the system pays for the more
expensive search only when it is actually required.

### 2.1.2 Blueprints and Test Specifications in Assessment

The notion that an examination should be built against an explicit specification of its shape —
how many questions of each kind, carrying what marks, drawn from which parts of the syllabus —
predates any software and is well established in assessment practice as a test blueprint or table
of specifications, a device for ensuring that a test samples the intended content in the intended
proportions rather than reflecting whatever the setter happened to recall {{CITATION NEEDED}}. QForge
makes this specification a first-class, reusable object: a teacher authors a **blueprint** once,
describing the paper's sections, the type and mark value of each slot, the units the paper may draw
from, and the repetition rules it must observe. Every later generation is an attempt to realise that
blueprint from the current question bank. Capturing the specification explicitly is what turns the
balance of a paper from a matter of the setter's memory into a property the system can enforce and
verify, and it is the artefact that makes generation repeatable across examination cycles.

### 2.1.3 Optical Character Recognition and PDF Text Extraction

To seed and grow its question bank from a department's existing material, QForge must recover machine-
readable text from PDF documents of two very different kinds. A born-digital PDF already carries an
embedded text layer, and its characters can be read directly; a scanned or photocopied paper is only
a bitmap image and carries no such layer, so its text must be reconstructed by optical character
recognition — the conversion of an image of printed text into the characters it depicts. QForge uses
the Tesseract engine for this reconstruction [4], rasterising a page and passing it through OCR only
when the page is judged to be a scan. The decision is made per page rather than per document, because
real examination papers routinely mix a digitally typeset cover sheet with photocopied question
pages, and a single scanned page can even carry a misleading text layer baked in by the scanner's own
low-quality OCR. Recognising that recovered text is inherently noisier than embedded text, the
extraction pipeline treats every OCR-derived candidate as provisional and routes it through human
review before it can ever enter the bank the generator draws upon.

### 2.1.4 Embeddings and Semantic Similarity for Duplicate Detection

Deciding whether two questions are effectively the same cannot be done by comparing their characters:
"Define a binary tree" and "What is a binary tree?" are the same question in different words, while a
literal text match would treat them as unrelated. QForge resolves meaning rather than spelling by
means of embeddings — representations that map a piece of text to a fixed-length vector of numbers
positioned so that texts of similar meaning land close together, regardless of the particular words
they use. The closeness of two such vectors is measured by cosine similarity, the cosine of the angle
between them, which yields a score that rises toward one as two texts converge in meaning. This
family of techniques was advanced substantially by sentence-level embedding models that produce
directly comparable vectors for whole sentences, making semantic comparison efficient enough to apply
at scale [5]. QForge puts this single operation to work in several guises: flagging a freshly
extracted question that paraphrases one already in the bank, discarding AI-written candidates that
merely reword existing ones, and steering the generator away from placing two questions that mean the
same thing on one paper. In each case the comparison is a similarity score against stored vectors,
and in each case that score informs a decision without ever overriding the deterministic rules that
govern the paper itself.

### 2.1.5 Large-Language-Model-Assisted Question Generation

When a blueprint demands more questions of some type, mark value, and unit than the bank can supply,
QForge can call a locally hosted large language model to draft fresh candidate questions and close
the shortfall. Automatically producing assessment items in this way is an established line of
research: systematic surveys of automatic question generation document a field that has moved from
rule- and template-based methods toward neural generation [2], and the broader theory of automatic
item generation frames how assessment items can be produced systematically rather than authored one
by one [3]. A recognised hazard of unconstrained language-model generation is that the model, lacking
knowledge of the specific syllabus, will produce fluent but off-topic or fabricated content. QForge
mitigates this by grounding every generation prompt in the relevant syllabus text and exemplar
questions retrieved from its own corpus, so the model writes about the intended material rather than
inventing it. Crucially, the generated text is treated only as candidate wording: the system stamps
the question's type, marks, and unit from the blueprint slot rather than trusting the model, and the
deterministic algorithm alone decides which questions reach the paper. The AI is therefore
supportive, never authoritative — it enlarges the pool of raw material, and nothing more.

## 2.2 Literature Review

Automated construction of examination and test papers has been studied for some time, and the
proposed approaches can be grouped into a small number of recurring strategies. Reviewing them by
strategy — rather than paper by paper — makes clear both what each family achieves and where it falls
short, and thereby exposes the gap that QForge's hybrid design is intended to fill.

The simplest strategy selects questions at random from a bank until a paper's requirements appear to
be met. Its appeal is that it is trivial to implement and introduces variety between successive
papers, but the guarantees it can offer are correspondingly weak: random selection cannot ensure that
every required topic is covered or that mark totals come out exactly, and when it fails to produce an
acceptable paper it offers no account of why. It is best understood as a baseline against which more
disciplined methods are measured [1].

A second and more heavily studied family treats paper composition as an optimisation problem and
applies metaheuristic search — most prominently genetic algorithms and ant-colony optimisation — to
navigate the very large space of possible question combinations toward one that scores well against a
weighted objective. Work in this vein has modelled the multiple, competing demands on a paper (its
coverage, difficulty distribution, and total marks) as terms in a fitness function and evolved or
foraged for a combination that balances them [1], with related studies pursuing the same goal through
refined genetic operators {{CITATION NEEDED}}. These methods handle large banks and multi-objective
trade-offs well, but they carry two costs relevant here. Because they optimise a weighted aggregate,
a strong score on some objectives can compensate for a violated requirement on another, so a hard
constraint such as full unit coverage is not strictly guaranteed; and the stochastic search that
gives them their reach also makes a given result harder to reproduce and to explain to an examiner.

A third family stays closer to the constraint-satisfaction framing and uses systematic search —
backtracking and related constraint-solving techniques — to construct a paper that provably meets
every hard rule, an approach whose foundations lie in the classical treatment of constraint
satisfaction [6]. Its strength is exactly the guarantee the metaheuristic methods relax: if a valid
paper exists, a complete search will find it, and the reasoning is transparent. Its weakness is cost,
since an unguided search over a large bank can become expensive, which is why practical systems
temper it with heuristics or bounds.

A fourth and more recent direction shifts the focus from selecting existing questions to generating
new ones, using automatic item generation and, latterly, large language models to author assessment
content directly [2], [3]. This line addresses a problem the selection-based families cannot — a bank
too thin to satisfy any request — but it introduces concerns of its own around the correctness,
relevance, and originality of generated items, and it does not by itself decide how those items are
assembled into a balanced paper.

Alongside these algorithmic strands sit integrated question-bank and paper-generation systems that
package storage, selection, and paper assembly into a single administrative tool
{{CITATION NEEDED}}. Such systems demonstrate the practical value of the workflow but tend to treat
the selection step as a supporting feature rather than the object of study, and they seldom foreground
reproducibility or an explicit account of constraint satisfaction.

Across these approaches a gap emerges. The random and metaheuristic families buy variety at the cost
of guaranteed constraint satisfaction and reproducibility; the systematic-search family buys those
guarantees but addresses only selection from an adequate bank; and the generative family can enlarge
a thin bank but cannot on its own assemble a valid paper. No single prior approach delivers all of
determinism, guaranteed constraint satisfaction, an explainable outcome, and a remedy for an
insufficient bank at once. QForge is positioned in precisely this space. It makes a deterministic
greedy-plus-backtracking engine the sole authority over paper composition — securing guaranteed
constraint satisfaction, reproducibility for a given seed, and a per-constraint explanation of every
result — while relegating language-model generation to a strictly supporting role that replenishes
the bank without ever deciding the paper. This division of labour, in which rule-based search owns
correctness and AI merely supplies raw material behind it, is the hybrid design the remainder of this
report develops.

## Progress

- ✅ 2.1 Background Study — report/02-background.md
- ✅ 2.2 Literature Review — report/02-background.md
