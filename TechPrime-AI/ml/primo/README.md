# Primo SVM Intent Service

## Setup
```bat
cd ml\primo
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

## Train
```bat
python train_model.py
```

## Run API (keep this terminal open)
```bat
python predict_api.py
```
Listens on `http://127.0.0.1:5055`

`GET /health` returns JSON with `model_loaded` (`200` when the pipeline is loaded, `503` when it is not). It also reports `llm_configured` (true only when `PRIMO_LLM_API_KEY` is set). The key itself is never returned.

`POST /predict` is unchanged: intent and confidence only.

`POST /reply` writes a conversational sentence from facts the PHP app already loaded from the database. If the key is missing or the LLM call fails, the body is `{"ok":true,"reply":null,"llm":false}` and PHP keeps its existing reply.

## Render

Service root directory: `ml/primo`

| Setting | Value |
|---|---|
| Python | 3.12.5 (`.python-version`) |
| Build Command | `pip install -r requirements.txt && python train_model.py` |
| Start Command | `python predict_api.py` |
| Health Check Path | `/health` |

The PHP site reaches this service with `PRIMO_API_URL` (no trailing path), for example `https://techprime-ai-1.onrender.com`. Unset, `backend/api/primo_chat.php` calls `http://127.0.0.1:5055`.

Optional LLM (same Render service, no second service). Set these on the Render service only. Do not commit them.

| Variable | Required | Purpose |
|---|---|---|
| `PRIMO_LLM_API_KEY` | yes, for LLM replies | Groq (or other OpenAI-compatible) secret |
| `PRIMO_LLM_BASE_URL` | no | Default `https://api.groq.com/openai/v1` |
| `PRIMO_LLM_MODEL` | no | Default `openai/gpt-oss-20b` |

Groq is the default because the chat endpoint matches OpenAI's, the Python service needs no new package, and the 20B model is the cheaper current option. Change the base URL and model to use another vendor. PHP never sees the key. Order lookups stay in PHP and are not sent to the LLM.

## Test intents
```bat
python test_intents.py
```

PHP chat bridge: `backend/api/primo_chat.php` (called by Primo UI).
