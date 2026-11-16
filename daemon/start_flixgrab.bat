@echo off
echo ==========================================
echo   Iniciando FlixGrab Engine (FlixHN Daemon)
echo ==========================================
set PYTHON_EXE=%~dp0..\.venv\Scripts\python.exe
if not exist "%PYTHON_EXE%" set PYTHON_EXE=python
"%PYTHON_EXE%" "%~dp0flixgrab.py"
