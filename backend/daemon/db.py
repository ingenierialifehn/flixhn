"""
FlixGrab OTT Background Daemon - Database Layer
Conexión directa y operaciones transaccionales con MySQL en FlixHN.
"""

import json
import logging
import mysql.connector
from mysql.connector import pooling
from typing import List, Dict, Any, Optional
from datetime import datetime

from config import (
    DB_HOST,
    DB_PORT,
    DB_USER,
    DB_PASSWORD,
    DB_NAME
)

logger = logging.getLogger("FlixGrabDaemon.DB")

# Pool de conexiones optimizado para subprocesos
_connection_pool: Optional[pooling.MySQLConnectionPool] = None


def get_db_pool() -> pooling.MySQLConnectionPool:
    global _connection_pool
    if _connection_pool is None:
        _connection_pool = pooling.MySQLConnectionPool(
            pool_name="flixgrab_pool",
            pool_size=5,
            pool_reset_session=True,
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            charset="utf8mb4",
            autocommit=True
        )
    return _connection_pool


def get_connection():
    try:
        pool = get_db_pool()
        return pool.get_connection()
    except Exception as e:
        logger.error(f"Error obteniendo conexión del pool MySQL: {e}")
        # Conexión directa de fallback
        return mysql.connector.connect(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            charset="utf8mb4",
            autocommit=True
        )


def get_connected_accounts() -> List[Dict[str, Any]]:
    """Obtiene todas las cuentas OTT actualmente conectadas con o sin auto_download."""
    conn = get_connection()
    cursor = conn.cursor(dictionary=True)
    try:
        query = """
            SELECT id, provider, account_name, credentials, status, 
                   auto_download, quality_profile, session_metadata, last_sync_at
            FROM ott_provider_accounts
            WHERE status = 'connected'
            ORDER BY id ASC
        """
        cursor.execute(query)
        accounts = cursor.fetchall()
        for acc in accounts:
            if isinstance(acc.get("session_metadata"), str):
                try:
                    acc["session_metadata"] = json.loads(acc["session_metadata"])
                except Exception:
                    acc["session_metadata"] = {}
        return accounts
    finally:
        cursor.close()
        conn.close()


def get_pending_tasks() -> List[Dict[str, Any]]:
    """
    Obtiene las tareas de descarga activas o en cola de ott_download_tasks y media_ingest_tasks.
    """
    conn = get_connection()
    cursor = conn.cursor(dictionary=True)
    try:
        query = """
            SELECT id, provider, ott_account_id, title, media_type, external_id,
                   quality, status, progress, download_speed, eta, total_bytes,
                   downloaded_bytes, file_path, metadata, media_item_id, title_id
            FROM ott_download_tasks
            WHERE status IN ('pending', 'downloading') AND progress < 100
            ORDER BY FIELD(status, 'downloading', 'pending'), id ASC
            LIMIT 10
        """
        cursor.execute(query)
        tasks = cursor.fetchall()

        for t in tasks:
            if isinstance(t.get("metadata"), str):
                try:
                    t["metadata"] = json.loads(t["metadata"])
                except Exception:
                    t["metadata"] = {}
            elif not t.get("metadata"):
                t["metadata"] = {}
        return tasks
    finally:
        cursor.close()
        conn.close()


