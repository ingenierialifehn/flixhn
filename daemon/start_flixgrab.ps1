$ErrorActionPreference = "Stop"
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  Iniciando FlixGrab Engine (FlixHN Daemon) " -ForegroundColor Yellow
Write-Host "==========================================" -ForegroundColor Cyan

$PythonExe = Join-Path $PSScriptRoot "..\.venv\Scripts\python.exe"
if (-not (Test-Path $PythonExe)) {
    $PythonExe = "python"
}

$ScriptPath = Join-Path $PSScriptRoot "flixgrab.py"
& $PythonExe $ScriptPath
