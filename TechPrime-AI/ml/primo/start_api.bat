@echo off
cd /d "%~dp0"
echo Starting Primo SVM API on http://127.0.0.1:5055 ...
echo Keep this window open while using Primo chat.
echo.
".venv\Scripts\python.exe" predict_api.py
pause