def update_task_progress(
    task_id: int,
    progress: int,
    speed: str,
    eta: str,
    downloaded_bytes: int = 0,
    total_bytes: int = 0,
    file_path: Optional[str] = None
):
    """Actualiza reactivamente el progreso en tiempo real de la tarea OTT y su homóloga de ingesta."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # 1. Actualizar ott_download_tasks
        update_ott = """
            UPDATE ott_download_tasks
            SET status = 'downloading',
                progress = %s,
                download_speed = %s,
                eta = %s,
                downloaded_bytes = %s,
                total_bytes = CASE WHEN %s > 0 THEN %s ELSE total_bytes END,
                file_path = COALESCE(%s, file_path),
                started_at = COALESCE(started_at, NOW()),
                updated_at = NOW()
            WHERE id = %s
        """
        cursor.execute(update_ott, (
            progress, speed, eta, downloaded_bytes, total_bytes, total_bytes, file_path, task_id
        ))

        # 2. Obtener el título asociado para sincronizar media_ingest_tasks
        cursor.execute("SELECT title, file_path FROM ott_download_tasks WHERE id = %s", (task_id,))
        row = cursor.fetchone()
        if row:
            title_name, resolved_path = row[0], row[1]
            update_ingest = """
                UPDATE media_ingest_tasks
                SET status = 'downloading',
                    progress = %s,
                    download_speed = %s,
                    eta = %s,
                    output_file = COALESCE(%s, output_file),
                    started_at = COALESCE(started_at, NOW()),
                    updated_at = NOW()
                WHERE title = %s OR output_file = %s
            """
            cursor.execute(update_ingest, (
                progress, speed, eta, resolved_path, title_name, resolved_path
            ))
    except Exception as e:
        logger.warning(f"Error actualizando progreso de tarea #{task_id}: {e}")
    finally:
        cursor.close()
        conn.close()


def mark_task_completed(
    task_id: int,
    file_path: str,
    media_item_id: Optional[int] = None,
    title_id: Optional[int] = None
):
    """Marca la tarea como completada al 100% y asocia sus IDs de biblioteca."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # ott_download_tasks
        cursor.execute("""
            UPDATE ott_download_tasks
            SET status = 'completed',
                progress = 100,
                download_speed = '0.0 MB/s',
                eta = 'Completado',
                file_path = %s,
                media_item_id = COALESCE(%s, media_item_id),
                title_id = COALESCE(%s, title_id),
                completed_at = NOW(),
                updated_at = NOW(),
                error_message = NULL
            WHERE id = %s
        """, (file_path, media_item_id, title_id, task_id))

        # media_ingest_tasks
        cursor.execute("SELECT title FROM ott_download_tasks WHERE id = %s", (task_id,))
        row = cursor.fetchone()
        if row:
            title_name = row[0]
            cursor.execute("""
                UPDATE media_ingest_tasks
                SET status = 'completed',
                    progress = 100,
                    download_speed = '0.0 MB/s',
                    eta = 'Completado',
                    output_file = %s,
                    media_item_id = COALESCE(%s, media_item_id),
                    title_id = COALESCE(%s, title_id),
                    completed_at = NOW(),
                    updated_at = NOW(),
                    error_message = NULL
                WHERE title = %s OR output_file = %s
            """, (file_path, media_item_id, title_id, title_name, file_path))
    finally:
        cursor.close()
        conn.close()


def mark_task_failed(task_id: int, error_message: str):
    """Marca la tarea como fallida registrando el mensaje de error."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            UPDATE ott_download_tasks
            SET status = 'failed',
                download_speed = '0.0 MB/s',
                eta = 'Fallo',
                error_message = %s,
                updated_at = NOW()
            WHERE id = %s
        """, (error_message, task_id))

        cursor.execute("SELECT title, file_path FROM ott_download_tasks WHERE id = %s", (task_id,))
        row = cursor.fetchone()
        if row:
            title_name, file_path = row[0], row[1]
            cursor.execute("""
                UPDATE media_ingest_tasks
                SET status = 'failed',
                    download_speed = '0.0 MB/s',
                    eta = 'Fallo',
                    error_message = %s,
                    updated_at = NOW()
                WHERE title = %s OR output_file = %s
            """, (error_message, title_name, file_path))
    finally:
        cursor.close()
        conn.close()


