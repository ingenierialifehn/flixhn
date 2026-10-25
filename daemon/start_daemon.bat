@echo off
REM =========================================================================
REM  FlixGrab OTT Background Daemon - Lanzador en Segundo Plano (Windows)
REM =========================================================================

cd /d "%~dp0.."
echo [*] Iniciando FlixGrab OTT Daemon en segundo plano...
python daemon/flixgrab_daemon.py start
timeout /t 2 >nul
python daemon/flixgrab_daemon.py status
pause
