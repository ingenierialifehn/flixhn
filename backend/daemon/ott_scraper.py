"""
FlixGrab OTT Background Daemon - Catalog Scraper & Provider Ingestion
Descubrimiento automatizado de contenido de Netflix y proveedores OTT conectados
para encolar y descargar películas/series con carátulas oficiales.
"""

import json
import logging
import requests
from typing import List, Dict, Any, Optional

from config import SUPPORTED_PROVIDERS, MEDIA_DIR
from db import create_download_task, get_connection

logger = logging.getLogger("FlixGrabDaemon.Scraper")

# Catálogo curado oficial por plataforma para autodescarga e ingesta en segundo plano
CURATED_PLATFORM_RELEASES = {
    "netflix": [
        {
            "external_id": "nflx-stranger-things",
            "title": "Stranger Things",
            "original_title": "Stranger Things",
            "type": "series",
            "release_year": 2024,
            "genre": "Ciencia Ficción / Misterio",
            "overview": "Cuando un niño desaparece, sus amigos, la familia y la policía se ven envueltos en un misterio que involucra experimentos ultrasecretos, fuerzas sobrenaturales terroríficas y una niña muy extraña.",
            "poster_url": "https://image.tmdb.org/t/p/w500/49WJfeN0moxb9IPfGn8AIqMGskD.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/56v2KjBlU4XaOv9rVYEQypROD7P.jpg",
        },
        {
            "external_id": "nflx-wednesday",
            "title": "Merlina (Wednesday)",
            "original_title": "Wednesday",
            "type": "series",
            "release_year": 2024,
            "genre": "Fantasía / Comedia Negra",
            "overview": "Inteligente, sarcástica y un poco muerta por dentro, Merlina Addams investiga una ola de asesinatos mientras hace nuevos amigos —y enemigos— en la Academia Nunca Más.",
            "poster_url": "https://image.tmdb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/iHSwvRVsRyxpX7FE7GbviaDvgGZ.jpg",
        },
        {
            "external_id": "nflx-squid-game",
            "title": "El Juego del Calamar",
            "original_title": "Squid Game",
            "type": "series",
            "release_year": 2024,
            "genre": "Suspense / Drama",
            "overview": "Cientos de personas con dificultades económicas aceptan una extraña invitación para competir en juegos infantiles. Adentro les esperan un tentador premio y desafíos de supervivencia letales.",
            "poster_url": "https://image.tmdb.org/t/p/w500/dDlGcaqftV2zX9G97R435W4P9vA.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/oaGvjB0DvdurWhf9IhStbtQg9d4.jpg",
        },
        {
            "external_id": "nflx-extraction-2",
            "title": "Misión de Rescate 2 (Extraction 2)",
            "original_title": "Extraction 2",
            "type": "movie",
            "release_year": 2023,
            "genre": "Acción / Suspense",
            "overview": "Tras sobrevivir de milagro a su última misión, el mercenario de operaciones encubiertas Tyler Rake se enfrenta a un desafío aún más peligroso: rescatar a la familia de un despiadado gánster.",
            "poster_url": "https://image.tmdb.org/t/p/w500/7gKI9hpEMcZUwYeCuVmACQC9Y42.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/7I6VUdPj6tQECNHdviJkUHD2389.jpg",
        },
        {
            "external_id": "nflx-arcane",
            "title": "Arcane: League of Legends",
            "original_title": "Arcane",
            "type": "series",
            "release_year": 2024,
            "genre": "Animación / Ciencia Ficción",
            "overview": "En medio del conflicto entre las ciudades gemelas de Piltóver y Zaun, dos hermanas luchan en bandos opuestos de una guerra entre tecnologías mágicas y convicciones incompatibles.",
            "poster_url": "https://image.tmdb.org/t/p/w500/abf8tHznhSvl9BAElD2cQYRrQI2.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/fqldf2t8ztc9aiwn397FvFeUQgr.jpg",
        }
    ],
    "disney": [
        {
            "external_id": "dis-deadpool-wolverine",
            "title": "Deadpool & Wolverine",
            "original_title": "Deadpool & Wolverine",
            "type": "movie",
            "release_year": 2024,
            "genre": "Acción / Comedia",
            "overview": "Un apático Wade Wilson se afana en la vida civil dejando atrás sus días como Deadpool. Pero cuando su mundo natal se enfrenta a una amenaza existencial, vuelve a la acción con Wolverine.",
            "poster_url": "https://image.tmdb.org/t/p/w500/8cdWjvZQUExUUTzyp4t6EDMubfO.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/yDHYTfA3R0jFYba16jBB1jv8uaC.jpg",
        },
        {
            "external_id": "dis-loki",
            "title": "Loki",
            "original_title": "Loki",
            "type": "series",
            "release_year": 2024,
            "genre": "Ciencia Ficción / Aventura",
            "overview": "El voluble villano Loki retoma su papel como Dios de las mentiras en una nueva serie que transcurre tras los acontecimientos de Avengers: Endgame.",
            "poster_url": "https://image.tmdb.org/t/p/w500/voHUmlvjysv923xao5iJDmeM6Bq.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/bZGA55Xk59T4gZ8UvT3D1Kx6y7v.jpg",
        }
    ],
    "hbo_max": [
        {
            "external_id": "hbo-house-of-dragon",
            "title": "La Casa del Dragón (House of the Dragon)",
            "original_title": "House of the Dragon",
            "type": "series",
            "release_year": 2024,
            "genre": "Drama / Fantasía",
            "overview": "La historia de la Casa Targaryen, ambientada 200 años antes de los eventos de Game of Thrones.",
            "poster_url": "https://image.tmdb.org/t/p/w500/1XDDXPXGiI8id7MrUxK36ke7gkX.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/etj5CuMuam3hD9P9G4zg0J2026.jpg",
        },
        {
            "external_id": "hbo-dune-part-two",
            "title": "Dune: Parte Dos",
            "original_title": "Dune: Part Two",
            "type": "movie",
            "release_year": 2024,
            "genre": "Ciencia Ficción / Aventura",
            "overview": "Paul Atreides se une a Chani y a los Fremen mientras busca venganza contra los conspiradores que destruyeron a su familia.",
            "poster_url": "https://image.tmdb.org/t/p/w500/1pdfLvkbY9ohJlCjQH2CZjjYVvJ.jpg",
            "backdrop_url": "https://image.tmdb.org/t/p/original/xOMo8BRK7PfcJv9JCnx7s5hj0x2.jpg",
        }
    ]
}


