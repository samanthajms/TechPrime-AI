"""
Train the Build a PC Random Forest recommender.

Reads data/train.csv (written by scripts/build_pc_recommend_dataset.php)
and saves models/forest.json for includes/pc_recommend.php.

Usage (from TechPrime-AI, primo venv):
    python ml/pc_recommend/train_model.py
"""
from __future__ import annotations

import json
from pathlib import Path

import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, precision_recall_fscore_support
from sklearn.model_selection import train_test_split

BASE = Path(__file__).resolve().parent
DATA = BASE / "data" / "train.csv"
OUT = BASE / "models" / "forest.json"
FEATURES = [
    "price_ratio",
    "socket_ok",
    "ddr_ok",
    "watt_headroom",
    "brand_match",
    "in_stock",
    "form_slack",
]


def export_tree(estimator) -> list[dict]:
    tree = estimator.tree_
    nodes = []
    for i in range(tree.node_count):
        counts = tree.value[i][0]
        total = float(counts.sum()) or 1.0
        nodes.append(
            {
                "f": int(tree.feature[i]),
                "t": float(tree.threshold[i]),
                "l": int(tree.children_left[i]),
                "r": int(tree.children_right[i]),
                "p": float(counts[1] / total) if len(counts) > 1 else 0.0,
            }
        )
    return nodes


def main() -> None:
    df = pd.read_csv(DATA)
    X = df[FEATURES]
    y = df["label"].astype(int)
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.25, random_state=42, stratify=y
    )
    forest = RandomForestClassifier(
        n_estimators=40,
        max_depth=8,
        min_samples_leaf=4,
        random_state=42,
        n_jobs=1,
    )
    forest.fit(X_train, y_train)
    pred = forest.predict(X_test)
    acc = accuracy_score(y_test, pred)
    precision, recall, f1, _ = precision_recall_fscore_support(
        y_test, pred, average="binary", zero_division=0
    )
    OUT.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "type": "random_forest_classifier",
        "features": FEATURES,
        "threshold": 0.5,
        "n_estimators": 40,
        "n_train": int(len(X_train)),
        "n_test": int(len(X_test)),
        "accuracy": float(acc),
        "precision": float(precision),
        "recall": float(recall),
        "f1": float(f1),
        "trees": [export_tree(est) for est in forest.estimators_],
    }
    OUT.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    print(
        f"accuracy={acc:.4f} precision={precision:.4f} recall={recall:.4f} f1={f1:.4f} file={OUT}"
    )


if __name__ == "__main__":
    main()
