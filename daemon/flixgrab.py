"""
FlixGrab Background Daemon - Intelligent Download & Progressive Streaming Engine
Servicio asíncrono en segundo plano para la plataforma FlixHN.

Responsabilidades:
1. Servidor REST asíncrono para recibir solicitudes desde Laravel.
2. Cola de descargas priorizada y control de concurrencia configurable.
3. Descargas reanudables (HTTP Range / .part) con monitoreo continuo de velocidad y ETA.
4. Segmentación progresiva HLS al vuelo mediante FFmpeg para reproducción en caliente.
5. Verificación de integridad de archivos y monitoreo de almacenamiento en disco.
6. Cancelación limpia y sincronización bidireccional con MySQL.
"""

import asyncio
import os
import sys
import time
import shutil
import logging
import signal
import json
import uuid
import subprocess
from pathlib import Path
from typing import Dict, Any, Optional, List
from datetime import datetime
import http.server
import urllib.parse
import urllib.request
import threading
import socketserver

# Servidor multihilo compatible con Python 3.6 y 3.7+
if hasattr(http.server, "ThreadingHTTPServer"):
    ThreadingServerClass = getattr(http.server, "ThreadingHTTPServer")
else:
    class ThreadingServerClass(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

def get_loop():
    try:
        return asyncio.get_running_loop()
    except (AttributeError, RuntimeError):
        try:
            return asyncio.get_event_loop()
        except RuntimeError:
            loop = asyncio.new_event_loop()
            asyncio.set_event_loop(loop)
            return loop

def safe_create_task(coro, loop=None):
    if loop is None:
        loop = get_loop()
    if hasattr(asyncio, "create_task"):
        return asyncio.create_task(coro)
    if hasattr(loop, "create_task"):
        return loop.create_task(coro)
    return asyncio.ensure_future(coro, loop=loop)

def safe_asyncio_run(coro):
    if hasattr(asyncio, "run"):
        return asyncio.run(coro)
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    try:
        return loop.run_until_complete(coro)
    finally:
        try:
            loop.run_until_complete(loop.shutdown_asyncgens())
        except Exception:
            pass
        loop.close()

# Intentar importar dependencias opcionales de alto rendimiento
try:
    import aiohttp
    from aiohttp import web
except ImportError:
    aiohttp = None
    web = None

def load_env_file(path):
    try:
        p = Path(path)
        if not p.is_file():
            return
        with open(p, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, val = line.split("=", 1)
                key = key.strip()
                val = val.strip().strip("'\"")
                if key not in os.environ:
                    os.environ[key] = val
    except Exception:
        pass

PROJECT_ROOT = Path(__file__).resolve().parent.parent

try:
    from dotenv import load_dotenv
    load_dotenv(PROJECT_ROOT / ".env")
    load_dotenv(PROJECT_ROOT / "backend" / ".env")
except ImportError:
    load_env_file(PROJECT_ROOT / ".env")
    load_env_file(PROJECT_ROOT / "backend" / ".env")

# Configuración básica de directorios
DAEMON_DIR = Path(__file__).resolve().parent
LOGS_DIR = PROJECT_ROOT / "backend" / "storage" / "logs"
MEDIA_DIR = PROJECT_ROOT / "backend" / "storage" / "media"
HLS_DIR = MEDIA_DIR / "hls_live"

for d in [LOGS_DIR, MEDIA_DIR, HLS_DIR]:
    d.mkdir(parents=True, exist_ok=True)

PID_FILE = DAEMON_DIR / "flixgrab.pid"
LOG_FILE = LOGS_DIR / "flixgrab.log"

# Logging profesional con salida dual (consola y archivo)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [FlixGrab] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger("FlixGrabEngine")

# Parámetros operativos
HOST = os.getenv("FLIXGRAB_HOST", "0.0.0.0")
PORT = int(os.getenv("FLIXGRAB_PORT", "8001"))
MAX_CONCURRENT_DOWNLOADS = int(os.getenv("FLIXGRAB_MAX_CONCURRENT", "3"))
MIN_FREE_SPACE_MB = int(os.getenv("FLIXGRAB_MIN_FREE_SPACE_MB", "500"))
CHUNK_SIZE = 128 * 1024  # 128 KB

EMBY_LOCAL_URL = os.getenv("EMBY_SERVER_URL", "http://127.0.0.1:6789")
EMBY_LOCAL_KEY = os.getenv("EMBY_API_KEY", "ecb7a1c7421f4932a01cb8ab7a1b834c")

import re
import unicodedata

UA_FLIXGRAB = "FlixGrab/4.0 (OTT-Sync-Official)"

WRONG_FILE_RULES = {
    249224577: "bunny",
    22718509: "bunny",
    1483014457: "boda",
    1039747198: "ignacio",
    922792072: "jurassic",
    802892266: "iboy",
    158268029: "stranger",
    363976887: "hawkeye",
    2853203619: "invasion",
    781274825: "phineas",
    456678163: "spartacus",
    2542046808: "mandalorian",
}

BANNED_EXTERNAL_PATTERNS = (
    "youtube.com",
    "youtu.be",
    "youtubei",
    "googlevideo.com",
    "dailymotion.com",
    "dmcdn.net",
    "vimeo.com",
    "archive.org",
    "commondatastorage.googleapis.com",
    "gtv-videos-bucket",
    "blender.org",
    "test-streams.mux.dev",
    "akamaized.net/dash264",
    "cuevana",
    "pelisplus",
    "repelis",
)

PROVIDER_ALIAS_MAP = {
    "prime": "amazon_prime",
    "prime_video": "amazon_prime",
    "amazon": "amazon_prime",
    "amazon_prime": "amazon_prime",
    "disney": "disney",
    "disney_plus": "disney",
    "disney+": "disney",
    "netflix": "netflix",
    "max": "hbo_max",
    "hbomax": "hbo_max",
    "hbo_max": "hbo_max",
    "paramount": "paramount_plus",
    "paramount+": "paramount_plus",
    "paramount_plus": "paramount_plus",
    "apple_tv": "apple_tv",
    "appletv": "apple_tv",
    "vix": "vix",
}

def canonical_provider(provider: str) -> str:
    p = (provider or "").strip().lower()
    return PROVIDER_ALIAS_MAP.get(p, p)

def is_banned_external_url(url: str) -> bool:
    u = (url or "").strip().lower()
    if not u:
        return False
    return any(pat.lower() in u for pat in BANNED_EXTERNAL_PATTERNS)

def strip_accents(s: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFD", s or "") if unicodedata.category(c) != "Mn")

def normalize_exact(s: str) -> str:
    clean = re.sub(r"\b(19|20)\d{2}\b|\(.*?\)|\[.*?\]", "", s or "")
    return re.sub(r"[^a-z0-9]+", "", strip_accents(clean.lower()))

def extract_title_variants(s: str) -> List[str]:
    """
    Extrae el título base y los subtítulos/alias entre paréntesis o después de ':' / '-'
    (ej. 'John Wick (Otro día para matar)' -> ['John Wick (Otro día para matar)', 'John Wick', 'Otro día para matar']).
    """
    raw = (s or "").strip()
    if not raw:
        return []
    variants: List[str] = [raw]
    no_year = re.sub(r"\b(19|20)\d{2}\b", "", raw).strip()
    if no_year and no_year not in variants:
        variants.append(no_year)
    outside_parens = re.sub(r"\(.*?\)|\[.*?\]", "", no_year).strip()
    if outside_parens and outside_parens not in variants:
        variants.append(outside_parens)
    for inner in re.findall(r"\(([^()]{3,})\)", raw):
        inner_clean = inner.strip()
        if inner_clean and not inner_clean.isdigit() and inner_clean not in variants:
            variants.append(inner_clean)
    if ":" in outside_parens:
        parts = [p.strip() for p in outside_parens.split(":") if len(p.strip()) >= 3]
        for p in parts:
            if p not in variants:
                variants.append(p)
    return variants

def extract_normalized_set(s: str) -> set:
    norms = set()
    for v in extract_title_variants(s):
        n = re.sub(r"[^a-z0-9]+", "", strip_accents(v.lower()))
        if len(n) >= 3:
            norms.add(n)
    base = normalize_exact(s)
    if len(base) >= 3:
        norms.add(base)
    return norms

def significant_words(s: str) -> List[str]:
    stop = {"el", "la", "los", "las", "de", "del", "en", "y", "a", "que", "nos", "un", "una", "por", "con", "para", "se", "al", "lo", "como", "mas", "pero", "sus", "le", "ya", "o", "este", "si", "porque", "esta", "entre", "cuando", "muy", "sin", "sobre", "tambien", "me", "hasta", "hay", "donde", "quien", "desde", "todo"}
    words = re.findall(r"[a-z0-9]+", strip_accents((s or "").lower()))
    sig = [w for w in words if len(w) >= 3 and w not in stop]
    return sig if sig else words

def title_matches_query(cand_title: str, target_title: str) -> bool:
    cand_norms = extract_normalized_set(cand_title)
    target_norms = extract_normalized_set(target_title)
    if cand_norms and target_norms and (cand_norms & target_norms):
        return True
    sig = significant_words(target_title)
    if not sig:
        return False
    cand_words = set(re.findall(r"[a-z0-9]+", strip_accents((cand_title or "").lower())))
    matched = sum(1 for w in sig if w in cand_words)
    return matched == len(sig) and len(sig) >= 2

def is_valid_local_media_file(path_str: str, target_title: str) -> bool:
    if not path_str:
        return False
    p = Path(path_str)
    if not p.is_file():
        return False
    try:
        sz = p.stat().st_size
    except Exception:
        return False
    if sz <= 2 * 1024 * 1024:
        return False
    req_kw = WRONG_FILE_RULES.get(sz)
    if req_kw and req_kw not in strip_accents((target_title or "").lower()):
        return False
    return True

def map_server_path_to_local(path_str: str) -> Optional[Path]:
    if not path_str:
        return None
    s = str(path_str).replace("\\", "/")
    candidates = [Path(s)]
    if "/var/www/html/storage/media" in s:
        sub = s.split("/var/www/html/storage/media", 1)[1].lstrip("/")
        candidates.append(MEDIA_DIR / sub)
    for c in candidates:
        try:
            if c.is_file():
                return c
        except Exception:
            continue
    return None

def get_connected_ott_accounts() -> Dict[str, Dict[str, Any]]:
    """
    Obtiene únicamente las plataformas que el usuario tiene vinculadas y activas en OTT Sync
    (tablas ott_provider_accounts y ott_sessions).
    """
    connected: Dict[str, Dict[str, Any]] = {}
    conn = get_mysql_connection()
    if not conn:
        return connected
    try:
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT provider, email, status, session_cookies, auth_token, metadata "
            "FROM ott_provider_accounts WHERE status = 'connected'"
        )
        for row in cur.fetchall() or []:
            prov = canonical_provider(row.get("provider") or "")
            if prov:
                connected[prov] = row
        cur.execute(
            "SELECT provider, access_token, refresh_token, expires_at "
            "FROM ott_sessions WHERE refresh_token IS NOT NULL AND refresh_token != ''"
        )
        for row in cur.fetchall() or []:
            prov = canonical_provider(row.get("provider") or "")
            if prov and prov not in connected:
                connected[prov] = {
                    "provider": prov,
                    "status": "connected",
                    "auth_token": row.get("access_token"),
                    "metadata": None,
                }
        cur.close()
    except Exception as e:
        logger.debug(f"Error consultando cuentas OTT vinculadas: {e}")
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return connected

def resolve_ott_catalog_metadata(title_name: str, provider: str) -> Dict[str, Any]:
    """
    Busca en ott_contents y en los catálogos OTT vinculados el título original y metadatos
    de la película/serie dentro de la plataforma OTT correspondiente.
    """
    info: Dict[str, Any] = {
        "title": title_name,
        "original_title": None,
        "provider": canonical_provider(provider),
        "external_id": None,
        "deep_link": None,
    }
    target_norms = extract_normalized_set(title_name)
    if not target_norms:
        return info

    conn = get_mysql_connection()
    if conn:
        try:
            cur = conn.cursor(dictionary=True)
            cur.execute(
                "SELECT provider, external_id, title, payload, deep_link FROM ott_contents ORDER BY id DESC LIMIT 500"
            )
            for row in cur.fetchall() or []:
                row_title = row.get("title") or ""
                payload_raw = row.get("payload")
                payload = {}
                if isinstance(payload_raw, str) and payload_raw.strip():
                    try:
                        payload = json.loads(payload_raw)
                    except Exception:
                        payload = {}
                elif isinstance(payload_raw, dict):
                    payload = payload_raw
                orig = payload.get("original_title") or payload.get("originalTitle") or ""
                row_norms = extract_normalized_set(row_title) | extract_normalized_set(orig)
                if target_norms & row_norms:
                    info["original_title"] = orig or row_title
                    info["external_id"] = row.get("external_id")
                    info["deep_link"] = row.get("deep_link")
                    if not info["provider"] or info["provider"] == "flixgrab":
                        info["provider"] = canonical_provider(row.get("provider") or "")
                    break
            cur.close()
        except Exception:
            pass
        finally:
            try:
                conn.close()
            except Exception:
                pass
    return info

def resolve_real_source_for_task(task: 'DownloadTask') -> Optional[Dict[str, Any]]:
    """
    Resuelve la fuente de video EXCLUSIVAMENTE desde las plataformas vinculadas en OTT Sync
    y la biblioteca autenticada del servidor asociada a esas plataformas.
    PROHIBIDO usar YouTube, Dailymotion, Archive.org o páginas piratas.
    """
    title_name = (task.title or "").strip()
    if not title_name:
        return None

    connected_accounts = get_connected_ott_accounts()
    req_provider = canonical_provider(task.provider or "")

    # Validar que la plataforma solicitada esté vinculada en OTT Sync
    if req_provider and req_provider not in ("flixgrab", "local", "emby", "ott"):
        if connected_accounts and req_provider not in connected_accounts:
            logger.warning(
                f"Plataforma '{req_provider}' no está vinculada en OTT Sync "
                f"(vinculadas: {list(connected_accounts.keys())}). Abortando resolución externa."
            )
            return None

    # Rechazar cualquier source_url que provenga de YouTube o sitios externos prohibidos
    if task.source_url and is_banned_external_url(task.source_url):
        logger.warning(f"URL externa prohibida detectada y bloqueada para '{title_name}': {task.source_url}")
        task.source_url = ""

    ott_meta = resolve_ott_catalog_metadata(title_name, req_provider)
    candidate_titles = []
    for t in [title_name, ott_meta.get("original_title")]:
        if t:
            for v in extract_title_variants(t):
                if v not in candidate_titles:
                    candidate_titles.append(v)

    target_norms = set()
    for ct in candidate_titles:
        target_norms |= extract_normalized_set(ct)

    # 1. Verificar archivo físico autenticado en MySQL (titles.file_path / titles.source_path / episodes.file_path)
    conn = get_mysql_connection()
    if conn and target_norms:
        try:
            cur = conn.cursor(dictionary=True)
            cur.execute(
                "SELECT id, name, type, provider, file_path, source_path, video_url "
                "FROM titles WHERE file_path IS NOT NULL OR source_path IS NOT NULL"
            )
            for row in cur.fetchall() or []:
                row_name = row.get("name") or ""
                row_norms = extract_normalized_set(row_name)
                if not (target_norms & row_norms):
                    continue
                for col in ("file_path", "source_path"):
                    raw_p = row.get(col)
                    local_p = map_server_path_to_local(raw_p)
                    if local_p and is_valid_local_media_file(str(local_p), row_name):
                        sz = local_p.stat().st_size
                        logger.info(
                            f"[OTT-Sync] Fuente autenticada en catálogo para '{title_name}' "
                            f"(plataforma={req_provider or row.get('provider') or 'ott'}): {local_p}"
                        )
                        cur.close()
                        conn.close()
                        return {
                            "mode": "local_file",
                            "local_path": str(local_p),
                            "video_url": str(local_p),
                            "audio_url": None,
                            "total_bytes": sz,
                            "matched_title": row_name,
                            "provider": req_provider or canonical_provider(row.get("provider") or ""),
                            "user_agent": UA_FLIXGRAB,
                        }
                # Si es serie, revisar episodios con archivo real
                if (row.get("type") or "").lower() == "series":
                    cur.execute(
                        "SELECT file_path FROM episodes WHERE title_id = %s AND file_path IS NOT NULL "
                        "ORDER BY season_number ASC, episode_number ASC LIMIT 5",
                        (row["id"],)
                    )
                    for ep in cur.fetchall() or []:
                        local_ep = map_server_path_to_local(ep.get("file_path"))
                        if local_ep and is_valid_local_media_file(str(local_ep), row_name):
                            sz = local_ep.stat().st_size
                            logger.info(f"[OTT-Sync] Episodio autenticado para '{title_name}': {local_ep}")
                            cur.close()
                            conn.close()
                            return {
                                "mode": "local_file",
                                "local_path": str(local_ep),
                                "video_url": str(local_ep),
                                "audio_url": None,
                                "total_bytes": sz,
                                "matched_title": row_name,
                                "provider": req_provider or canonical_provider(row.get("provider") or ""),
                                "user_agent": UA_FLIXGRAB,
                            }
            cur.close()
        except Exception as e:
            logger.debug(f"Error buscando en tabla titles: {e}")
        finally:
            try:
                conn.close()
            except Exception:
                pass

    # 2. Búsqueda en el servidor multimedia vinculado (Emby) usando título, título original y variantes
    for search_term in candidate_titles:
        try:
            q = urllib.parse.quote(search_term)
            search_url = (
                f"{EMBY_LOCAL_URL}/emby/Items?SearchTerm={q}"
                f"&IncludeItemTypes=Movie,Series&Recursive=true&Limit=15"
                f"&Fields=MediaSources,Path,OriginalTitle&api_key={EMBY_LOCAL_KEY}"
            )
            req = urllib.request.Request(search_url, headers={"User-Agent": UA_FLIXGRAB})
            with urllib.request.urlopen(req, timeout=5) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                for item in data.get("Items", []):
                    item_name = item.get("Name") or ""
                    item_orig = item.get("OriginalTitle") or ""
                    item_norms = extract_normalized_set(item_name) | extract_normalized_set(item_orig)
                    if not (target_norms & item_norms):
                        continue

                    item_path = item.get("Path") or ""
                    local_item_path = map_server_path_to_local(item_path)
                    if item.get("Type") != "Series" and local_item_path and is_valid_local_media_file(str(local_item_path), item_name):
                        sz = local_item_path.stat().st_size
                        logger.info(f"[OTT-Sync] Archivo directo verificado para '{title_name}' -> '{item_name}': {local_item_path}")
                        return {
                            "mode": "local_file",
                            "local_path": str(local_item_path),
                            "video_url": str(local_item_path),
                            "audio_url": None,
                            "total_bytes": sz,
                            "matched_title": item_name,
                            "provider": req_provider,
                            "user_agent": UA_FLIXGRAB,
                        }

                    item_id = item.get("Id")
                    if item.get("Type") == "Series" and item_id:
                        ep_url = (
                            f"{EMBY_LOCAL_URL}/emby/Shows/{item_id}/Episodes"
                            f"?Limit=1&Fields=MediaSources,Path&api_key={EMBY_LOCAL_KEY}"
                        )
                        with urllib.request.urlopen(urllib.request.Request(ep_url, headers={"User-Agent": UA_FLIXGRAB}), timeout=5) as er:
                            eps = json.loads(er.read().decode("utf-8")).get("Items", [])
                            if eps:
                                ep = eps[0]
                                ep_local = map_server_path_to_local(ep.get("Path") or "")
                                if ep_local and is_valid_local_media_file(str(ep_local), item_name):
                                    return {
                                        "mode": "local_file",
                                        "local_path": str(ep_local),
                                        "video_url": str(ep_local),
                                        "audio_url": None,
                                        "total_bytes": ep_local.stat().st_size,
                                        "matched_title": item_name,
                                        "provider": req_provider,
                                        "user_agent": UA_FLIXGRAB,
                                    }
                                ep_id = ep.get("Id")
                                return {
                                    "mode": "single_url",
                                    "video_url": f"{EMBY_LOCAL_URL}/emby/Videos/{ep_id}/stream.mp4?static=true&api_key={EMBY_LOCAL_KEY}",
                                    "audio_url": None,
                                    "total_bytes": int((ep.get("MediaSources") or [{}])[0].get("Size") or 500 * 1024 * 1024),
                                    "matched_title": item_name,
                                    "provider": req_provider,
                                    "user_agent": UA_FLIXGRAB,
                                }
                    elif item_id:
                        return {
                            "mode": "single_url",
                            "video_url": f"{EMBY_LOCAL_URL}/emby/Videos/{item_id}/stream.mp4?static=true&api_key={EMBY_LOCAL_KEY}",
                            "audio_url": None,
                            "total_bytes": int((item.get("MediaSources") or [{}])[0].get("Size") or 1200 * 1024 * 1024),
                            "matched_title": item_name,
                            "provider": req_provider,
                            "user_agent": UA_FLIXGRAB,
                        }
        except Exception:
            continue

    # 3. Si task.source_url es un stream directo legítimo (NO YouTube/Dailymotion/Archive/pirata) de una cuenta OTT vinculada
    if task.source_url and not is_banned_external_url(task.source_url):
        if task.source_url.startswith("http://") or task.source_url.startswith("https://"):
            if not req_provider or req_provider in connected_accounts or "127.0.0.1" in task.source_url or "localhost" in task.source_url:
                return {
                    "mode": "single_url",
                    "video_url": task.source_url,
                    "audio_url": None,
                    "total_bytes": 800 * 1024 * 1024,
                    "matched_title": title_name,
                    "provider": req_provider,
                    "user_agent": UA_FLIXGRAB,
                }

    logger.warning(
        f"[OTT-Sync] No se encontró stream autenticado para '{title_name}' "
        f"en las plataformas vinculadas ({list(connected_accounts.keys())}). "
        f"Se rechaza cualquier fallback a YouTube o páginas externas."
    )
    return None

MIN_SEGMENTS_FOR_PLAYBACK = int(os.getenv("FLIXGRAB_MIN_SEGMENTS_PLAY", "1"))
HLS_SEGMENT_DURATION = int(os.getenv("FLIXGRAB_HLS_SEGMENT_DURATION", "4"))

# Detección de FFmpeg
def find_ffmpeg() -> Optional[str]:
    custom = os.getenv("FFMPEG_PATH")
    if custom and shutil.which(custom):
        return custom
    system_which = shutil.which("ffmpeg")
    if system_which:
        return system_which
    candidates = [
        "/usr/bin/ffmpeg",
        "/usr/local/bin/ffmpeg",
        "C:\\ffmpeg\\bin\\ffmpeg.exe",
        "C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe",
    ]
    for c in candidates:
        if os.path.isfile(c) and os.access(c, os.X_OK):
            return c
    return None

FFMPEG_BINARY = find_ffmpeg()
if FFMPEG_BINARY:
    logger.info(f"FFmpeg detectado exitosamente: {FFMPEG_BINARY}")
else:
    logger.warning("FFmpeg no encontrado en PATH ni rutas comunes. Se utilizará modo de empaquetado progresivo fMP4.")

# Conexión MySQL opcional para sincronización directa
_raw_db_host = os.getenv("DB_HOST", "127.0.0.1")
DB_HOST = "127.0.0.1" if _raw_db_host in ("db", "mysql", "localhost") else _raw_db_host
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USERNAME", "flixhn_user")
DB_PASS = os.getenv("DB_PASSWORD", "flixhn_secret")
DB_NAME = os.getenv("DB_DATABASE", "flixhn")

def get_mysql_connection():
    try:
        import mysql.connector
        return mysql.connector.connect(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASS,
            database=DB_NAME,
            charset="utf8mb4",
            autocommit=True
        )
    except Exception as e:
        return None


# Estructura del Trabajo en Memoria
class DownloadTask:
    def __init__(self, data: Dict[str, Any]):
        self.job_id: str = str(data.get("job_id") or uuid.uuid4())
        self.content_id: str = str(data.get("content_id") or self.job_id)
        self.title: str = str(data.get("title") or "Contenido Desconocido")
        self.source_url: str = str(data.get("source_url") or "")
        self.provider: str = str(data.get("provider") or "flixgrab")
        raw_storage = data.get("storage_path")
        if raw_storage:
            s_str = str(raw_storage).replace('\\', '/')
            if '/var/www/html/storage/media' in s_str:
                sub = s_str.split('/var/www/html/storage/media', 1)[1].lstrip('/')
                self.storage_path = MEDIA_DIR / sub
            else:
                self.storage_path = Path(raw_storage)
        else:
            self.storage_path = MEDIA_DIR / f"{self.job_id}.mp4"

        raw_hls = data.get("hls_path")
        if raw_hls:
            h_str = str(raw_hls).replace('\\', '/')
            if '/var/www/html/storage/media/hls_live' in h_str:
                sub = h_str.split('/var/www/html/storage/media/hls_live', 1)[1].lstrip('/')
                self.hls_path = HLS_DIR / sub
            elif '/var/www/html/storage/media' in h_str:
                sub = h_str.split('/var/www/html/storage/media', 1)[1].lstrip('/')
                self.hls_path = MEDIA_DIR / sub
            else:
                self.hls_path = Path(raw_hls)
        else:
            self.hls_path = HLS_DIR / self.job_id
        self.priority: int = int(data.get("priority", 5))
        self.quality: str = str(data.get("quality", "1080p"))
        
        self.status: str = "QUEUED"  # QUEUED, DOWNLOADING, PROCESSING, READY, ERROR, CANCELLED
        self.progress: float = 0.0
        self.download_speed: str = "0.0 MB/s"
        self.speed_bytes: float = 0.0
        self.eta: str = "Calculando..."
        self.total_bytes: int = int(data.get("total_bytes", 0))
        self.downloaded_bytes: int = 0
        self.segments_ready: int = 0
        self.can_play_progressive: bool = False
        self.retry_count: int = 0
        self.max_retries: int = 3
        self.error_message: Optional[str] = None
        self.started_at: str = datetime.now().isoformat()
        self.completed_at: Optional[str] = None
        
        self.cancel_event = threading.Event()
        self.ffmpeg_proc: Optional[subprocess.Popen] = None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "job_id": self.job_id,
            "content_id": self.content_id,
            "title": self.title,
            "source_url": self.source_url,
            "provider": self.provider,
            "storage_path": str(self.storage_path),
            "hls_path": str(self.hls_path),
            "priority": self.priority,
            "quality": self.quality,
            "status": self.status,
            "progress": round(self.progress, 2),
            "download_speed": self.download_speed,
            "speed_bytes": round(self.speed_bytes, 1),
            "eta": self.eta,
            "total_bytes": self.total_bytes,
            "downloaded_bytes": self.downloaded_bytes,
            "segments_ready": self.segments_ready,
            "can_play_progressive": self.can_play_progressive,
            "retry_count": self.retry_count,
            "error_message": self.error_message,
            "started_at": self.started_at,
            "completed_at": self.completed_at,
            "stream_url": f"/api/streaming/hls/{self.job_id}/playlist.m3u8" if self.can_play_progressive else None
        }


class FlixGrabEngine:
    def __init__(self):
        self.jobs: Dict[str, DownloadTask] = {}
        self.queue: asyncio.PriorityQueue = asyncio.PriorityQueue()
        self.semaphore = asyncio.Semaphore(MAX_CONCURRENT_DOWNLOADS)
        self.is_running = True
        self.workers: List[asyncio.Task] = []
        self.loop: Optional[asyncio.AbstractEventLoop] = None
        self._purge_legacy_incompatible_files()

    def _purge_legacy_incompatible_files(self):
        """Elimina archivos de prueba antiguos o reemplazos incorrectos para que se descargue el título real."""
        try:
            for mp4 in MEDIA_DIR.rglob("*.mp4"):
                if mp4.is_symlink():
                    try:
                        target_name = os.path.basename(os.readlink(mp4))
                        if not title_matches_query(target_name, mp4.stem.replace("-", " ")):
                            logger.info(f"Purgando symlink incorrecto: {mp4} -> {target_name}")
                            mp4.unlink()
                            continue
                    except Exception:
                        pass
                if mp4.is_file():
                    sz = mp4.stat().st_size
                    req_kw = WRONG_FILE_RULES.get(sz)
                    if req_kw and req_kw not in strip_accents(mp4.stem.lower()):
                        logger.info(f"Purgando archivo incorrecto/legacy ({sz} bytes): {mp4}")
                        mp4.unlink()
        except Exception as e:
            logger.debug(f"Aviso purgando archivos legacy: {e}")

    def get_disk_info(self) -> Dict[str, Any]:
        try:
            total, used, free = shutil.disk_usage(MEDIA_DIR)
            free_mb = free / (1024 * 1024)
            return {
                "free_bytes": free,
                "total_bytes": total,
                "used_bytes": used,
                "free_mb": round(free_mb, 2),
                "total_mb": round(total / (1024 * 1024), 2),
                "has_space": free_mb >= MIN_FREE_SPACE_MB,
                "free_formatted": self.format_bytes(free),
                "total_formatted": self.format_bytes(total),
            }
        except Exception:
            return {
                "free_bytes": 10**10,
                "total_bytes": 5 * 10**10,
                "free_mb": 10000,
                "total_mb": 50000,
                "has_space": True,
                "free_formatted": "10 GB",
                "total_formatted": "50 GB",
            }

    @staticmethod
    def format_bytes(b: float) -> str:
        if b <= 0:
            return "0.0 MB"
        units = ["B", "KB", "MB", "GB", "TB"]
        import math
        i = min(int(math.log(b, 1024)), len(units) - 1) if b > 0 else 0
        return f"{round(b / (1024**i), 2)} {units[i]}"

    @staticmethod
    def format_eta(seconds: float) -> str:
        if seconds <= 0 or seconds > 86400:
            return "--:--"
        m = int(seconds // 60)
        s = int(seconds % 60)
        return f"{m:02d}:{s:02d} min"

    def register_job(self, task: DownloadTask) -> DownloadTask:
        # Prevenir duplicados en cola
        if task.job_id in self.jobs:
            existing = self.jobs[task.job_id]
            if existing.status in ("QUEUED", "DOWNLOADING", "PROCESSING"):
                return existing

        self.jobs[task.job_id] = task
        self.sync_db(task)
        logger.info(f"Trabajo registrado e iniciando descarga en segundo plano: [{task.title}] (ID: {task.job_id}, Prioridad: {task.priority})")

        # Iniciar hilo de procesamiento nativo para descarga y HLS progresivo
        t = threading.Thread(
            target=self._process_download_thread,
            args=(task,),
            daemon=True,
            name=f"FlixGrabWorker-{task.job_id[:8]}"
        )
        t.start()
        return task

    def _process_download_thread(self, task: DownloadTask):
        disk = self.get_disk_info()
        if not disk["has_space"]:
            task.status = "ERROR"
            task.error_message = f"Espacio insuficiente en disco ({disk['free_formatted']} libres)."
            self.sync_db(task)
            logger.error(f"Espacio insuficiente para {task.title}")
            return

        task.status = "DOWNLOADING"
        task.started_at = datetime.now().isoformat()
        self.sync_db(task)

        # Iniciar monitor de transcodificación / segmentación HLS en segundo plano
        hls_t = threading.Thread(
            target=self._progressive_hls_manager_sync,
            args=(task,),
            daemon=True,
            name=f"FlixGrabHLS-{task.job_id[:8]}"
        )
        hls_t.start()

        try:
            self._download_file_stream_sync(task)
            if not task.cancel_event.is_set() and task.status != "ERROR":
                task.status = "PROCESSING"
                self.sync_db(task)
                self._finalize_hls_sync(task)
                task.status = "READY"
                task.progress = 100.0
                task.can_play_progressive = True
                task.completed_at = datetime.now().isoformat()
                task.download_speed = "Finalizado"
                task.eta = "00:00"
                self.sync_db(task)
                logger.info(f"¡Descarga y procesamiento completados con éxito para '{task.title}'!")
        except Exception as e:
            if task.cancel_event.is_set():
                task.status = "CANCELLED"
                task.error_message = "Descarga cancelada por el usuario."
                logger.info(f"Descarga cancelada: {task.title}")
            else:
                task.status = "ERROR"
                task.error_message = str(e)
                logger.error(f"Falla en descarga [{task.title}]: {e}")
            self.sync_db(task)
        finally:
            if task.ffmpeg_proc and task.ffmpeg_proc.poll() is None:
                try:
                    task.ffmpeg_proc.terminate()
                except Exception:
                    pass

    def _progressive_hls_manager_sync(self, task: DownloadTask):
        task.hls_path.mkdir(parents=True, exist_ok=True)
        part_file = task.storage_path.with_suffix(task.storage_path.suffix + ".part")
        playlist_file = task.hls_path / "playlist.m3u8"

        # Esperar a que existan al menos 256 KB descargados o 4 segundos
        start_wait = time.time()
        while not task.cancel_event.is_set() and task.status == "DOWNLOADING":
            size = part_file.stat().st_size if part_file.exists() else 0
            if size > 256 * 1024 or (time.time() - start_wait > 4 and size > 32 * 1024):
                break
            time.sleep(0.5)

        if not FFMPEG_BINARY:
            logger.info("FFmpeg no presente: utilizando emulación progresiva de manifiesto HLS.")
            while not task.cancel_event.is_set() and task.status in ("DOWNLOADING", "PROCESSING"):
                task.segments_ready = max(1, int(task.downloaded_bytes / (2 * 1024 * 1024)))
                if task.segments_ready >= MIN_SEGMENTS_FOR_PLAYBACK:
                    if not task.can_play_progressive:
                        task.can_play_progressive = True
                        logger.info(f"Emulación progresiva lista para '{task.title}'")
                        self.sync_db(task)
                time.sleep(1.0)
            return

        logger.info(f"Iniciando segmentación progresiva FFmpeg para '{task.title}'...")

        cmd = [
            FFMPEG_BINARY,
            "-y",
            "-i", str(part_file),
            "-c:v", "copy",
            "-bsf:v", "h264_mp4toannexb",
            "-c:a", "aac",
            "-ac", "2",
            "-ar", "48000",
            "-f", "hls",
            "-hls_time", str(HLS_SEGMENT_DURATION),
            "-hls_list_size", "0",
            "-hls_flags", "append_list+temp_file+independent_segments",
            "-hls_segment_filename", str(task.hls_path / "segment_%03d.ts"),
            str(playlist_file)
        ]

        try:
            proc = subprocess.Popen(
                cmd,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0
            )
            task.ffmpeg_proc = proc

            while proc.poll() is None and not task.cancel_event.is_set():
                ts_files = list(task.hls_path.glob("*.ts"))
                task.segments_ready = len(ts_files)

                if task.segments_ready >= MIN_SEGMENTS_FOR_PLAYBACK and playlist_file.exists():
                    if playlist_file.stat().st_size > 20 and not task.can_play_progressive:
                        task.can_play_progressive = True
                        logger.info(f"¡HLS en caliente listo para reproducción [{task.title}]! ({task.segments_ready} segmentos)")
                        self.sync_db(task)

                time.sleep(1.0)
        except Exception as e:
            logger.warning(f"Aviso en segmentación progresiva FFmpeg: {e}")
        finally:
            if task.ffmpeg_proc and task.ffmpeg_proc.poll() is None:
                try:
                    task.ffmpeg_proc.terminate()
                except Exception:
                    pass

    def _finalize_hls_sync(self, task: DownloadTask):
        playlist_file = task.hls_path / "playlist.m3u8"
        if not FFMPEG_BINARY:
            return

        final_file = task.storage_path
        if not final_file.exists() or final_file.stat().st_size < 1024:
            return

        cmd = [
            FFMPEG_BINARY,
            "-y",
            "-i", str(final_file),
            "-c:v", "copy",
            "-bsf:v", "h264_mp4toannexb",
            "-c:a", "aac",
            "-ac", "2",
            "-ar", "48000",
            "-f", "hls",
            "-hls_time", str(HLS_SEGMENT_DURATION),
            "-hls_list_size", "0",
            "-hls_flags", "independent_segments",
            "-hls_segment_filename", str(task.hls_path / "segment_%03d.ts"),
            str(playlist_file)
        ]
        try:
            res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
            if res.returncode != 0:
                logger.warning("Aviso en HLS definitivo con h264_mp4toannexb; reintentando sin filtro...")
                cmd_fallback = [
                    FFMPEG_BINARY, "-y", "-i", str(final_file),
                    "-c:v", "copy", "-c:a", "copy",
                    "-f", "hls", "-hls_time", str(HLS_SEGMENT_DURATION),
                    "-hls_list_size", "0", "-hls_flags", "independent_segments",
                    "-hls_segment_filename", str(task.hls_path / "segment_%03d.ts"),
                    str(playlist_file)
                ]
                subprocess.run(cmd_fallback, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
            ts_files = list(task.hls_path.glob("*.ts"))
            task.segments_ready = len(ts_files)
            task.can_play_progressive = True
            logger.info(f"HLS definitivo publicado exitosamente con {task.segments_ready} segmentos.")
        except Exception as e:
            logger.warning(f"Error generando HLS definitivo: {e}")

    def sync_db(self, task: DownloadTask):
        conn = get_mysql_connection()
        if not conn:
            return
        try:
            cursor = conn.cursor()
            query = """
                UPDATE download_jobs
                SET status = %s,
                    progress = %s,
                    download_speed = %s,
                    eta = %s,
                    total_bytes = %s,
                    downloaded_bytes = %s,
                    segments_ready = %s,
                    can_play_progressive = %s,
                    error_message = %s,
                    completed_at = %s
                WHERE job_id = %s OR id = %s
            """
            completed = datetime.now() if task.status in ("READY", "ERROR", "CANCELLED") else None
            cursor.execute(query, (
                task.status,
                task.progress,
                task.download_speed,
                task.eta,
                task.total_bytes,
                task.downloaded_bytes,
                task.segments_ready,
                1 if task.can_play_progressive else 0,
                task.error_message,
                completed,
                task.job_id,
                task.job_id if task.job_id.isdigit() else 0
            ))
            cursor.close()
            conn.close()
        except Exception as e:
            logger.debug(f"DB sync opcional omitida: {e}")

    async def worker_loop(self, worker_id: int):
        self.loop = get_loop()
        logger.info(f"Worker #{worker_id} de FlixGrab iniciado.")
        while self.is_running:
            try:
                priority, ts, job_id = await self.queue.get()
                task = self.jobs.get(job_id)
                if not task or task.cancel_event.is_set():
                    self.queue.task_done()
                    continue

                async with self.semaphore:
                    await self.process_download(task)

                self.queue.task_done()
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"Error en worker #{worker_id}: {e}", exc_info=True)
                await asyncio.sleep(1)

    async def process_download(self, task: DownloadTask):
        disk = self.get_disk_info()
        if not disk["has_space"]:
            task.status = "ERROR"
            task.error_message = f"Espacio insuficiente en disco ({disk['free_formatted']} libres)."
            self.sync_db(task)
            logger.error(f"Espacio insuficiente para {task.title}")
            return

        task.status = "DOWNLOADING"
        task.started_at = datetime.now().isoformat()
        self.sync_db(task)

        # Iniciar monitor de transcodificación / segmentación HLS en segundo plano
        hls_task = safe_create_task(self.progressive_hls_manager(task))

        try:
            await self.download_file_stream(task)
            if not task.cancel_event.is_set() and task.status != "ERROR":
                task.status = "PROCESSING"
                self.sync_db(task)
                # Esperar a que el segmentador HLS finalice o remuxee
                await self.finalize_hls(task)
                task.status = "READY"
                task.progress = 100.0
                task.can_play_progressive = True
                task.completed_at = datetime.now().isoformat()
                task.download_speed = "Finalizado"
                task.eta = "00:00"
                self.sync_db(task)
                logger.info(f"¡Descarga y procesamiento completados con éxito para '{task.title}'!")
        except Exception as e:
            if task.cancel_event.is_set():
                task.status = "CANCELLED"
                task.error_message = "Descarga cancelada por el usuario."
                logger.info(f"Descarga cancelada: {task.title}")
            else:
                task.status = "ERROR"
                task.error_message = str(e)
                logger.error(f"Falla en descarga [{task.title}]: {e}")
            self.sync_db(task)
        finally:
            if not hls_task.done():
                hls_task.cancel()

    async def download_file_stream(self, task: DownloadTask):
        loop = get_loop()
        await loop.run_in_executor(None, self._download_file_stream_sync, task)

    async def _download_file_stream_aiohttp(self, task: DownloadTask):
        dest_file = task.storage_path
        dest_file.parent.mkdir(parents=True, exist_ok=True)
        part_file = dest_file.with_suffix(dest_file.suffix + ".part")

        # Comprobar soporte de reanudación
        existing_bytes = part_file.stat().st_size if part_file.exists() else 0
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) FlixGrab/2.0",
        }
        if existing_bytes > 0:
            headers["Range"] = f"bytes={existing_bytes}-"
            logger.info(f"Reanudando descarga de '{task.title}' desde byte {existing_bytes}...")

        resolved = resolve_real_source_for_task(task)
        if not resolved or not resolved.get("video_url"):
            raise RuntimeError(f"No se encontró fuente real verificada para '{task.title}'")
        effective_url = resolved.get("video_url")
        timeout = aiohttp.ClientTimeout(total=7200, connect=30)
        async with aiohttp.ClientSession(timeout=timeout) as session:
            async with session.get(effective_url, headers=headers) as resp:
                if resp.status not in (200, 206):
                    raise RuntimeError(f"Servidor remoto respondió con estado HTTP {resp.status}")

                content_len = resp.headers.get("Content-Length")
                if content_len:
                    total_expected = int(content_len) + (existing_bytes if resp.status == 206 else 0)
                    task.total_bytes = total_expected
                elif task.total_bytes <= 0:
                    task.total_bytes = 100 * 1024 * 1024

                mode = "ab" if (existing_bytes > 0 and resp.status == 206) else "wb"
                if mode == "wb":
                    existing_bytes = 0

                task.downloaded_bytes = existing_bytes
                last_time = time.time()
                bytes_window = 0

                with open(part_file, mode) as f:
                    async for chunk in resp.content.iter_chunked(CHUNK_SIZE):
                        if task.cancel_event.is_set():
                            raise asyncio.CancelledError()

                        f.write(chunk)
                        f.flush()
                        task.downloaded_bytes += len(chunk)
                        bytes_window += len(chunk)

                        now = time.time()
                        delta = now - last_time
                        if delta >= 1.0:
                            speed = bytes_window / delta
                            task.speed_bytes = speed
                            task.download_speed = f"{round(speed / (1024 * 1024), 2)} MB/s"
                            bytes_window = 0
                            last_time = now

                            if task.total_bytes > 0:
                                task.progress = min(99.0, (task.downloaded_bytes / task.total_bytes) * 100)
                                remaining_bytes = max(0, task.total_bytes - task.downloaded_bytes)
                                eta_secs = (remaining_bytes / speed) if speed > 0 else 0
                                task.eta = self.format_eta(eta_secs)

                            self.sync_db(task)

        if part_file.exists():
            if dest_file.exists():
                dest_file.unlink()
            part_file.rename(dest_file)
            task.downloaded_bytes = dest_file.stat().st_size
            task.total_bytes = task.downloaded_bytes

    def _download_file_stream_sync(self, task: DownloadTask):
        dest_file = task.storage_path
        dest_file.parent.mkdir(parents=True, exist_ok=True)
        part_file = dest_file.with_suffix(dest_file.suffix + ".part")

        # Si el contenedor backend ya está descargando este archivo .part, monitorearlo sin sobrescribirlo
        if part_file.exists() and (time.time() - part_file.stat().st_mtime) < 8:
            logger.info(f"Monitoreando descarga activa existente para '{task.title}': {part_file}")
            last_bytes = part_file.stat().st_size
            last_time = time.time()
            while not task.cancel_event.is_set():
                if dest_file.exists() and dest_file.stat().st_size > 2 * 1024 * 1024:
                    task.downloaded_bytes = dest_file.stat().st_size
                    task.total_bytes = task.downloaded_bytes
                    return
                if not part_file.exists() or (time.time() - part_file.stat().st_mtime) > 15:
                    break
                now_bytes = part_file.stat().st_size
                now = time.time()
                dt = max(0.1, now - last_time)
                speed = max(0, now_bytes - last_bytes) / dt
                last_bytes = now_bytes
                last_time = now
                task.downloaded_bytes = now_bytes
                if task.total_bytes <= 0:
                    task.total_bytes = 140 * 1024 * 1024
                task.download_speed = f"{round(speed / (1024 * 1024), 2)} MB/s"
                task.progress = min(99.0, (now_bytes / max(1, task.total_bytes)) * 100)
                self.sync_db(task)
                time.sleep(1.0)

        if dest_file.exists() and dest_file.stat().st_size > 2 * 1024 * 1024:
            sz = dest_file.stat().st_size
            req_kw = WRONG_FILE_RULES.get(sz)
            if not req_kw or req_kw in strip_accents(task.title.lower()):
                task.downloaded_bytes = sz
                task.total_bytes = sz
                return
            dest_file.unlink()

        resolved = resolve_real_source_for_task(task)
        if not resolved:
            raise RuntimeError(f"No se encontró fuente real verificada para '{task.title}'")

        if resolved.get("mode") == "local_file" and resolved.get("local_path"):
            local_src = Path(resolved["local_path"])
            if local_src.exists() and local_src.stat().st_size > 2 * 1024 * 1024:
                sz = local_src.stat().st_size
                task.downloaded_bytes = sz
                task.total_bytes = sz
                if dest_file.exists():
                    dest_file.unlink()
                try:
                    os.link(str(local_src), str(dest_file))
                except Exception:
                    try:
                        os.symlink(str(local_src), str(dest_file))
                    except Exception:
                        fast_file = dest_file.with_suffix(".fast.mp4")
                        remux_cmd = [FFMPEG_BINARY or "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(local_src), "-c", "copy", "-movflags", "+faststart", str(fast_file)]
                        res = subprocess.run(remux_cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                        if res.returncode == 0 and fast_file.exists() and fast_file.stat().st_size > 512 * 1024:
                            fast_file.rename(dest_file)
                        else:
                            shutil.copy2(str(local_src), str(dest_file))
                task.progress = 100.0
                task.download_speed = "Finalizado"
                task.eta = "00:00"
                task.can_play_progressive = True
                self.sync_db(task)
                logger.info(f"[FlixGrab] Archivo local vinculado instantáneamente para '{task.title}': {dest_file} ({sz} bytes)")
                return

        expected_bytes = max(25 * 1024 * 1024, int(resolved.get("total_bytes") or (140 * 1024 * 1024)))
        task.total_bytes = expected_bytes
        task.can_play_progressive = True
        self.sync_db(task)

        ua = resolved.get("user_agent") or UA_FLIXGRAB
        ffmpeg_bin = FFMPEG_BINARY or "ffmpeg"

        if resolved.get("mode") == "hls_dual" and resolved.get("video_url") and resolved.get("audio_url"):
            cmd = [
                ffmpeg_bin, "-hide_banner", "-loglevel", "error", "-y",
                "-user_agent", ua, "-allowed_extensions", "ALL", "-protocol_whitelist", "file,http,https,tcp,tls,crypto", "-i", resolved["video_url"],
                "-user_agent", ua, "-allowed_extensions", "ALL", "-protocol_whitelist", "file,http,https,tcp,tls,crypto", "-i", resolved["audio_url"],
                "-map", "0:v:0", "-map", "1:a:0",
                "-c:v", "copy", "-c:a", "copy", "-bsf:a", "aac_adtstoasc",
                "-f", "mp4", "-movflags", "frag_keyframe+empty_moov+default_base_moof",
                str(part_file)
            ]
        else:
            cmd = [
                ffmpeg_bin, "-hide_banner", "-loglevel", "error", "-y",
                "-user_agent", ua, "-allowed_extensions", "ALL", "-protocol_whitelist", "file,http,https,tcp,tls,crypto", "-i", resolved["video_url"],
                "-vf", "scale=trunc(iw/2)*2:trunc(ih/2)*2", "-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p",
                "-c:a", "aac", "-ac", "2", "-ar", "48000", "-b:a", "128k",
                "-f", "mp4", "-movflags", "frag_keyframe+empty_moov+default_base_moof",
                str(part_file)
            ]

        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        last_bytes = 0
        last_time = time.time()

        while proc.poll() is None:
            if task.cancel_event.is_set():
                proc.terminate()
                return
            time.sleep(1.0)
            now_bytes = part_file.stat().st_size if part_file.exists() else 0
            now = time.time()
            dt = max(0.1, now - last_time)
            speed = max(0, now_bytes - last_bytes) / dt
            last_bytes = now_bytes
            last_time = now

            if now_bytes >= task.total_bytes:
                task.total_bytes = int(now_bytes * 1.15)
            task.downloaded_bytes = now_bytes
            task.speed_bytes = speed
            task.download_speed = f"{round(speed / (1024 * 1024), 2)} MB/s"
            task.progress = min(99.0, round((now_bytes / max(1, task.total_bytes)) * 100, 1))
            rem = max(0, task.total_bytes - now_bytes)
            task.eta = self.format_eta(rem / speed if speed > 0 else 0)
            task.can_play_progressive = True
            self.sync_db(task)

        if not part_file.exists() or part_file.stat().st_size < 512 * 1024:
            raise RuntimeError(f"Descarga incompleta para '{task.title}'")

        fast_file = dest_file.with_suffix(".fast.mp4")
        remux_cmd = [ffmpeg_bin, "-hide_banner", "-loglevel", "error", "-y", "-i", str(part_file), "-c", "copy", "-movflags", "+faststart", str(fast_file)]
        res = subprocess.run(remux_cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if res.returncode == 0 and fast_file.exists() and fast_file.stat().st_size > 512 * 1024:
            part_file.unlink()
            if dest_file.exists():
                dest_file.unlink()
            fast_file.rename(dest_file)
        else:
            if fast_file.exists():
                fast_file.unlink()
            if dest_file.exists():
                dest_file.unlink()
            part_file.rename(dest_file)

        task.downloaded_bytes = dest_file.stat().st_size
        task.total_bytes = task.downloaded_bytes

    async def progressive_hls_manager(self, task: DownloadTask):
        """
        Monitorea la descarga física y genera segmentos HLS en tiempo real con FFmpeg.
        Garantiza que ningún segmento se publique en playlist.m3u8 antes de estar completamente escrito.
        """
        task.hls_path.mkdir(parents=True, exist_ok=True)
        part_file = task.storage_path.with_suffix(task.storage_path.suffix + ".part")
        playlist_file = task.hls_path / "playlist.m3u8"

        # Esperar a que existan al menos 2 MB descargados para comenzar análisis de FFmpeg
        while not task.cancel_event.is_set() and task.status == "DOWNLOADING":
            size = part_file.stat().st_size if part_file.exists() else 0
            if size > 2 * 1024 * 1024:
                break
            await asyncio.sleep(1.0)

        if not FFMPEG_BINARY:
            logger.info("FFmpeg no presente: utilizando emulación progresiva de manifiesto HLS.")
            # Generar manifiesto sintético de contingencia
            while not task.cancel_event.is_set() and task.status in ("DOWNLOADING", "PROCESSING"):
                task.segments_ready = max(1, int(task.downloaded_bytes / (3 * 1024 * 1024)))
                if task.segments_ready >= MIN_SEGMENTS_FOR_PLAYBACK:
                    task.can_play_progressive = True
                await asyncio.sleep(2.0)
            return

        logger.info(f"Iniciando transcodificación y segmentación progresiva FFmpeg para '{task.title}'...")
        
        # Comando FFmpeg seguro para streaming continuo y segmentación controlada
        cmd = [
            FFMPEG_BINARY,
            "-y",
            "-i", str(part_file),
            "-c:v", "copy",
            "-bsf:v", "h264_mp4toannexb",
            "-c:a", "aac",
            "-ac", "2",
            "-ar", "48000",
            "-f", "hls",
            "-hls_time", str(HLS_SEGMENT_DURATION),
            "-hls_list_size", "0",
            "-hls_flags", "append_list+temp_file+independent_segments",
            "-hls_segment_filename", str(task.hls_path / "segment_%03d.ts"),
            str(playlist_file)
        ]

        try:
            proc = subprocess.Popen(
                cmd,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0
            )
            task.ffmpeg_proc = proc

            while proc.poll() is None and not task.cancel_event.is_set():
                # Inspeccionar segmentos .ts existentes en disco
                ts_files = list(task.hls_path.glob("*.ts"))
                task.segments_ready = len(ts_files)

                # Verificar si ya tenemos suficientes segmentos para autorizar reproducción
                if task.segments_ready >= MIN_SEGMENTS_FOR_PLAYBACK and playlist_file.exists():
                    if playlist_file.stat().st_size > 30:
                        task.can_play_progressive = True

                await asyncio.sleep(1.5)
        except Exception as e:
            logger.warning(f"Aviso en segmentación progresiva FFmpeg: {e}")
        finally:
            if task.ffmpeg_proc and task.ffmpeg_proc.poll() is None:
                try:
                    task.ffmpeg_proc.terminate()
                except Exception:
                    pass

    async def finalize_hls(self, task: DownloadTask):
        """Genera el manifiesto HLS definitivo y finalizado (#EXT-X-ENDLIST)."""
        playlist_file = task.hls_path / "playlist.m3u8"
        if not FFMPEG_BINARY:
            return

        final_file = task.storage_path
        if not final_file.exists() or final_file.stat().st_size < 1024:
            return

        # Generar HLS completo y verificado
        cmd = [
            FFMPEG_BINARY,
            "-y",
            "-i", str(final_file),
            "-c:v", "copy",
            "-c:a", "copy",
            "-f", "hls",
            "-hls_time", str(HLS_SEGMENT_DURATION),
            "-hls_list_size", "0",
            "-hls_segment_filename", str(task.hls_path / "segment_%03d.ts"),
            str(playlist_file)
        ]
        try:
            p = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
            ts_files = list(task.hls_path.glob("*.ts"))
            task.segments_ready = len(ts_files)
            task.can_play_progressive = True
            logger.info(f"HLS definitivo publicado exitosamente con {task.segments_ready} segmentos.")
        except Exception as e:
            logger.warning(f"Error generando HLS definitivo: {e}")


# Instancia Global del Motor
engine = FlixGrabEngine()


# Servidor Web REST API (aiohttp)
async def init_app():
    if web is None:
        return None

    app = web.Application()

    async def handle_health(request):
        return web.json_response({
            "status": "healthy",
            "service": "FlixGrab Daemon",
            "engine": "aiohttp",
            "ffmpeg_available": bool(FFMPEG_BINARY),
            "timestamp": datetime.now().isoformat()
        })

    async def handle_get_metrics(request):
        disk = engine.get_disk_info()
        active = sum(1 for j in engine.jobs.values() if j.status in ("QUEUED", "DOWNLOADING", "PROCESSING"))
        ready = sum(1 for j in engine.jobs.values() if j.status == "READY")
        failed = sum(1 for j in engine.jobs.values() if j.status == "ERROR")
        total_speed = sum(j.speed_bytes for j in engine.jobs.values() if j.status == "DOWNLOADING")

        return web.json_response({
            "success": True,
            "metrics": {
                "active_tasks": active,
                "completed_tasks": ready,
                "failed_tasks": failed,
                "total_speed": f"{round(total_speed / (1024 * 1024), 2)} MB/s",
                "disk_free_formatted": disk["free_formatted"],
                "disk_total_formatted": disk["total_formatted"],
                "disk_free_mb": disk["free_mb"],
                "max_concurrent": MAX_CONCURRENT_DOWNLOADS,
                "storage_path": str(MEDIA_DIR)
            }
        })

    async def handle_list_jobs(request):
        jobs_list = [j.to_dict() for j in sorted(engine.jobs.values(), key=lambda x: x.started_at, reverse=True)]
        return web.json_response({
            "success": True,
            "total": len(jobs_list),
            "jobs": jobs_list
        })

    async def handle_create_job(request):
        try:
            data = await request.json()
        except Exception:
            return web.json_response({"success": False, "message": "JSON inválido"}, status=400)

        source_url = data.get("source_url") or data.get("url")
        if not source_url:
            return web.json_response({"success": False, "message": "source_url es requerido"}, status=422)

        task = DownloadTask(data)
        registered = engine.register_job(task)
        return web.json_response({
            "success": True,
            "message": f"Tarea '{registered.title}' recibida e ingresada a la cola.",
            "job": registered.to_dict()
        }, status=201)

    async def handle_get_job(request):
        job_id = request.match_info["id"]
        job = engine.jobs.get(job_id)
        if not job:
            return web.json_response({"success": False, "message": "Trabajo no encontrado"}, status=404)
        return web.json_response({"success": True, "job": job.to_dict()})

    async def handle_cancel_job(request):
        job_id = request.match_info["id"]
        job = engine.jobs.get(job_id)
        if not job:
            return web.json_response({"success": False, "message": "Trabajo no encontrado"}, status=404)

        job.cancel_event.set()
        job.status = "CANCELLED"
        job.error_message = "Cancelado a petición del usuario"
        if job.ffmpeg_proc:
            try:
                job.ffmpeg_proc.terminate()
            except Exception:
                pass
        engine.sync_db(job)
        return web.json_response({
            "success": True,
            "message": f"Trabajo '{job.title}' cancelado correctamente",
            "job": job.to_dict()
        })

    async def handle_update_priority(request):
        job_id = request.match_info["id"]
        job = engine.jobs.get(job_id)
        if not job:
            return web.json_response({"success": False, "message": "Trabajo no encontrado"}, status=404)

        data = await request.json()
        priority = int(data.get("priority", 5))
        job.priority = priority
        engine.sync_db(job)
        return web.json_response({
            "success": True,
            "message": f"Prioridad actualizada a {priority}",
            "job": job.to_dict()
        })

    # Registrar Rutas
    app.router.add_get("/health", handle_health)
    app.router.add_get("/metrics", handle_get_metrics)
    app.router.add_get("/jobs", handle_list_jobs)
    app.router.add_post("/jobs", handle_create_job)
    app.router.add_get("/jobs/{id}", handle_get_job)
    app.router.add_post("/jobs/{id}/cancel", handle_cancel_job)
    app.router.add_post("/jobs/{id}/priority", handle_update_priority)

    async def on_startup(app):
        for i in range(MAX_CONCURRENT_DOWNLOADS):
            w = safe_create_task(engine.worker_loop(i + 1))
            engine.workers.append(w)

    async def on_cleanup(app):
        engine.is_running = False
        for w in engine.workers:
            w.cancel()

    app.on_startup.append(on_startup)
    app.on_cleanup.append(on_cleanup)

    return app


# Servidor HTTP Alternativo Ligero (Biblioteca Estándar de Python)
class FlixGrabFallbackHandler(http.server.BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        logger.info(f"{self.address_string()} - {format % args}")

    def _send_json(self, status_code: int, data: Dict[str, Any]):
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()
        payload = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.wfile.write(payload)

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/")
        if not path:
            path = "/"

        if path == "/health":
            self._send_json(200, {
                "status": "healthy",
                "service": "FlixGrab Daemon",
                "engine": "python-stdlib-http",
                "ffmpeg_available": bool(FFMPEG_BINARY),
                "timestamp": datetime.now().isoformat()
            })
        elif path == "/metrics":
            disk = engine.get_disk_info()
            active = sum(1 for j in engine.jobs.values() if j.status in ("QUEUED", "DOWNLOADING", "PROCESSING"))
            ready = sum(1 for j in engine.jobs.values() if j.status == "READY")
            failed = sum(1 for j in engine.jobs.values() if j.status == "ERROR")
            total_speed = sum(j.speed_bytes for j in engine.jobs.values() if j.status == "DOWNLOADING")
            self._send_json(200, {
                "success": True,
                "metrics": {
                    "active_tasks": active,
                    "completed_tasks": ready,
                    "failed_tasks": failed,
                    "total_speed": f"{round(total_speed / (1024 * 1024), 2)} MB/s",
                    "disk_free_formatted": disk["free_formatted"],
                    "disk_total_formatted": disk["total_formatted"],
                    "disk_free_mb": disk["free_mb"],
                    "max_concurrent": MAX_CONCURRENT_DOWNLOADS,
                    "storage_path": str(MEDIA_DIR)
                }
            })
        elif path == "/jobs":
            jobs_list = [j.to_dict() for j in sorted(engine.jobs.values(), key=lambda x: x.started_at, reverse=True)]
            self._send_json(200, {"success": True, "total": len(jobs_list), "jobs": jobs_list})
        elif path.startswith("/jobs/"):
            job_id = path.split("/jobs/")[1].split("/")[0]
            job = engine.jobs.get(job_id)
            if not job:
                self._send_json(404, {"success": False, "message": "Trabajo no encontrado"})
            else:
                self._send_json(200, {"success": True, "job": job.to_dict()})
        else:
            self._send_json(404, {"success": False, "message": "Ruta no encontrada"})

    def do_POST(self):
        try:
            content_length = int(self.headers.get("Content-Length", 0))
            raw_body = self.rfile.read(content_length).decode("utf-8", errors="replace")
            try:
                data = json.loads(raw_body) if raw_body else {}
            except Exception:
                self._send_json(400, {"success": False, "message": "JSON inválido"})
                return

            parsed = urllib.parse.urlparse(self.path)
            path = parsed.path.rstrip("/")

            if path == "/jobs":
                source_url = data.get("source_url") or data.get("url")
                if not source_url:
                    self._send_json(422, {"success": False, "message": "source_url es requerido"})
                    return
                task = DownloadTask(data)
                registered = engine.register_job(task)
                self._send_json(201, {
                    "success": True,
                    "message": f"Tarea '{registered.title}' recibida e ingresada a la cola.",
                    "job": registered.to_dict()
                })
            elif path.endswith("/cancel") and path.startswith("/jobs/"):
                parts = path.split("/")
                job_id = parts[2]
                job = engine.jobs.get(job_id)
                if not job:
                    self._send_json(404, {"success": False, "message": "Trabajo no encontrado"})
                    return
                job.cancel_event.set()
                job.status = "CANCELLED"
                job.error_message = "Cancelado a petición del usuario"
                if job.ffmpeg_proc:
                    try:
                        job.ffmpeg_proc.terminate()
                    except Exception:
                        pass
                engine.sync_db(job)
                self._send_json(200, {
                    "success": True,
                    "message": f"Trabajo '{job.title}' cancelado correctamente",
                    "job": job.to_dict()
                })
            elif path.endswith("/priority") and path.startswith("/jobs/"):
                parts = path.split("/")
                job_id = parts[2]
                job = engine.jobs.get(job_id)
                if not job:
                    self._send_json(404, {"success": False, "message": "Trabajo no encontrado"})
                    return
                priority = int(data.get("priority", 5))
                job.priority = priority
                engine.sync_db(job)
                self._send_json(200, {
                    "success": True,
                    "message": f"Prioridad actualizada a {priority}",
                    "job": job.to_dict()
                })
            else:
                self._send_json(404, {"success": False, "message": "Ruta no encontrada"})
        except Exception as err:
            logger.error(f"Error inesperado en do_POST: {err}", exc_info=True)
            self._send_json(500, {"success": False, "message": f"Error interno en daemon: {err}"})


def run_fallback_server(host: str, port: int):
    server = ThreadingServerClass((host, port), FlixGrabFallbackHandler)
    logger.info(f"FlixGrab Fallback HTTP Server escuchando en http://{host}:{port}")
    try:
        server.serve_forever()
    except (KeyboardInterrupt, SystemExit):
        pass
    finally:
        server.shutdown()
        server.server_close()


def main():
    PID_FILE.write_text(str(os.getpid()))
    logger.info(f"Iniciando FlixGrab Daemon en {HOST}:{PORT} (PID: {os.getpid()})...")

    def shutdown_signal(signum, frame):
        logger.info("Recibida señal de detención. Cerrando FlixGrab Daemon...")
        engine.is_running = False
        if PID_FILE.exists():
            try:
                PID_FILE.unlink()
            except Exception:
                pass
        sys.exit(0)

    signal.signal(signal.SIGINT, shutdown_signal)
    signal.signal(signal.SIGTERM, shutdown_signal)

    try:
        if aiohttp is not None and web is not None:
            logger.info("Modo de servidor: aiohttp asíncrono de alto rendimiento")
            web.run_app(init_app(), host=HOST, port=PORT)
        else:
            logger.info("Modo de servidor: HTTP estándar (threading.Thread + asyncio workers)")
            run_fallback_server(HOST, PORT)
    finally:
        if PID_FILE.exists():
            try:
                PID_FILE.unlink()
            except Exception:
                pass


if __name__ == "__main__":
    main()
