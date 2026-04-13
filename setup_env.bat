@echo off
setlocal enabledelayedexpansion
set "VENVDIR=%~dp0.venv"

where python >nul 2>&1
if errorlevel 1 (
  echo Python no esta en PATH. Instala primero Python.
  exit /b 1
)

if not exist "%VENVDIR%" (
  echo Creando entorno virtual en .venv...
  python -m venv "%VENVDIR%"
) else (
  echo Ya existe .venv; se reutilizara.
)

if not exist "%VENVDIR%\Scripts\python.exe" (
  echo No se encontro python.exe dentro de .venv. El entorno puede estar corrupto.
  exit /b 1
)

"%VENVDIR%\Scripts\python.exe" -m pip install --upgrade pip wheel

if exist "%~dp0requirements.txt" (
  echo Instalando dependencias desde requirements.txt...
  "%VENVDIR%\Scripts\python.exe" -m pip install -r "%~dp0requirements.txt"
) else (
  echo requirements.txt no existe; no se instalaran dependencias.
)
