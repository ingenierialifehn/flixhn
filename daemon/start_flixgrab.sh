#!/bin/bash
# ==============================================================================
# Lanzador de FlixGrab Engine (FlixHN Daemon) para Servidor Linux
# ==============================================================================

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
PROJECT_ROOT="$(cd "$DIR/.." >/dev/null 2>&1 && pwd)"
cd "$DIR"

LOG_FILE="$DIR/flixgrab.log"
PID_FILE="$DIR/flixgrab.pid"

# Selección del intérprete Python
if [ -f "$PROJECT_ROOT/.venv/bin/python" ]; then
    PYTHON_BIN="$PROJECT_ROOT/.venv/bin/python"
elif [ -f "$DIR/.venv/bin/python" ]; then
    PYTHON_BIN="$DIR/.venv/bin/python"
elif command -v python3 &>/dev/null; then
    PYTHON_BIN="python3"
else
    PYTHON_BIN="python"
fi

is_running() {
    if [ -f "$PID_FILE" ]; then
        local pid
        pid=$(cat "$PID_FILE" 2>/dev/null || true)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

start_daemon() {
    if is_running; then
        echo "FlixGrab ya está ejecutándose con PID $(cat "$PID_FILE")."
        return 0
    fi

    # Intentar instalar dependencias en segundo plano si no están presentes
    if ! "$PYTHON_BIN" -c "import aiohttp, dotenv" >/dev/null 2>&1; then
        echo "Verificando e instalando dependencias de FlixGrab..."
        "$PYTHON_BIN" -m pip install -r "$DIR/requirements.txt" >/dev/null 2>&1 || \
        "$PYTHON_BIN" -m pip install --user -r "$DIR/requirements.txt" >/dev/null 2>&1 || \
        "$PYTHON_BIN" -m pip install --break-system-packages -r "$DIR/requirements.txt" >/dev/null 2>&1 || true
    fi

    rm -f "$PID_FILE"
    echo "Iniciando FlixGrab Engine en segundo plano (puerto 8001)..."
    nohup "$PYTHON_BIN" flixgrab.py > "$LOG_FILE" 2>&1 &
    PID=$!
    echo "$PID" > "$PID_FILE"

    sleep 1
    if kill -0 "$PID" 2>/dev/null; then
        echo "FlixGrab iniciado exitosamente con PID $PID."
        echo "Logs en: $LOG_FILE"
    else
        echo "ERROR: FlixGrab terminó inmediatamente tras iniciar. Últimas líneas de $LOG_FILE:"
        echo "--------------------------------------------------"
        tail -n 25 "$LOG_FILE" 2>/dev/null || true
        echo "--------------------------------------------------"
        rm -f "$PID_FILE"
        return 1
    fi
}

stop_daemon() {
    if [ -f "$PID_FILE" ]; then
        local PID
        PID=$(cat "$PID_FILE" 2>/dev/null || true)
        if [ -n "$PID" ]; then
            echo "Deteniendo FlixGrab (PID $PID)..."
            kill -TERM "$PID" 2>/dev/null || true
            sleep 2
            if kill -0 "$PID" 2>/dev/null; then
                echo "Forzando detención (SIGKILL)..."
                kill -9 "$PID" 2>/dev/null || true
            fi
        fi
        rm -f "$PID_FILE"
        echo "FlixGrab detenido."
    else
        echo "No se encontró PID_FILE de FlixGrab."
    fi
    pkill -f "flixgrab.py" 2>/dev/null || true
}

status_daemon() {
    if is_running; then
        echo "FlixGrab está activo y funcionando (PID: $(cat "$PID_FILE"))."
    else
        echo "FlixGrab NO está en ejecución."
    fi
}

case "$1" in
    start)
        start_daemon
        ;;
    stop)
        stop_daemon
        ;;
    restart)
        stop_daemon
        sleep 1
        start_daemon
        ;;
    status)
        status_daemon
        ;;
    *)
        echo "=========================================="
        echo "  Iniciando FlixGrab Engine en primer plano"
        echo "=========================================="
        echo "Intérprete: $PYTHON_BIN"
        echo "Para modo segundo plano use: $0 start"
        exec "$PYTHON_BIN" flixgrab.py
        ;;
esac
