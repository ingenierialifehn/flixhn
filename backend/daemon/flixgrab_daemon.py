"""
FlixGrab OTT Background Daemon - Main Service
Servicio en segundo plano que vigila cuentas de Netflix y plataformas OTT,
encola automáticamente estrenos y descargas, y las procesa como FlixGrab hacia el servidor.
"""

import os
import sys
import time
import signal
import os
import sys
import time
import signal
import logging
import argparse
import subprocess
import ctypes
from datetime import datetime
from pathlib import Path

# Cargar configuración interna
from config import (
    PID_FILE,
    LOG_FILE,
    POLL_INTERVAL_SECONDS,
    SUPPORTED_PROVIDERS
)
from db import (
    get_connected_accounts,
    get_pending_tasks,
    get_connection
)
from downloader import FlixGrabDownloader
from ott_scraper import sync_auto_downloads_for_account

# Configuración de Logging profesional
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [%(name)s] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger("FlixGrabDaemon")

running = True


def signal_handler(signum, frame):
    """Manejo de señales de detención para cierre ordenado."""
    global running
    logger.info("Recibida señal de detención. Cerrando FlixGrab Daemon de forma segura...")
    running = False


signal.signal(signal.SIGINT, signal_handler)
signal.signal(signal.SIGTERM, signal_handler)


def is_pid_active(pid: int) -> bool:
    """Comprueba si un proceso sigue activo usando la API nativa del SO."""
    if pid <= 0:
        return False

    if sys.platform == "win32":
        try:
            PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
            SYNCHRONIZE = 0x00100000
            handle = ctypes.windll.kernel32.OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION | SYNCHRONIZE, False, pid)
            if handle:
                exit_code = ctypes.c_ulong()
                ctypes.windll.kernel32.GetExitCodeProcess(handle, ctypes.byref(exit_code))
                ctypes.windll.kernel32.CloseHandle(handle)
                return exit_code.value == 259  # STILL_ACTIVE
            return False
        except Exception:
            return False
    else:
        try:
            os.kill(pid, 0)
            return True
        except (OSError, ProcessLookupError):
            return False


def is_daemon_running() -> tuple[bool, int]:
    """Comprueba si el proceso daemon está activo a partir del archivo PID."""
    if not PID_FILE.exists():
        return False, 0

    try:
        pid = int(PID_FILE.read_text().strip())
        if is_pid_active(pid):
            return True, pid
    except Exception:
        pass

    # Si el PID ya no existe, limpiar archivo huérfano
    try:
        PID_FILE.unlink(missing_ok=True)
    except Exception:
        pass

    return False, 0


def write_pid_file():
    """Registra el PID del proceso actual."""
    PID_FILE.write_text(str(os.getpid()))


def remove_pid_file():
    """Elimina el archivo PID al terminar."""
    try:
        PID_FILE.unlink(missing_ok=True)
    except Exception:
        pass


def run_daemon_loop(run_once: bool = False):
    """
    Bucle principal de ejecución del servicio en segundo plano:
    1. Revisa cuentas conectadas (Netflix, Disney, etc.) y ejecuta autodescarga si está activa.
    2. Procesa la cola de tareas pendientes en orden de prioridad.
    3. Descarga carátulas, realiza streaming de video y valida archivos.
    4. Indexa el contenido y limpia caché para reflejo instantáneo en /browse.
    """
    global running
    logger.info("=======================================================")
    logger.info("  FlixGrab OTT Background Daemon - INICIADO EXITOSAMENTE")
    logger.info(f"  PID: {os.getpid()} | Intervalo de sondeo: {POLL_INTERVAL_SECONDS}s")
    logger.info(f"  Ruta de Logs: {LOG_FILE}")
    logger.info("=======================================================")

    write_pid_file()

    try:
        last_account_check = 0.0

        while running:
            now = time.time()

            # 1. Monitoreo de cuentas OTT conectadas cada 30 segundos
            if (now - last_account_check) >= 30.0:
                try:
                    accounts = get_connected_accounts()
                    for acc in accounts:
                        if acc.get("auto_download"):
                            queued = sync_auto_downloads_for_account(acc)
                            if queued > 0:
                                logger.info(f"[{acc['provider']}] {queued} nuevos estrenos encolados para descarga automática.")
                    last_account_check = now
                except Exception as e:
                    logger.warning(f"Error verificando cuentas OTT: {e}")

            # 2. Obtener tareas pendientes
            try:
                tasks = get_pending_tasks()
                if tasks:
                    logger.info(f"Encontradas {len(tasks)} tareas de descarga activas/pendientes.")
                    for task in tasks:
                        if not running:
                            break

                        downloader = FlixGrabDownloader(task)
                        downloader.execute()
            except Exception as e:
                logger.error(f"Error procesando cola de descargas: {e}")

            if run_once:
                logger.info("Ejecución única finalizada (run_once).")
                break

            # 3. Dormir intervalo con verificación reactiva de salida
            for _ in range(int(POLL_INTERVAL_SECONDS * 10)):
                if not running:
                    break
                time.sleep(0.1)

    finally:
        remove_pid_file()
        logger.info("FlixGrab OTT Background Daemon DETENIDO.")


