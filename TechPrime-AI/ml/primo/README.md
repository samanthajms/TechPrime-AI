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

## Test intents
```bat
python test_intents.py
```

PHP chat bridge: `backend/api/primo_chat.php` (called by Primo UI).
