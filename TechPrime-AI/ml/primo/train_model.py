"""
Train Primo intent classifier: TF-IDF (word + char) + SVM (RBF).

Usage (from this directory, with venv active):
    python train_model.py
"""
from __future__ import annotations

import json
from pathlib import Path

import joblib
import pandas as pd
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics import (
    accuracy_score,
    classification_report,
    confusion_matrix,
    precision_recall_fscore_support,
)
from sklearn.model_selection import train_test_split
from sklearn.pipeline import FeatureUnion, Pipeline
from sklearn.svm import SVC

from preprocess import preprocess_text

BASE_DIR = Path(__file__).resolve().parent
DATA_PATH = BASE_DIR / "data" / "intents_train.csv"
MODEL_DIR = BASE_DIR / "models"
PIPELINE_PATH = MODEL_DIR / "primo_intent_pipeline.joblib"
META_PATH = MODEL_DIR / "primo_intent_meta.json"
REPORT_PATH = MODEL_DIR / "last_eval_report.json"

RANDOM_STATE = 42
TEST_SIZE = 0.25
CONFIDENCE_THRESHOLD = 0.42


def load_dataset(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path)
    if "text" not in df.columns or "intent" not in df.columns:
        raise ValueError("Dataset must have columns: text, intent")
    df = df.dropna(subset=["text", "intent"]).copy()
    df["text"] = df["text"].astype(str).map(preprocess_text)
    df["intent"] = df["intent"].astype(str).str.strip()
    df = df[df["text"] != ""]
    # Drop exact duplicate texts (keep first) to reduce leakage risk across splits
    df = df.drop_duplicates(subset=["text"], keep="first")
    return df


def build_pipeline() -> Pipeline:
    # Word n-grams capture phrases; char_wb helps short conversational variants
    features = FeatureUnion(
        [
            (
                "word",
                TfidfVectorizer(
                    analyzer="word",
                    ngram_range=(1, 2),
                    min_df=1,
                    max_df=0.95,
                    sublinear_tf=True,
                    strip_accents="unicode",
                ),
            ),
            (
                "char",
                TfidfVectorizer(
                    analyzer="char_wb",
                    ngram_range=(3, 5),
                    min_df=1,
                    max_df=0.98,
                    sublinear_tf=True,
                ),
            ),
        ]
    )
    classifier = SVC(
        kernel="rbf",
        C=2.5,
        gamma="scale",
        probability=True,
        class_weight="balanced",
        random_state=RANDOM_STATE,
    )
    return Pipeline(
        [
            ("features", features),
            ("svm", classifier),
        ]
    )


def main() -> None:
    print("=" * 60)
    print("Primo SVM Intent Training (TF-IDF word+char + SVC RBF)")
    print("=" * 60)

    df = load_dataset(DATA_PATH)
    print(f"Loaded {len(df)} examples from {DATA_PATH}")
    print("Intent distribution:")
    print(df["intent"].value_counts().to_string())
    print()

    X = df["text"].tolist()
    y = df["intent"].tolist()

    X_train, X_test, y_train, y_test = train_test_split(
        X,
        y,
        test_size=TEST_SIZE,
        random_state=RANDOM_STATE,
        stratify=y,
    )

    pipe = build_pipeline()
    pipe.fit(X_train, y_train)

    y_pred = pipe.predict(X_test)
    acc = accuracy_score(y_test, y_pred)
    labels = sorted(set(y_test) | set(y_pred))
    precision, recall, f1, support = precision_recall_fscore_support(
        y_test, y_pred, labels=labels, zero_division=0
    )
    report_text = classification_report(y_test, y_pred, digits=3, zero_division=0)
    cm = confusion_matrix(y_test, y_pred, labels=labels)

    print("-" * 60)
    print(f"Hold-out Accuracy: {acc:.4f} ({acc * 100:.2f}%)")
    print("-" * 60)
    print("Classification Report:")
    print(report_text)
    print("Confusion Matrix (rows=true, cols=pred):")
    print("Labels:", labels)
    print(cm)
    print()

    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump(pipe, PIPELINE_PATH)

    per_intent = {}
    for i, lab in enumerate(labels):
        per_intent[lab] = {
            "precision": float(precision[i]),
            "recall": float(recall[i]),
            "f1": float(f1[i]),
            "support": int(support[i]),
        }

    meta = {
        "model_type": "sklearn.pipeline.Pipeline",
        "steps": [
            "FeatureUnion(TfidfVectorizer word 1-2, TfidfVectorizer char_wb 3-5)",
            "SVC(kernel=rbf, probability=True)",
        ],
        "dataset": str(DATA_PATH.name),
        "n_samples": len(df),
        "n_train": len(X_train),
        "n_test": len(X_test),
        "accuracy": float(acc),
        "macro_precision": float(precision.mean()) if len(precision) else 0.0,
        "macro_recall": float(recall.mean()) if len(recall) else 0.0,
        "macro_f1": float(f1.mean()) if len(f1) else 0.0,
        "per_intent": per_intent,
        "confusion_matrix_labels": labels,
        "confusion_matrix": cm.tolist(),
        "intents": sorted(df["intent"].unique().tolist()),
        "confidence_threshold": CONFIDENCE_THRESHOLD,
        "random_state": RANDOM_STATE,
    }
    META_PATH.write_text(json.dumps(meta, indent=2), encoding="utf-8")
    REPORT_PATH.write_text(
        json.dumps({"classification_report": report_text, **meta}, indent=2),
        encoding="utf-8",
    )

    print(f"Saved pipeline -> {PIPELINE_PATH}")
    print(f"Saved metadata -> {META_PATH}")
    print("Training complete.")


if __name__ == "__main__":
    main()
