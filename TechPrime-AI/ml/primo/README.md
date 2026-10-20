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

`GET /health` returns JSON with `model_loaded` (`200` when the pipeline is loaded, `503` when it is not).

## Render

Service root directory: `ml/primo`

| Setting | Value |
|---|---|
| Python | 3.12.5 (`.python-version`) |
| Build Command | `pip install -r requirements.txt && python train_model.py` |
| Start Command | `python predict_api.py` |
| Health Check Path | `/health` |

The PHP site reaches this service with `PRIMO_API_URL` (no trailing path), for example `https://your-primo-service.onrender.com`. Unset, `backend/api/primo_chat.php` calls `http://127.0.0.1:5055`.

## Test intents
```bat
python test_intents.py
```

PHP chat bridge: `backend/api/primo_chat.php` (called by Primo UI).
