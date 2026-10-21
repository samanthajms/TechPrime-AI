# Primo intent model — evaluation record

Model: TF-IDF (word 1–2 + char_wb 3–5) + SVM (RBF, C=2.5, balanced). Below the confidence threshold the
API answers `fallback` (Primo asks the shopper to rephrase). Environment: Python 3.12.5, scikit-learn 1.5.2.

| | Before (2026-10-20) | After (2026-10-21) |
|---|---|---|
| Training examples (after duplicates removed) | 671 | 922 |
| Taglish / Filipino examples | 11 | 102 |
| Smallest intent | brand_advice, 16 | brand_advice, 40 |
| Confidence threshold | 0.42 | 0.32 |
| **Hard test set** (`python test_intents.py`) | **95/160 (59.4%)** | **134/160 (83.8%)** |
| Hard set misses: asks to rephrase / wrong intent | 53 / 12 | 15 / 11 |
| Quick checks (`CASES` in test_intents.py) | 47/47 | 47/47 |
| 5×5 cross-validation accuracy (`python evaluate_cv.py`) | 82.5% ± 2.9 | 82.7% ± 1.7 |

## 1. Hard test set — the main number

`data/intents_test_hard.csv`: 160 sentences, 10 per intent, written **before** any training data was added:
typos and chat spelling ("thank youuu", "is it still avail", "wheres my stuff"), Taglish ("magkano yung
ryzen 7 7700", "kasya ba yung 360 aio sa case ko"), and phrasing that is not in the training data.
It is never used for training or tuning. Every new training row was checked against it, and rows within
0.80 character-n-gram cosine of a test sentence were dropped (3 were).

Per intent, before → after: brand_advice 5→10, compatibility_question 3→7, fallback 7→6, goodbye 4→9,
gratitude 5→8, greeting 8→10, help 2→4, order_status 8→10, product_category 7→10, product_price 6→10,
product_recommendation 9→8, product_search 9→9, return_refund 4→8, saved_build 4→9, stock_inquiry 7→8,
store_information 7→8.

What made the difference (same scikit-learn, same test set):

| Training data | Threshold 0.42 | Threshold 0.32 |
|---|---|---|
| Old (671 examples) | 95/160 (59.4%) | 101/160 (63.1%) |
| New (922 examples) | 130/160 (81.2%) | 134/160 (83.8%) |

Most of the gain comes from the new examples. The lower threshold adds about 2.5 points: fewer
"please rephrase" replies (24 → 15) for a few more wrong guesses (6 → 11), mostly harmless ones such as an
off-topic question read as `goodbye`, which still gets the general reply.

Still weak: `help` (4/10, mostly Taglish such as "pano ka gamitin") and `fallback` (6/10: off-topic
sentences with shop words, e.g. "can you order me food" → order_status). These were left alone on purpose:
adding examples aimed at the remaining test misses would turn the test set into training data. The next
round should come from real shopper messages that Primo answered with `fallback`.

Limitation: the training additions and the test set were written by the same person, so the hard set is
honest about wording but not fully independent. A test set written by someone else (or real chat logs)
would be a stronger check.

## 2. Training data added (2026-10-21)

251 rows: Taglish/Filipino for every intent (salamat, magkano, kasya ba, pwede ba, nasaan na order ko,
anong oras kayo...), chat spellings (g2g, tenks, thx), and more English phrasings for the smallest intents
(brand_advice, order_status, saved_build, return_refund, store_information). Every intent now has at least
40 examples.

## 3. Threshold (`python tune_threshold.py`)

Out-of-fold probabilities from 5-fold × 3 cross-validation on the training data (not the hard set).
Macro F1 is almost flat from 0.10 to 0.32 (0.841–0.845), so the script picks the highest threshold within
0.005 of the best: **0.32**. On shop questions that means about 8% wrong answers and 9% asks to rephrase
(at 0.42: 7% and 13%); 74% of off-topic messages go to the fallback reply.

## 4. Cross-validation (`python evaluate_cv.py`)

5 folds × 5 repeats, threshold applied as in the API. Before and after are measured on different data:
the new training set is larger and harder (Taglish, slang), so per-intent F1 moved both ways and these
numbers should not be read as before/after. Use the hard test set for that.

| Intent | F1 before (671 ex, 0.42) | F1 after (922 ex, 0.32) | Examples now |
|---|---|---|---|
| fallback | 0.575 | 0.559 | 80 |
| product_category | 0.694 | 0.709 | 43 |
| goodbye | 0.785 | 0.807 | 59 |
| store_information | 0.787 | 0.815 | 50 |
| product_search | 0.803 | 0.786 | 101 |
| return_refund | 0.803 | 0.839 | 46 |
| help | 0.806 | 0.760 | 57 |
| product_price | 0.860 | 0.879 | 50 |
| stock_inquiry | 0.881 | 0.867 | 52 |
| greeting | 0.883 | 0.900 | 60 |
| gratitude | 0.897 | 0.913 | 54 |
| compatibility_question | 0.909 | 0.935 | 45 |
| saved_build | 0.918 | 0.935 | 44 |
| product_recommendation | 0.948 | 0.928 | 120 |
| order_status | 0.961 | 0.867 | 42 |
| brand_advice | 0.965 | 0.920 | 40 |

`product_category` vs `product_search` is the most common confusion, but both go to the same PHP
handler (`primo_product_intent()`), which only changes the intro sentence, so shoppers see little
difference.

## Notes

- The live site uses the Render service (`PRIMO_API_URL`). Render's build runs `train_model.py`, so the new
  data and threshold go live only after this is pushed and the service redeploys. `requirements.txt` does
  not pin scikit-learn (the originally committed model was built with 1.9.1), so Render's numbers can differ
  slightly from these.
- Before-change files: the committed model and data are in git history (`a4f98af`).
