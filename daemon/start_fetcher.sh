#!/bin/bash
# Lanzador de stream_fetcher.py para servidor FlixHN (Linux)

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR"

LOG_FILE="$DIR/stream_fetcher.log"
PID_FILE="$DIR/stream_fetcher.pid"

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

case "$1" in
  start)
    if is_running; then
      echo "stream_fetcher ya está ejecutándose con PID $(cat "$PID_FILE")."
      exit 0
    fi

    rm -f "$PID_FILE"
    echo "Iniciando stream_fetcher en segundo plano (puerto 5005)..."
    nohup python3 stream_fetcher.py serve --host 0.0.0.0 --port 5005 > "$LOG_FILE" 2>&1 &
    PID=$!
    echo "$PID" > "$PID_FILE"

    sleep 1
    if kill -0 "$PID" 2>/dev/null; then
      echo "Iniciado exitosamente con PID $PID. Logs en: $LOG_FILE"
    else
      echo "ERROR: stream_fetcher terminó inmediatamente tras iniciar. Últimas líneas de $LOG_FILE:"
      echo "----------------------------------------"
      tail -n 25 "$LOG_FILE" 2>/dev/null || true
      echo "----------------------------------------"
      rm -f "$PID_FILE"
      exit 1
    fi
    ;;

  stop)
    if [ -f "$PID_FILE" ]; then
      PID=$(cat "$PID_FILE" 2>/dev/null || true)
      if [ -n "$PID" ]; then
        echo "Deteniendo stream_fetcher (PID $PID)..."
        kill "$PID" 2>/dev/null || true
      fi
      rm -f "$PID_FILE"
    fi
    pkill -f "stream_fetcher.py serve" 2>/dev/null || true
    echo "stream_fetcher detenido."
    ;;

  status)
    if is_running; then
      echo "stream_fetcher está CORRIENDO (PID $(cat "$PID_FILE"))."
    else
      echo "stream_fetcher está DETENIDO."
    fi
    ;;

  restart)
    "$0" stop
    sleep 1
    "$0" start
    ;;

  *)
    echo "Uso: $0 {start|stop|restart|status}"
    exit 1
    ;;
esac
