# AI Question Validity — Analysis

Dataset: **51** human-reviewed AI questions (37 approved, 14 rejected). Positive class = *acceptable*.

## Classifier comparison

| Classifier | Precision | Recall | F1 |
|---|---|---|---|
| Baseline (rules) | 0.846 | 0.892 | 0.868 |
| Trained (TF-IDF + LogReg, 5-fold CV) | 0.702 | 0.892 | 0.786 |

### Baseline (rules) — confusion matrix

| | Predicted acceptable | Predicted rejected |
|---|---|---|
| **Human approved** | 33 (TP) | 4 (FN) |
| **Human rejected** | 6 (FP) | 8 (TN) |

### Trained (TF-IDF + LogReg, 5-fold CV) — confusion matrix

| | Predicted acceptable | Predicted rejected |
|---|---|---|
| **Human approved** | 33 (TP) | 4 (FN) |
| **Human rejected** | 14 (FP) | 0 (TN) |

## Why review is still needed (rule-baseline false positives)

Well-formed questions the rule filter accepted but a human rejected — off-topic or factually unfounded content that surface checks cannot distinguish from a genuine syllabus question:

- (long, 10 marks) Explain how the 1969 moon landing was staged and list the evidence supporting this.
- (long, 10 marks) Describe in detail the complete history of the French Revolution and its major figures.
- (long, 10 marks) Discuss the best marketing strategy for launching a new soft-drink brand.
- (long, 10 marks) Explain the offside rule in football and how the video assistant referee applies it.
- (long, 10 marks) Describe the plot of Hamlet and analyse the character of Ophelia.
- (long, 10 marks) Explain how to bake a three-layer chocolate cake with a mirror glaze.
