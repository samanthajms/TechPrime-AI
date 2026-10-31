"""
Repeated stratified 5-fold cross-validation of the Primo intent pipeline.

More stable than the single hold-out split in train_model.py (some intents have
only 4-5 test rows there). Use it to compare dataset or model changes against
BASELINE.md. Does not write or replace any model file.

Usage (from this directory, with venv active):
    python evaluate_cv.py
"""
from __future__ import annotations

import json

import numpy as np
from sklearn.metrics import accuracy_score, f1_score, precision_recall_fscore_support
from sklearn.model_selection import RepeatedStratifiedKFold

from train_model import CONFIDENCE_THRESHOLD, DATA_PATH, RANDOM_STATE, build_pipeline, load_dataset

N_SPLITS = 5
N_REPEATS = 5


def main() -> None:
    df = load_dataset(DATA_PATH)
    X = np.array(df["text"].tolist())
    y = np.array(df["intent"].tolist())
    labels = sorted(set(y))

    rskf = RepeatedStratifiedKFold(n_splits=N_SPLITS, n_repeats=N_REPEATS, random_state=RANDOM_STATE)
    accs, f1s = [], []
    per_intent = {lab: [] for lab in labels}
    for train_idx, test_idx in rskf.split(X, y):
        pipe = build_pipeline().fit(X[train_idx], y[train_idx])
        proba = pipe.predict_proba(X[test_idx])
        raw = pipe.classes_[proba.argmax(axis=1)]
        # Same rule as predict_api.py: low confidence becomes fallback
        pred = np.where(proba.max(axis=1) >= CONFIDENCE_THRESHOLD, raw, "fallback")
        accs.append(accuracy_score(y[test_idx], pred))
        f1s.append(f1_score(y[test_idx], pred, average="macro", zero_division=0))
        _, _, f1, _ = precision_recall_fscore_support(y[test_idx], pred, labels=labels, zero_division=0)
        for lab, value in zip(labels, f1):
            per_intent[lab].append(value)

    result = {
        "n_examples": len(y),
        "folds": len(accs),
        "accuracy_mean": round(float(np.mean(accs)), 4),
        "accuracy_std": round(float(np.std(accs)), 4),
        "macro_f1_mean": round(float(np.mean(f1s)), 4),
        "macro_f1_std": round(float(np.std(f1s)), 4),
        "per_intent_f1": {lab: round(float(np.mean(v)), 3) for lab, v in per_intent.items()},
    }
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
