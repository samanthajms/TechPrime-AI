"""
Choose the confidence threshold for Primo's intent model.

Below the threshold predict_api.py answers "fallback" (Primo asks the shopper to rephrase) instead
of acting on its best guess. Too high: Primo asks to rephrase questions it understood. Too low:
it answers the wrong question, or treats off-topic chat as a product question.

Method: out-of-fold probabilities from repeated stratified 5-fold cross-validation on
intents_train.csv (every row is scored by a model that never saw it), then a sweep of thresholds.
The pick is the highest threshold whose macro F1 (every intent counts the same, fallback included)
is within TOLERANCE of the best: F1 is nearly flat over a wide range and differences that small are
cross-validation noise, so among equals Primo prefers asking to rephrase over answering a wrong guess.
The hard test set (data/intents_test_hard.csv) is NOT used here, so it stays an honest check.

Usage (from this directory, with venv active):
    python tune_threshold.py
Then copy the suggested value into CONFIDENCE_THRESHOLD in train_model.py and retrain.
"""
from __future__ import annotations

import numpy as np
from sklearn.metrics import f1_score
from sklearn.model_selection import RepeatedStratifiedKFold

from train_model import CONFIDENCE_THRESHOLD, DATA_PATH, RANDOM_STATE, build_pipeline, load_dataset

N_SPLITS = 5
N_REPEATS = 3
THRESHOLDS = [round(t, 2) for t in np.arange(0.10, 0.71, 0.02)]
TOLERANCE = 0.005   # macro F1 below the best that still counts as a tie


def main() -> None:
    df = load_dataset(DATA_PATH)
    X = np.array(df["text"].tolist())
    y = np.array(df["intent"].tolist())

    # One (best intent, confidence) per row per repeat, from a model that did not train on that row
    best = []
    conf = []
    truth = []
    rskf = RepeatedStratifiedKFold(n_splits=N_SPLITS, n_repeats=N_REPEATS, random_state=RANDOM_STATE)
    for train_idx, test_idx in rskf.split(X, y):
        pipe = build_pipeline().fit(X[train_idx], y[train_idx])
        proba = pipe.predict_proba(X[test_idx])
        best.extend(pipe.classes_[proba.argmax(axis=1)])
        conf.extend(proba.max(axis=1))
        truth.extend(y[test_idx])
    best = np.array(best)
    conf = np.array(conf)
    truth = np.array(truth)

    print(f"{len(y)} examples, {N_SPLITS}-fold x {N_REPEATS} repeats; current threshold {CONFIDENCE_THRESHOLD}")
    print(f"{'thr':>5} {'accuracy':>9} {'macroF1':>8} {'wrong answer':>13} {'asks rephrase':>14} {'off-topic kept out':>19}")
    rows = []
    off_topic = truth == "fallback"
    on_topic = ~off_topic
    for t in THRESHOLDS:
        pred = np.where(conf >= t, best, "fallback")
        acc = float((pred == truth).mean())
        f1 = float(f1_score(truth, pred, average="macro", zero_division=0))
        wrong = float(((pred != truth) & (pred != "fallback") & on_topic).sum() / on_topic.sum())
        rephrase = float(((pred == "fallback") & on_topic).sum() / on_topic.sum())
        kept_out = float((pred[off_topic] == "fallback").mean())
        rows.append((t, acc, f1, wrong, rephrase, kept_out))
        print(f"{t:>5.2f} {acc:>9.3f} {f1:>8.3f} {wrong:>13.3f} {rephrase:>14.3f} {kept_out:>19.3f}")

    best_f1 = max(r[2] for r in rows)
    pick = max((r for r in rows if r[2] >= best_f1 - TOLERANCE), key=lambda r: r[0])
    print()
    print(f"Best macro F1 {best_f1:.3f}. Highest threshold within {TOLERANCE} of it:")
    print(f"Suggested threshold {pick[0]:.2f} (accuracy {pick[1]:.3f}, macro F1 {pick[2]:.3f}, "
          f"wrong answers {pick[3]:.1%} of shop questions, asks to rephrase {pick[4]:.1%}, "
          f"off-topic kept out {pick[5]:.1%})")


if __name__ == "__main__":
    main()
