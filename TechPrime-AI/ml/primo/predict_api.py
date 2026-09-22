"""
Primo SVM intent prediction API.

POST /predict
{"message": "Is the RTX 4060 in stock?"}

→ {"intent": "stock_inquiry", "confidence": 0.87, "ok": true}

GET /health → status
"""
from __future__ import annotations

import json
from pathlib import Path

import joblib
from flask import Flask, jsonify, request
from flask_cors import CORS

from preprocess import preprocess_text

BASE_DIR = Path(__file__).resolve().parent
MODEL_DIR = BASE_DIR / "models"
PIPELINE_PATH = MODEL_DIR / "primo_intent_pipeline.joblib"
META_PATH = MODEL_DIR / "primo_intent_meta.json"

app = Flask(__name__)
CORS(app, resources={r"/*": {"origins": "*"}})

_pipeline = None
_meta: dict = {}


def load_model() -> None:
    global _pipeline, _meta
    if not PIPELINE_PATH.is_file():
        raise FileNotFoundError(
            f"Model not found: {PIPELINE_PATH}. Run: python train_model.py"
        )
    _pipeline = joblib.load(PIPELINE_PATH)
    if META_PATH.is_file():
        _meta = json.loads(META_PATH.read_text(encoding="utf-8"))
    else:
        _meta = {"confidence_threshold": 0.42}


def get_threshold() -> float:
    return float(_meta.get("confidence_threshold", 0.42))


@app.get("/health")
def health():
    return jsonify(
        {
            "ok": True,
            "service": "primo-svm-intent",
            "model_loaded": _pipeline is not None,
            "intents": _meta.get("intents", []),
            "confidence_threshold": get_threshold(),
        }
    )


@app.post("/predict")
def predict():
    if _pipeline is None:
        return jsonify({"ok": False, "error": "model_not_loaded"}), 503

    data = request.get_json(silent=True) or {}
    message = data.get("message", "")
    if not isinstance(message, str):
        return jsonify({"ok": False, "error": "invalid_message"}), 400

    message = message.strip()
    if message == "" or len(message) > 500:
        return jsonify({"ok": False, "error": "invalid_message"}), 400

    cleaned = preprocess_text(message)
    if cleaned == "":
        return jsonify(
            {
                "ok": True,
                "intent": "fallback",
                "confidence": 0.0,
                "raw_intent": "fallback",
            }
        )

    proba = _pipeline.predict_proba([cleaned])[0]
    classes = list(_pipeline.classes_)
    best_idx = int(proba.argmax())
    raw_intent = str(classes[best_idx])
    confidence = float(proba[best_idx])
    threshold = get_threshold()

    intent = raw_intent if confidence >= threshold else "fallback"

    return jsonify(
        {
            "ok": True,
            "intent": intent,
            "confidence": round(confidence, 4),
            "raw_intent": raw_intent,
            "threshold": threshold,
        }
    )


if __name__ == "__main__":
    load_model()
    # Local-only service; PHP proxies from the browser.
    app.run(host="127.0.0.1", port=5055, debug=False)
