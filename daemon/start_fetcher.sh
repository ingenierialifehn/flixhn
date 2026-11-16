#!/usr/bin/env bash
# Lanzador de stream_fetcher.py para servidor FlixHN (Linux)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR"

LOG_FILE="$DIR/stream_fetcher.log"
PID_FILE="$DIR/stream_fetcher.pid"

case "$1" in
  start)
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
      echo "stream_fetcher ya está ejecutándose con PID $(cat "$PID_FILE")."
      exit 0
    fi
    echo "Iniciando stream_fetcher en segundo plano (puerto 5005)..."
    nohup python3 stream_fetcher.py serve --host 0.0.0.0 --port 5005 > "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    echo "Iniciado con PID $(cat "$PID_FILE"). Logs en: $LOG_FILE"
    ;;
  stop)
    if [ -f "$PID_FILE" ]; then
      PID=$(cat "$PID_FILE")
      echo "Deteniendo stream_fetcher (PID $PID)..."
      kill $PID 2>/dev/null || true
      rm -f "$PID_FILE"
      echo "Detenido."
    else
      echo "PID file no encontrado. Matando procesos huérfanos..."
      pkill -f "stream_fetcher.py serve" || true
    fi
    ;;
  status)
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
      echo "stream_fetcher está CORRIENDO (PID $(cat "$PID_FILE"))."
    else
      echo "stream_fetcher está DETENIDO."
    fi
    ;;
  restart)
    $0 stop
    sleep 1
    $0 start
    ;;
  *)
    echo "Uso: $0 {start|stop|restart|status}"
    exit 1
    ;;
esac