def start_daemon_background():
    """Lanza el daemon como proceso en segundo plano desacoplado en Windows o Linux."""
    active, pid = is_daemon_running()
    if active:
        print(f"[!] FlixGrab Daemon ya se encuentra en ejecución con PID {pid}.")
        return

    script_path = Path(__file__).resolve()
    python_exe = sys.executable

    if sys.platform == "win32":
        # En Windows, usar CREATE_NO_WINDOW (0x08000000) o start /B para desacoplamiento completo
        creation_flags = 0x08000000 | 0x00000200 # CREATE_NO_WINDOW | CREATE_NEW_PROCESS_GROUP
        proc = subprocess.Popen(
            [python_exe, str(script_path), "run"],
            creationflags=creation_flags,
            close_fds=True
        )
        print(f"[+] FlixGrab Daemon iniciado en segundo plano con PID {proc.pid}.")
    else:
        # En Linux / macOS
        proc = subprocess.Popen(
            [python_exe, str(script_path), "run"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True
        )
        print(f"[+] FlixGrab Daemon iniciado en segundo plano con PID {proc.pid}.")


def stop_daemon():
    """Detiene el proceso del daemon en ejecución."""
    active, pid = is_daemon_running()
    if not active:
        print("[-] FlixGrab Daemon no está en ejecución.")
        return

    try:
        print(f"[*] Deteniendo FlixGrab Daemon (PID {pid})...")
        if sys.platform == "win32":
            subprocess.run(["taskkill", "/F", "/PID", str(pid)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        else:
            os.kill(pid, signal.SIGTERM)
            time.sleep(1)
            if is_pid_active(pid):
                os.kill(pid, signal.SIGKILL)
        print("[+] FlixGrab Daemon detenido correctamente.")
    except Exception as e:
        print(f"[-] Error deteniendo daemon: {e}")
    finally:
        remove_pid_file()


def print_status():
    """Muestra el estado detallado del servicio en consola o para consumo de API."""
    active, pid = is_daemon_running()
    status_str = "ACTIVO (En segundo plano)" if active else "DETENIDO"

    accounts = []
    pending_tasks = []
    completed_count = 0
    try:
        accounts = get_connected_accounts()
        pending_tasks = get_pending_tasks()
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*) FROM ott_download_tasks WHERE status = 'completed'")
        completed_count = cur.fetchone()[0]
        cur.close()
        conn.close()
    except Exception:
        pass

    print("--------------------------------------------------")
    print("  FlixGrab OTT Background Daemon - Estado Actual")
    print("--------------------------------------------------")
    print(f"  Estado del Servicio: {status_str}")
    if active:
        print(f"  PID:                 {pid}")
    print(f"  Cuentas Conectadas:  {len(accounts)} {[a['provider'] for a in accounts]}")
    print(f"  Descargas en Cola:   {len(pending_tasks)}")
    print(f"  Títulos Completados: {completed_count}")
    print(f"  Archivo de Logs:     {LOG_FILE}")
    print("--------------------------------------------------")


def main():
    parser = argparse.ArgumentParser(description="FlixGrab OTT Background Daemon Manager")
    parser.add_argument("action", choices=["run", "start", "stop", "status", "restart", "run-once"], help="Acción a realizar")
    args = parser.parse_args()

    if args.action == "run":
        run_daemon_loop(run_once=False)
    elif args.action == "run-once":
        run_daemon_loop(run_once=True)
    elif args.action == "start":
        start_daemon_background()
    elif args.action == "stop":
        stop_daemon()
    elif args.action == "restart":
        stop_daemon()
        time.sleep(1)
        start_daemon_background()
    elif args.action == "status":
        print_status()


if __name__ == "__main__":
    main()
