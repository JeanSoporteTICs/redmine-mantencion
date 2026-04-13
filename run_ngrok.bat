@echo off
setlocal enabledelayedexpansion

:: Puerto que quieres exponer (modifica aquí si hace falta)
set "PORT=8000"

:: Usa ngrok desde el PATH del sistema (igual que en CMD)
set "NGROK_BIN=ngrok"

echo Lanzando ngrok en el puerto %PORT%...
"%NGROK_BIN%" http %PORT%
