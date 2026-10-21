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
python test_intents.py          :: quick checks + held-out hard test set (data/intents_test_hard.csv)
python evaluate_cv.py           :: repeated 5-fold cross-validation on the training data
python tune_threshold.py        :: suggests CONFIDENCE_THRESHOLD for train_model.py
```
Never add rows from `data/intents_test_hard.csv` to the training data; it is the honest accuracy check.
Results and history: `BASELINE.md`.

## Learning from real questions
When the model cannot place a message (final intent `fallback`), `backend/api/primo_chat.php` saves it to
the table `primo_unanswered` (create it once with `database/migration_primo_unanswered.sql`). Only the
message text is kept, with e-mail addresses and phone/long numbers masked; no user id, session or IP.
Rows are deleted after 90 days, and one visitor can add at most 30.

Review loop (run from the project folder `TechPrime-AI/`):
```bat
php scripts\primo_export_unanswered.php                 :: CSV in your user folder, never inside htdocs
:: open the CSV, fill the "intent" column (leave empty or write skip for junk)
python ml\primo\add_reviewed.py C:\Users\you\primo_review_YYYY-MM-DD.csv           :: preview
python ml\primo\add_reviewed.py C:\Users\you\primo_review_YYYY-MM-DD.csv --write   :: append
cd ml\primo && python train_model.py && python test_intents.py   :: compare with BASELINE.md, then push
```

PHP chat bridge: `backend/api/primo_chat.php` (called by Primo UI).
