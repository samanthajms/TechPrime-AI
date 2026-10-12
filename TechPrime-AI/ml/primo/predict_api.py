"""
Primo SVM intent prediction API.

POST /predict
{"message": "Is the RTX 4060 in stock?"}

→ {"intent": "stock_inquiry", "confidence": 0.87, "ok": true}

POST /phrase
{"facts": "MSI RTX 5070 Ti — ₱65950.00 — 2 on hand"}

→ {"ok": true, "reply": "...", "phrased": true}

GET /health → {"ok", "model_loaded", ...} (200 if loaded, 503 if not)
"""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
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
    loaded = _pipeline is not None
    body = {
        "ok": loaded,
        "service": "primo-svm-intent",
        "model_loaded": loaded,
        "intents": _meta.get("intents", []),
        "confidence_threshold": get_threshold(),
    }
    body.update(_llm_public_status())
    # 200 only when the trained pipeline is ready (Render health check uses HTTP status).
    return jsonify(body), (200 if loaded else 503)


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


def _llm_settings() -> tuple[str, str, str]:
    key = os.environ.get("PRIMO_LLM_API_KEY", "").strip().strip('"').strip("'")
    base = os.environ.get("PRIMO_LLM_BASE_URL", "https://api.groq.com/openai/v1").strip().strip('"').strip("'").rstrip("/")
    model = os.environ.get("PRIMO_LLM_MODEL", "openai/gpt-oss-20b").strip().strip('"').strip("'")
    return key, base, model


def _llm_public_status() -> dict:
    """Says whether the running app can see a key. Never includes the secret."""
    key, base, model = _llm_settings()
    return {
        "llm_key_set": key != "",
        "llm_key_length": len(key),
        "llm_key_prefix_ok": key.startswith("gsk_"),
        "llm_base_set": base != "",
        "llm_model_set": model != "",
    }


def phrase_facts(facts: str) -> tuple[str, bool]:
    """Rewrite catalog facts with Groq. The original sentence stays when Groq is off or fails."""
    key, base, model = _llm_settings()
    if key == "" or base == "" or model == "":
        return facts, False

    payload = {
        "model": model,
        "temperature": 0.2,
        "max_tokens": 220,
        "messages": [
            {
                "role": "system",
                "content": (
                    "You are Primo, the EasyPC shop assistant. Rewrite FACTS as one short friendly reply. "
                    "Use only FACTS. Keep every product name, peso amount, and quantity exactly as written. "
                    "Do not add products, prices, stock, or warranties. No markdown."
                ),
            },
            {"role": "user", "content": facts},
        ],
    }
    req = urllib.request.Request(
        base + "/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {key}",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            body = json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError):
        return facts, False

    content = (((body.get("choices") or [{}])[0].get("message") or {}).get("content"))
    if isinstance(content, list):
        content = "".join(part.get("text", "") for part in content if isinstance(part, dict))
    if not isinstance(content, str):
        return facts, False
    text = content.strip()
    if text == "" or len(text) > 700:
        return facts, False
    return text, True


def chat_reply(history: list) -> str | None:
    """Answer a general question. Returns None when Groq is off or fails."""
    key, base, model = _llm_settings()
    if key == "" or base == "" or model == "":
        return None

    messages = [{
        "role": "system",
        "content": (
            "You are Primo. Answer the user's question directly in a natural short conversation. "
            "General questions such as homework, advice, jokes, and everyday topics should be answered on their own. "
            "Do not turn those into an EasyPC product pitch. "
            "Do not invent EasyPC product names, prices, or stock. "
            "Mention the shop only when the user asks about a computer product. No markdown."
        ),
    }]
    for turn in history[-8:]:
        if not isinstance(turn, dict):
            continue
        role = turn.get("role")
        content = turn.get("content")
        if role not in ("user", "assistant") or not isinstance(content, str):
            continue
        content = content.strip()
        if content == "":
            continue
        messages.append({"role": role, "content": content[:800]})
    if len(messages) < 2:
        return None

    payload = {
        "model": model,
        "temperature": 0.6,
        "max_tokens": 400,
        "messages": messages,
    }
    req = urllib.request.Request(
        base + "/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {key}",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            body = json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError):
        return None

    content = (((body.get("choices") or [{}])[0].get("message") or {}).get("content"))
    if isinstance(content, list):
        content = "".join(part.get("text", "") for part in content if isinstance(part, dict))
    if not isinstance(content, str):
        return None
    text = content.strip()
    if text == "" or len(text) > 1200:
        return None
    return text


@app.post("/phrase")
def phrase():
    data = request.get_json(silent=True) or {}
    facts = data.get("facts", "")
    if not isinstance(facts, str):
        return jsonify({"ok": False, "error": "invalid_facts"}), 400
    facts = facts.strip()
    if facts == "" or len(facts) > 1200:
        return jsonify({"ok": False, "error": "invalid_facts"}), 400
    reply, phrased = phrase_facts(facts)
    return jsonify({"ok": True, "reply": reply, "phrased": phrased})


@app.post("/chat")
def chat():
    data = request.get_json(silent=True) or {}
    history = data.get("messages")
    if not isinstance(history, list) or history == []:
        return jsonify({"ok": False, "error": "invalid_messages"}), 400
    reply = chat_reply(history)
    if reply is None:
        body = {"ok": False, "error": "chat_unavailable"}
        body.update(_llm_public_status())
        return jsonify(body)
    return jsonify({"ok": True, "reply": reply})


if __name__ == "__main__":
    try:
        load_model()
    except Exception as exc:
        # Keep /health reachable so Render can see model_loaded=false instead of a dead port.
        app.logger.error("Primo model failed to load: %s", exc)
    # Local default: 127.0.0.1:5055. Render sets PORT (and RENDER); listen on 0.0.0.0 there.
    hosted = bool(os.environ.get("RENDER")) or "PORT" in os.environ
    host = os.environ.get("PRIMO_HOST", "0.0.0.0" if hosted else "127.0.0.1")
    port = int(os.environ.get("PORT", "5055"))
    app.run(host=host, port=port, debug=False, use_reloader=False)