def index_media_content(
    title_name: str,
    file_path: str,
    poster_url: Optional[str],
    backdrop_url: Optional[str],
    overview: str,
    provider: str,
    media_type: str = "movie",
    release_year: Optional[int] = None,
    genre: Optional[str] = None,
    duration_seconds: int = 7200,
    file_size_bytes: int = 0
) -> Dict[str, int]:
    """
    Inserta o actualiza el registro en `titles` y `media_items` en MySQL
    para que aparezca de inmediato en `/browse` con su carátula oficial.
    """
    conn = get_connection()
    cursor = conn.cursor(dictionary=True)
    try:
        # 1. Determinar categoría según tipo (Películas o Series)
        cat_slug = "series" if media_type == "series" else "peliculas"
        cursor.execute("SELECT id FROM categories WHERE slug = %s LIMIT 1", (cat_slug,))
        cat_row = cursor.fetchone()
        category_id = cat_row["id"] if cat_row else (2 if media_type == "series" else 1)

        # 2. Generar slug único del título
        import re
        clean_slug = re.sub(r'[^a-zA-Z0-9]+', '-', title_name.lower()).strip('-')
        full_slug = f"{clean_slug}-{provider.lower()}"

        # 3. Buscar o insertar en `titles`
        cursor.execute("SELECT id FROM titles WHERE slug = %s OR name = %s LIMIT 1", (full_slug, title_name))
        existing_title = cursor.fetchone()

        if existing_title:
            title_id = existing_title["id"]
            cursor.execute("""
                UPDATE titles
                SET description = %s,
                    poster_url = COALESCE(%s, poster_url),
                    backdrop_url = COALESCE(%s, backdrop_url),
                    release_year = COALESCE(%s, release_year),
                    genre = COALESCE(%s, genre),
                    duration_seconds = %s,
                    stream_path = %s,
                    is_featured = 1,
                    provider = %s,
                    updated_at = NOW()
                WHERE id = %s
            """, (
                overview, poster_url, backdrop_url, release_year or datetime.now().year,
                genre or "Acción / Streaming", duration_seconds, file_path, provider, title_id
            ))
        else:
            cursor.execute("""
                INSERT INTO titles (
                    name, slug, type, category_id, description, poster_url, backdrop_url,
                    release_year, genre, duration_seconds, stream_path, is_featured,
                    provider, created_at, updated_at
                ) VALUES (
                    %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1, %s, NOW(), NOW()
                )
            """, (
                title_name, full_slug, media_type, category_id, overview, poster_url,
                backdrop_url, release_year or datetime.now().year, genre or "Acción / Streaming",
                duration_seconds, file_path, provider
            ))
            title_id = cursor.lastrowid

        # 4. Buscar o insertar en `media_items` (archivo físico real indexado)
        cursor.execute("SELECT id FROM media_items WHERE file_path = %s LIMIT 1", (file_path,))
        existing_media = cursor.fetchone()

        if existing_media:
            media_item_id = existing_media["id"]
            cursor.execute("""
                UPDATE media_items
                SET title = %s,
                    title_id = %s,
                    provider = %s,
                    file_size = %s,
                    updated_at = NOW()
                WHERE id = %s
            """, (title_name, title_id, provider, file_size_bytes, media_item_id))
        else:
            cursor.execute("""
                INSERT INTO media_items (
                    title, title_id, provider, file_path, file_size,
                    status, created_at, updated_at
                ) VALUES (
                    %s, %s, %s, %s, %s, 'ready', NOW(), NOW()
                )
            """, (title_name, title_id, provider, file_path, file_size_bytes))
            media_item_id = cursor.lastrowid

        return {
            "title_id": title_id,
            "media_item_id": media_item_id
        }
    finally:
        cursor.close()
        conn.close()


def create_download_task(
    title: str,
    provider: str,
    external_id: str,
    media_type: str,
    target_folder: str,
    output_file: str,
    quality: str = "1080p",
    ott_account_id: Optional[int] = None,
    metadata: Optional[Dict[str, Any]] = None
) -> int:
    """Crea una nueva tarea de descarga en la cola de FlixHN."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # Verificar si ya existe una tarea idéntica pendiente
        cursor.execute("""
            SELECT id FROM ott_download_tasks
            WHERE provider = %s AND (external_id = %s OR title = %s) AND status IN ('pending', 'downloading')
            LIMIT 1
        """, (provider, external_id, title))
        existing = cursor.fetchone()
        if existing:
            return existing[0]

        meta_json = json.dumps(metadata or {})

        cursor.execute("""
            INSERT INTO ott_download_tasks (
                provider, ott_account_id, title, media_type, external_id,
                quality, status, progress, download_speed, eta, file_path,
                metadata, created_at, updated_at
            ) VALUES (
                %s, %s, %s, %s, %s, %s, 'pending', 0, '0.0 MB/s', 'En cola...',
                %s, %s, NOW(), NOW()
            )
        """, (
            provider, ott_account_id, title, media_type, external_id,
            quality, output_file, meta_json
        ))
        ott_task_id = cursor.lastrowid

        # Insertar también en media_ingest_tasks
        cursor.execute("""
            INSERT INTO media_ingest_tasks (
                source_url, provider, title, target_folder, status,
                progress, download_speed, eta, quality, auto_index,
                output_file, created_at, updated_at
            ) VALUES (
                %s, %s, %s, %s, 'pending', 0, '0.0 MB/s', 'En cola...',
                %s, 1, %s, NOW(), NOW()
            )
        """, (
            f"https://{provider}.com/title/{external_id}", provider, title,
            target_folder, quality, output_file
        ))

        return ott_task_id
    finally:
        cursor.close()
        conn.close()
