"""
Quick intent tests against the trained SVM pipeline (no Flask required).

Usage:
    python test_intents.py
"""
from __future__ import annotations

import json
from pathlib import Path

import joblib

from preprocess import preprocess_text

BASE = Path(__file__).resolve().parent
PIPE = BASE / "models" / "primo_intent_pipeline.joblib"
META = BASE / "models" / "primo_intent_meta.json"

CASES = [
    ("Hello Primo", "greeting"),
    ("Bye", "goodbye"),
    ("Thanks Primo", "gratitude"),
    ("What can you help me with?", "help"),
    ("I want a gaming PC", "product_recommendation"),
    ("Can you recommend a gaming PC?", "product_recommendation"),
    ("I need a PC for gaming", "product_recommendation"),
    ("Show me gaming PCs", "product_search"),
    ("Find RTX 4060 products", "product_search"),
    ("Is the RTX 4060 in stock?", "stock_inquiry"),
    ("How much is this GPU?", "product_price"),
    ("Where is my order?", "order_status"),
    ("Will this RAM work with my B450 motherboard?", "compatibility_question"),
    ("Tell me a joke about quantum physics", "fallback"),
]


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
        cleaned = preprocess_text(text)
        proba = pipe.predict_proba([cleaned])[0]
        classes = list(pipe.classes_)
        idx = int(proba.argmax())
        raw = classes[idx]
        conf = float(proba[idx])
        intent = raw if conf >= threshold else "fallback"

        ok = intent == expected
        status = "PASS" if ok else "FAIL"
        if ok:
            passed += 1
        print(f"[{status}] {text!r}")
        print(f"       -> intent={intent} (raw={raw}, conf={conf:.3f}) expected={expected}")
    print("-" * 72)
    print(f"{passed}/{len(CASES)} passed")


if __name__ == "__main__":
    main()
