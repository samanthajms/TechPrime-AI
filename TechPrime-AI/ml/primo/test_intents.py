"""
Intent tests against the trained SVM pipeline (no Flask required).

1. CASES: quick checks, close to the training data (should all pass).
2. data/intents_test_hard.csv: held-out sentences with typos, Taglish, and phrasing that is not in
   intents_train.csv. Never train on these rows; they are the honest accuracy number.

Usage:
    python test_intents.py           # both, failures only for the hard set
    python test_intents.py --all     # also print every hard-set row
"""
from __future__ import annotations

import csv
import json
import sys
from collections import defaultdict
from pathlib import Path

import joblib

from preprocess import preprocess_text

BASE = Path(__file__).resolve().parent
PIPE = BASE / "models" / "primo_intent_pipeline.joblib"
META = BASE / "models" / "primo_intent_meta.json"
HARD = BASE / "data" / "intents_test_hard.csv"

CASES = [
    ("Hello Primo", "greeting"),
    ("hey primo", "greeting"),
    ("Bye", "goodbye"),
    ("see you later", "goodbye"),
    ("Thanks Primo", "gratitude"),
    ("thanks", "gratitude"),
    ("What can you help me with?", "help"),
    ("I want a gaming PC", "product_recommendation"),
    ("help me build a pc", "product_recommendation"),
    ("I need a PC for gaming", "product_recommendation"),
    ("Show me gaming PCs", "product_search"),
    ("Find RTX 4060 products", "product_search"),
    ("do you have ryzen 5", "product_search"),
    ("Is the RTX 4060 in stock?", "stock_inquiry"),
    ("is this available?", "stock_inquiry"),
    ("do you have this", "stock_inquiry"),
    ("How much is this GPU?", "product_price"),
    ("how much?", "product_price"),
    ("what's the price?", "product_price"),
    ("Where is my order?", "order_status"),
    ("Will this RAM work with my B450 motherboard?", "compatibility_question"),
    ("will this work with my motherboard?", "compatibility_question"),
    ("show my saved builds", "saved_build"),
    ("where are my saved builds?", "saved_build"),
    ("open my saved build", "saved_build"),
    ("Tell me a joke about quantum physics", "fallback"),
    ("magkano ang rtx 4060", "product_price"),
    ("how much does the ryzen 5 cost", "product_price"),
    ("meron ba kayong rtx 4060", "stock_inquiry"),
    ("is the 1tb nvme still in stock", "stock_inquiry"),
    ("show the whole laptop category", "product_category"),
    ("find the rtx 4060 ti", "product_search"),
    ("search for asus tuf", "product_search"),
    ("suggest a gpu for 1080p", "product_recommendation"),
    ("where is the one oasis branch", "store_information"),
    ("what is the weather today", "fallback"),
    ("give me details or explain to me the best brand and why?", "brand_advice"),
    ("which brand should I buy and why", "brand_advice"),
    ("explain the best laptop brand", "brand_advice"),
    ("what is the best product in each category", "product_category"),
    ("what is the best gpu", "product_recommendation"),
    ("what is the best gaming laptop", "product_recommendation"),
    ("what is the best ram", "product_recommendation"),
    ("what is the best headphone", "product_recommendation"),
    ("what are the specs of the best gpu", "product_search"),
    ("product description of the best laptop", "product_search"),
    ("what is the quality of the best ram", "product_recommendation"),
]


def classify(pipe, text: str, threshold: float) -> tuple[str, str, float]:
    """Same rule as predict_api.py: below the threshold the reply is the clarifying fallback."""
    proba = pipe.predict_proba([preprocess_text(text)])[0]
    idx = int(proba.argmax())
    raw = str(pipe.classes_[idx])
    conf = float(proba[idx])
    return (raw if conf >= threshold else "fallback"), raw, conf


def run_hard_set(pipe, threshold: float, show_all: bool) -> None:
    if not HARD.is_file():
        print(f"No hard test set at {HARD}")
        return
    with HARD.open(encoding="utf-8", newline="") as f:
        rows = [(r["text"], r["intent"].strip()) for r in csv.DictReader(f)]

    per = defaultdict(lambda: [0, 0])
    wrong_answer = 0   # a different, non-fallback intent: Primo answers the wrong question
    clarified = 0      # fallback instead of the right intent: Primo asks the shopper to rephrase
    passed = 0
    print("Hard test set (held out; typos, Taglish, new phrasing)")
    print("-" * 72)
    for text, expected in rows:
        intent, raw, conf = classify(pipe, text, threshold)
        ok = intent == expected
        per[expected][1] += 1
        if ok:
            passed += 1
            per[expected][0] += 1
        elif intent == "fallback":
            clarified += 1
        else:
            wrong_answer += 1
        if show_all or not ok:
            print(f"[{'PASS' if ok else 'FAIL'}] {text!r}")
            print(f"       -> intent={intent} (raw={raw}, conf={conf:.3f}) expected={expected}")
    print("-" * 72)
    print("Per intent:")
    for intent in sorted(per):
        good, total = per[intent]
        print(f"  {intent:<24} {good:>2}/{total}")
    print(f"Hard set: {passed}/{len(rows)} passed ({passed / len(rows) * 100:.1f}%)")
    print(f"  misses that ask to rephrase (fallback): {clarified}")
    print(f"  misses that answer the wrong intent:    {wrong_answer}")


def main() -> None:
    if not PIPE.is_file():
        raise SystemExit("Train the model first: python train_model.py")

    pipe = joblib.load(PIPE)
    meta = json.loads(META.read_text(encoding="utf-8")) if META.is_file() else {}
    threshold = float(meta.get("confidence_threshold", 0.42))

    print(f"Threshold: {threshold}")
    print("-" * 72)
    passed = 0
    for text, expected in CASES:
        intent, raw, conf = classify(pipe, text, threshold)
        ok = intent == expected
        status = "PASS" if ok else "FAIL"
        if ok:
            passed += 1
        print(f"[{status}] {text!r}")
        print(f"       -> intent={intent} (raw={raw}, conf={conf:.3f}) expected={expected}")
    print("-" * 72)
    print(f"{passed}/{len(CASES)} passed")
    print()
    run_hard_set(pipe, threshold, "--all" in sys.argv)


if __name__ == "__main__":
    main()
