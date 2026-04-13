@echo off
REM Arranca el webhook FastAPI
cd /d %~dp0
if exist .venv\Scripts\activate.bat (
  call .venv\Scripts\activate.bat
)
uvicorn server:app --host 0.0.0.0 --port 8000
