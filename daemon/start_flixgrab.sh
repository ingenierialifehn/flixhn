#!/usr/bin/env bash
echo "=========================================="
echo "  Iniciando FlixGrab Engine (FlixHN Daemon)"
echo "=========================================="
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_BIN="${SCRIPT_DIR}/../.venv/bin/python"
if [ ! -f "$PYTHON_BIN" ]; then
    PYTHON_BIN="python3"
fi
exec "$PYTHON_BIN" "${SCRIPT_DIR}/flixgrab.py"
