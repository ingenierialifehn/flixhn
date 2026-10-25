"""
FlixGrab OTT Background Daemon - Configuration Module
Configuración central del servicio en segundo plano de descarga e ingesta OTT.
"""

import os
from pathlib import Path

# Rutas base del proyecto FlixHN
DAEMON_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = DAEMON_DIR.parent
BACKEND_DIR = PROJECT_ROOT / "backend"
STORAGE_DIR = BACKEND_DIR / "storage"
MEDIA_DIR = STORAGE_DIR / "media"
POSTERS_DIR = STORAGE_DIR / "app" / "public" / "posters"
LOGS_DIR = STORAGE_DIR / "logs"

# Asegurar directorios esenciales
for directory in [MEDIA_DIR, POSTERS_DIR, LOGS_DIR]:
    directory.mkdir(parents=True, exist_ok=True)

# Archivo PID y Logs del Daemon
PID_FILE = DAEMON_DIR / "flixgrab_daemon.pid"
LOG_FILE = LOGS_DIR / "flixgrab_daemon.log"

# Conexión MySQL de FlixHN
DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USERNAME", "flixhn_user")
DB_PASSWORD = os.getenv("DB_PASSWORD", "flixhn_secret")
DB_NAME = os.getenv("DB_DATABASE", "flixhn")

# API Local de FlixHN
API_BASE_URL = os.getenv("FLIXHN_API_URL", "http://127.0.0.1:80/api")

# Parámetros del motor de descarga
POLL_INTERVAL_SECONDS = 3.0       # Frecuencia de verificación de tareas
CHUNK_SIZE_BYTES = 128 * 1024     # 128 KB por bloque para cálculo fluido de velocidad
MIN_FILE_SIZE_BYTES = 1048576     # Mínimo 1 MB requerido para validación física
MAX_CONCURRENT_DOWNLOADS = 2      # Descargas simultáneas
DEFAULT_QUALITY = "1080p"         # Calidad estándar

# Proveedores OTT soportados
SUPPORTED_PROVIDERS = {
    "netflix": {
        "name": "Netflix",
        "brand": "Netflix Original & Worldwide Releases",
        "color": "#E50914",
        "default_folder": "netflix",
        "default_quality": "1080p"
    },
    "disney": {
        "name": "Disney+",
        "brand": "Disney, Pixar, Marvel, Star Wars & National Geographic",
        "color": "#0063E5",
        "default_folder": "disney",
        "default_quality": "4K HDR"
    },
    "hbo_max": {
        "name": "Max (HBO)",
        "brand": "Warner Bros, HBO Originals & DC",
        "color": "#5822b4",
        "default_folder": "hbo_max",
        "default_quality": "1080p"
    },
    "amazon_prime": {
        "name": "Amazon Prime Video",
        "brand": "Amazon MGM Studios & Prime Originals",
        "color": "#00A8E1",
        "default_folder": "amazon_prime",
        "default_quality": "1080p"
    },
    "apple_tv": {
        "name": "Apple TV+",
        "brand": "Apple Original Films & Series",
        "color": "#A1A1AA",
        "default_folder": "apple_tv",
        "default_quality": "4K HDR"
    },
    "paramount": {
        "name": "Paramount+",
        "brand": "Paramount Pictures, CBS, Showtime & MTV",
        "color": "#0064FF",
        "default_folder": "paramount",
        "default_quality": "1080p"
    }
}