def sync_auto_downloads_for_account(account: Dict[str, Any]) -> int:
    """
    Si la cuenta tiene `auto_download = 1`, descubre estrenos de la plataforma
    que no estén descargados ni en cola y crea las tareas de descarga correspondientes.
    """
    if not account.get("auto_download"):
        return 0

    provider = account.get("provider", "netflix")
    account_id = account.get("id")
    quality = account.get("quality_profile") or "1080p"

    releases = CURATED_PLATFORM_RELEASES.get(provider, [])
    if not releases:
        return 0

    queued_count = 0
    conn = get_connection()
    cursor = conn.cursor(dictionary=True)

    try:
        for item in releases:
            title = item["title"]
            ext_id = item["external_id"]
            media_type = item["type"]

            # Comprobar si ya existe en títulos o en tareas de descarga
            cursor.execute("""
                SELECT id FROM titles WHERE name = %s OR slug LIKE %s LIMIT 1
            """, (title, f"%{ext_id}%"))
            existing_title = cursor.fetchone()

            cursor.execute("""
                SELECT id, status FROM ott_download_tasks 
                WHERE provider = %s AND (external_id = %s OR title = %s)
                LIMIT 1
            """, (provider, ext_id, title))
            existing_task = cursor.fetchone()

            # Si ya está completada o existe el título, omitir
            if existing_title or (existing_task and existing_task["status"] == "completed"):
                continue

            # Si no existe tarea pendiente, encolarla
            if not existing_task:
                target_folder = str(MEDIA_DIR / provider / ("series" if media_type == "series" else "movies")).replace("\\", "/")
                slug_name = title.lower().replace(" ", "-")
                output_file = f"{target_folder}/{slug_name}.mp4"

                create_download_task(
                    title=title,
                    provider=provider,
                    external_id=ext_id,
                    media_type=media_type,
                    target_folder=target_folder,
                    output_file=output_file,
                    quality=quality,
                    ott_account_id=account_id,
                    metadata=item
                )
                logger.info(f"Auto-descarga programada para '{title}' ({provider}) en {output_file}")
                queued_count += 1

        return queued_count
    finally:
        cursor.close()
        conn.close()
