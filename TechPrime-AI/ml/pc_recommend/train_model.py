"""
Train and test the Build a PC Random Forest recommender.

Reads data/train.csv (written by scripts/build_pc_recommend_dataset.php)
and saves models/forest.json for includes/pc_recommend.php.

Testing: 4-fold cross-validation grouped by build, so every row is scored by a
forest that never saw that build. Reported:
  - accuracy / precision / recall / F1 on all rows, rule rows and real rows
  - Hit@3 on real customer builds: hide one part, rank the compatible parts,
    and check whether the part the customer chose is in the top 3. Compared with
    the expert rules alone, the price range alone, and random picks.
The saved model is then refit on every row.

Usage (from TechPrime-AI):
    python ml/pc_recommend/train_model.py
"""
from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, precision_recall_fscore_support
from sklearn.model_selection import GroupKFold

BASE = Path(__file__).resolve().parent
DATA = BASE / "data" / "train.csv"
OUT = BASE / "models" / "forest.json"
# Must match pc_recommend_feature_names() in includes/pc_recommend.php.
FEATURES = [
    "purpose_school",
    "purpose_office",
    "purpose_gaming",
    "purpose_creation",
    "slot_code",
    "tier_gap",
    "build_level",
    "gpu_level",
    "perf_tier",
    "balance",
    "cooling_margin",
    "dual_channel",
    "psu_headroom",
    "psu_rating",
    "psu_atx3",
    "case_bundle",
    "form_slack",
    "brand_match",
    "cand_igpu",
    "cpu_igpu",
    "cpu_cooler",
    "vram",
    "budget_slack",
]
# Purpose one-hot columns (pc_purpose_features() in includes/pc_purpose.php); all zeros = "Not sure yet".
PURPOSES = {
    "Not sure yet": None,
    "School & Everyday": "purpose_school",
    "Office & Work": "purpose_office",
    "Gaming": "purpose_gaming",
    "Content Creation": "purpose_creation",
}
THRESHOLD = 0.5
TOP_K = 3
FOLDS = 4
N_TREES = 60


def make_forest() -> RandomForestClassifier:
    return RandomForestClassifier(
        n_estimators=N_TREES,
        max_depth=16,
        min_samples_leaf=2,
        max_features=0.7,
        random_state=42,
        n_jobs=1,
    )


def export_tree(estimator) -> list[dict]:
    tree = estimator.tree_
    nodes = []
    for i in range(tree.node_count):
        counts = tree.value[i][0]
        total = float(counts.sum()) or 1.0
        nodes.append(
            {
                "f": int(tree.feature[i]),
                "t": round(float(tree.threshold[i]), 5),
                "l": int(tree.children_left[i]),
                "r": int(tree.children_right[i]),
                "p": round(float(counts[1] / total), 4) if len(counts) > 1 else 0.0,
            }
        )
    return nodes


def scores(y_true, proba) -> dict:
    if len(y_true) == 0:
        return {"rows": 0}
    pred = (proba >= THRESHOLD).astype(int)
    precision, recall, f1, _ = precision_recall_fscore_support(
        y_true, pred, average="binary", zero_division=0
    )
    return {
        "rows": int(len(y_true)),
        "accuracy": float(accuracy_score(y_true, pred)),
        "precision": float(precision),
        "recall": float(recall),
        "f1": float(f1),
    }


def hit_at_k(real: pd.DataFrame, columns: list[str], ascending: list[bool]) -> float:
    hits = []
    for _, q in real.groupby("query"):
        top = q.sort_values(columns, ascending=ascending, kind="stable").head(TOP_K)
        hits.append(int(top["label"].max() == 1))
    return float(np.mean(hits)) if hits else 0.0


def main() -> None:
    df = pd.read_csv(DATA)
    X = df[FEATURES]
    y = df["label"].astype(int)
    w = df["weight"].astype(float)

    proba = np.zeros(len(df))
    for train_idx, test_idx in GroupKFold(n_splits=FOLDS).split(X, y, df["group"]):
        forest = make_forest()
        forest.fit(X.iloc[train_idx], y.iloc[train_idx], sample_weight=w.iloc[train_idx])
        proba[test_idx] = forest.predict_proba(X.iloc[test_idx])[:, 1]
    df["proba"] = proba
    df["abs_gap"] = df["tier_gap"].abs()

    is_real = df["source"] == "real"
    metrics = {
        "all": scores(y, proba),
        "rule": scores(y[~is_real], proba[~is_real]),
        "real": scores(y[is_real], proba[is_real]),
    }
    by_purpose = {}
    one_hot = [c for c in PURPOSES.values() if c]
    for name, column in PURPOSES.items():
        picked = df[column] > 0 if column else (df[one_hot] <= 0).all(axis=1)
        mask = (~is_real & picked).to_numpy()
        by_purpose[name] = scores(y[mask], proba[mask])
    real = df[is_real]
    queries = real.groupby("query").size()
    hit = {
        "queries": int(len(queries)),
        "model": hit_at_k(real, ["proba"], [False]),
        "expert_rules": hit_at_k(real, ["rule", "abs_gap"], [False, True]),
        "price_range": hit_at_k(real, ["abs_gap"], [True]),
        "random": float(np.mean(np.minimum(TOP_K, queries) / queries)) if len(queries) else 0.0,
    }

    forest = make_forest()
    forest.fit(X, y, sample_weight=w)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "type": "random_forest_classifier",
        "features": FEATURES,
        "threshold": THRESHOLD,
        "n_estimators": N_TREES,
        "n_rows": int(len(df)),
        "n_rule_rows": int((~is_real).sum()),
        "n_real_rows": int(is_real.sum()),
        "evaluation": f"{FOLDS}-fold cross-validation grouped by build",
        "accuracy": metrics["all"]["accuracy"],
        "precision": metrics["all"]["precision"],
        "recall": metrics["all"]["recall"],
        "f1": metrics["all"]["f1"],
        "metrics": metrics,
        "rule_metrics_by_purpose": by_purpose,
        f"hit_at_{TOP_K}": hit,
        "feature_importance": dict(
            zip(FEATURES, [round(float(v), 4) for v in forest.feature_importances_])
        ),
        "trees": [export_tree(est) for est in forest.estimators_],
    }
    OUT.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")

    for name, m in metrics.items():
        if m["rows"]:
            print(
                f"{name:>4} rows={m['rows']:<5} accuracy={m['accuracy']:.4f} "
                f"precision={m['precision']:.4f} recall={m['recall']:.4f} f1={m['f1']:.4f}"
            )
    for name, m in by_purpose.items():
        if m["rows"]:
            print(f"  rule rows, {name:<18} rows={m['rows']:<5} accuracy={m['accuracy']:.4f} f1={m['f1']:.4f}")
    print(
        f"Hit@{TOP_K} on {hit['queries']} real customer picks: model={hit['model']:.2%} "
        f"expert rules={hit['expert_rules']:.2%} price range={hit['price_range']:.2%} random={hit['random']:.2%}"
    )
    print("feature importance:", payload["feature_importance"])
    print(f"saved {OUT}")


if __name__ == "__main__":
    main()
