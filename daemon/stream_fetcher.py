"""
FlixHN OTT Dynamic Stream Fetcher & Media Probe Microservice
Servicio auxiliar asincrono de alto rendimiento en Python 3.x para FlixHN.
Intermediario oficial entre Laravel y fuentes de streaming autorizadas.
"""

import argparse
import asyncio
import ipaddress
import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
import urllib.parse
from typing import Any, Dict, List, Optional, Tuple

try:
    import aiohttp
    from aiohttp import web
except ImportError:
    aiohttp = None
    web = None

# Configuracion de Logging Estructurado
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [StreamFetcher] %(message)s",
    handlers=[logging.StreamHandler(sys.stderr)]
)
logger = logging.getLogger("stream_fetcher")

# Rangos IP privados y reservados (Prevención SSRF)
PRIVATE_NETWORKS = [
    ipaddress.ip_network("0.0.0.0/8"),
    ipaddress.ip_network("10.0.0.0/8"),
    ipaddress.ip_network("127.0.0.0/8"),
    ipaddress.ip_network("169.254.0.0/16"),
    ipaddress.ip_network("172.16.0.0/12"),
    ipaddress.ip_network("192.168.0.0/16"),
    ipaddress.ip_network("224.0.0.0/4"),
    ipaddress.ip_network("240.0.0.0/4"),
    ipaddress.ip_network("::1/128"),
    ipaddress.ip_network("fc00::/7"),
    ipaddress.ip_network("fe80::/10"),
]

INTERNAL_ALLOWLIST = {
    "localhost:7000",
    "127.0.0.1:7000",
    "flixhn_backend",
    "flixhn_nginx",
    "flixhn_media",
}


def is_safe_url(url: str, allow_internal: bool = True) -> Tuple[bool, Optional[str]]:
    """Valida si una URL es segura contra ataques de tipo SSRF."""
    try:
        parsed = urllib.parse.urlparse(url)
        if parsed.scheme.lower() not in ("http", "https"):
            return False, f"Esquema no permitido: {parsed.scheme}"

        host = parsed.hostname
        if not host:
            return False, "Host ausente en la URL"

        host_lower = host.lower()
        if allow_internal:
            if host_lower in INTERNAL_ALLOWLIST or f"{host_lower}:{parsed.port}" in INTERNAL_ALLOWLIST:
                return True, None

        # Resolver DNS
        try:
            addr_info = socket.getaddrinfo(host, parsed.port or (443 if parsed.scheme == "https" else 80))
            for item in addr_info:
                ip_str = item[4][0]
                ip_obj = ipaddress.ip_address(ip_str)
                for net in PRIVATE_NETWORKS:
                    if ip_obj in net:
                        return False, f"La IP resuelta {ip_str} pertenece a un rango privado o reservado"
        except socket.gaierror as e:
            return False, f"Fallo de resolucion DNS para {host}: {e}"

        return True, None
    except Exception as e:
        return False, f"Error validando URL: {e}"


class HlsManifestParser:
    """Procesador asincrono de manifiestos M3U8 para fuentes HLS autorizadas."""

    @staticmethod
    def parse_m3u8_content(content: str, base_url: str) -> Dict[str, Any]:
        lines = [line.strip() for line in content.splitlines() if line.strip()]
        if not lines or not lines[0].startswith("#EXTM3U"):
            return {"valid": False, "error": "No es un manifiesto M3U8 valido (falta etiqueta #EXTM3U)"}

        is_master = any(l.startswith("#EXT-X-STREAM-INF") for l in lines)
        result: Dict[str, Any] = {
            "valid": True,
            "type": "master" if is_master else "media",
            "base_url": base_url,
            "variants": [],
            "segments": [],
            "has_drm": False,
            "drm_info": None,
            "target_duration": None,
        }

        if is_master:
            i = 0
            while i < len(lines):
                line = lines[i]
                if line.startswith("#EXT-X-STREAM-INF:"):
                    attributes = line[len("#EXT-X-STREAM-INF:"):]
                    bandwidth_m = re.search(r"BANDWIDTH=(\d+)", attributes)
                    resolution_m = re.search(r"RESOLUTION=(\d+x\d+)", attributes)
                    codecs_m = re.search(r'CODECS="([^"]+)"', attributes)
                    uri = ""
                    if i + 1 < len(lines) and not lines[i + 1].startswith("#"):
                        uri = lines[i + 1]
                        i += 1
                    full_uri = urllib.parse.urljoin(base_url, uri) if uri else ""
                    result["variants"].append({
                        "bandwidth": int(bandwidth_m.group(1)) if bandwidth_m else None,
                        "resolution": resolution_m.group(1) if resolution_m else None,
                        "codecs": codecs_m.group(1) if codecs_m else None,
                        "url": full_uri,
                    })
                i += 1
        else:
            # Media playlist
            i = 0
            current_duration = 0.0
            while i < len(lines):
                line = lines[i]
                if line.startswith("#EXT-X-TARGETDURATION:"):
                    try:
                        result["target_duration"] = float(line.split(":")[1])
                    except (IndexError, ValueError):
                        pass
                elif line.startswith("#EXT-X-KEY:"):
                    result["has_drm"] = True
                    result["drm_info"] = line
                elif line.startswith("#EXTINF:"):
                    dur_m = re.search(r"#EXTINF:([\d.]+)", line)
                    if dur_m:
                        current_duration = float(dur_m.group(1))
                    if i + 1 < len(lines) and not lines[i + 1].startswith("#"):
                        seg_uri = urllib.parse.urljoin(base_url, lines[i + 1])
                        result["segments"].append({
                            "duration": current_duration,
                            "url": seg_uri,
                        })
                        i += 1
                i += 1

        return result


class StreamFetcherService:
    """Servicio de consulta asincrona y gestion de streaming con aiohttp."""

    def __init__(self, timeout_seconds: int = 10, max_concurrent: int = 10):
        self.timeout = aiohttp.ClientTimeout(total=timeout_seconds, connect=3) if aiohttp else None
        self.semaphore = asyncio.Semaphore(max_concurrent)
        self.session: Optional[Any] = None

    async def get_session(self) -> Any:
        if self.session is None or self.session.closed:
            connector = aiohttp.TCPConnector(limit=50, keepalive_timeout=30)
            self.session = aiohttp.ClientSession(
                connector=connector,
                timeout=self.timeout,
                headers={"User-Agent": "FlixHN-StreamingFetcher/2.0"}
            )
        return self.session

    async def close(self):
        if self.session and not self.session.closed:
            await self.session.close()

    async def probe_manifest(self, url: str, headers: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
        """Descarga e inspecciona un manifiesto HLS M3U8 de forma asincrona y eficiente."""
        safe, reason = is_safe_url(url)
        if not safe:
            return {"success": False, "error": f"URL no segura (SSRF): {reason}"}

        start_time = time.time()
        session = await self.get_session()
        custom_headers = headers or {}

        try:
            async with self.semaphore:
                async with session.get(url, headers=custom_headers) as resp:
                    if resp.status not in (200, 206):
                        return {
                            "success": False,
                            "status_code": resp.status,
                            "error": f"El servidor remoto devolvio HTTP {resp.status}",
                            "latency_ms": round((time.time() - start_time) * 1000, 2),
                        }

                    content = await resp.text(encoding="utf-8", errors="replace")
                    latency_ms = round((time.time() - start_time) * 1000, 2)

                    parsed = HlsManifestParser.parse_m3u8_content(content, str(resp.url))
                    if not parsed.get("valid"):
                        return {
                            "success": False,
                            "error": parsed.get("error", "Error parseando M3U8"),
                            "latency_ms": latency_ms,
                        }

                    # Validar accesibilidad de segmentos con solicitud HEAD rápida sobre el primer segmento
                    segment_check = "skipped"
                    if parsed.get("segments"):
                        first_seg_url = parsed["segments"][0]["url"]
                        try:
                            async with session.head(first_seg_url, headers=custom_headers, timeout=aiohttp.ClientTimeout(total=4)) as seg_resp:
                                segment_check = "accessible" if seg_resp.status in (200, 206) else f"http_{seg_resp.status}"
                        except Exception as ex:
                            segment_check = f"error: {ex}"

                    return {
                        "success": True,
                        "url": url,
                        "final_url": str(resp.url),
                        "latency_ms": latency_ms,
                        "manifest": parsed,
                        "first_segment_status": segment_check,
                    }
        except Exception as e:
            logger.error(f"Error procesando manifiesto {url}: {e}")
            return {
                "success": False,
                "error": str(e),
                "latency_ms": round((time.time() - start_time) * 1000, 2),
            }

    async def probe_media_with_ffprobe(self, media_url: str) -> Dict[str, Any]:
        """Inspecciona una fuente multimedia autorizada con ffprobe sin descargar el archivo completo."""
        safe, reason = is_safe_url(media_url)
        if not safe:
            return {"success": False, "error": f"URL no segura (SSRF): {reason}"}

        cmd = [
            "ffprobe",
            "-v", "quiet",
            "-print_format", "json",
            "-show_format",
            "-show_streams",
            "-timeout", "5000000",  # 5 segundos microsegundos
            media_url
        ]

        try:
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=8.0)

            if proc.returncode != 0:
                return {
                    "success": False,
                    "error": f"ffprobe finalizo con codigo {proc.returncode}: {stderr.decode(errors='ignore')[:300]}"
                }

            data = json.loads(stdout.decode(errors="replace"))
            streams = data.get("streams", [])
            video_stream = next((s for s in streams if s.get("codec_type") == "video"), None)
            audio_streams = [s for s in streams if s.get("codec_type") == "audio"]
            fmt = data.get("format", {})

            return {
                "success": True,
                "format": fmt.get("format_name"),
                "duration_seconds": float(fmt.get("duration", 0)),
                "bitrate": int(fmt.get("bit_rate", 0)) if fmt.get("bit_rate") else None,
                "video": {
                    "codec": video_stream.get("codec_name") if video_stream else None,
                    "width": video_stream.get("width") if video_stream else None,
                    "height": video_stream.get("height") if video_stream else None,
                    "fps": eval(video_stream.get("r_frame_rate", "0/1")) if video_stream and "/" in video_stream.get("r_frame_rate", "") else None,
                } if video_stream else None,
                "audio_tracks": [
                    {
                        "codec": a.get("codec_name"),
                        "channels": a.get("channels"),
                        "sample_rate": a.get("sample_rate"),
                        "language": a.get("tags", {}).get("language", "und"),
                    } for a in audio_streams
                ],
            }
        except FileNotFoundError:
            return {"success": False, "error": "ffprobe no se encuentra instalado en el sistema"}
        except asyncio.TimeoutError:
            return {"success": False, "error": "Tiempo de espera agotado al consultar metadatos con ffprobe"}
        except Exception as e:
            return {"success": False, "error": f"Error inesperado en ffprobe: {e}"}


# ---------------------------------------------------------------------------
# Servidor REST Microservicio Asincrono (aiohttp.web)
# ---------------------------------------------------------------------------

async def handle_health(request: Any) -> Any:
    return web.json_response({
        "status": "online",
        "service": "FlixHN Stream Fetcher Daemon",
        "timestamp": time.time(),
    })


async def handle_probe_manifest(request: Any) -> Any:
    try:
        data = await request.json()
    except Exception:
        return web.json_response({"success": False, "error": "JSON no valido en la solicitud"}, status=400)

    url = data.get("url")
    if not url:
        return web.json_response({"success": False, "error": "Parametro 'url' requerido"}, status=422)

    headers = data.get("headers", {})
    fetcher: StreamFetcherService = request.app["fetcher"]
    result = await fetcher.probe_manifest(url, headers)
    status_code = 200 if result.get("success") else 400
    return web.json_response(result, status=status_code)


async def handle_probe_media(request: Any) -> Any:
    try:
        data = await request.json()
    except Exception:
        return web.json_response({"success": False, "error": "JSON no valido en la solicitud"}, status=400)

    url = data.get("url")
    if not url:
        return web.json_response({"success": False, "error": "Parametro 'url' requerido"}, status=422)

    fetcher: StreamFetcherService = request.app["fetcher"]
    result = await fetcher.probe_media_with_ffprobe(url)
    status_code = 200 if result.get("success") else 400
    return web.json_response(result, status=status_code)


def create_web_app() -> Any:
    if not web:
        raise RuntimeError("aiohttp no esta instalado")
    app = web.Application()
    fetcher = StreamFetcherService()
    app["fetcher"] = fetcher

    async def on_cleanup(app_instance: Any):
        await app_instance["fetcher"].close()

    app.on_cleanup.append(on_cleanup)

    app.router.add_get("/health", handle_health)
    app.router.add_post("/probe/manifest", handle_probe_manifest)
    app.router.add_post("/probe/media", handle_probe_media)

    return app


# ---------------------------------------------------------------------------
# Interfaz CLI (Linea de comandos directa para scripts e integracion)
# ---------------------------------------------------------------------------

async def cli_probe_manifest(url: str, headers_json: Optional[str] = None):
    fetcher = StreamFetcherService()
    headers = json.loads(headers_json) if headers_json else None
    try:
        res = await fetcher.probe_manifest(url, headers)
        print(json.dumps(res, indent=2, ensure_ascii=False))
    finally:
        await fetcher.close()


async def cli_probe_media(url: str):
    fetcher = StreamFetcherService()
    try:
        res = await fetcher.probe_media_with_ffprobe(url)
        print(json.dumps(res, indent=2, ensure_ascii=False))
    finally:
        await fetcher.close()


def main():
    parser = argparse.ArgumentParser(description="FlixHN Stream Fetcher & OTT Probe Daemon")
    subparsers = parser.add_subparsers(dest="command", required=True)

    # Subcomando: serve
    serve_parser = subparsers.add_parser("serve", help="Inicia el microservicio HTTP daemon")
    serve_parser.add_argument("--host", default="0.0.0.0", help="Direccion IP de escucha (default: 0.0.0.0)")
    serve_parser.add_argument("--port", type=int, default=5005, help="Puerto TCP de escucha (default: 5005)")

    # Subcomando: probe-manifest
    manifest_parser = subparsers.add_parser("probe-manifest", help="Inspecciona un manifiesto HLS M3U8")
    manifest_parser.add_argument("--url", required=True, help="URL del manifiesto M3U8")
    manifest_parser.add_argument("--headers", help="JSON string con cabeceras HTTP personalizadas")

    # Subcomando: probe-media
    media_parser = subparsers.add_parser("probe-media", help="Inspecciona metadatos multimedia con ffprobe")
    media_parser.add_argument("--url", required=True, help="URL o ruta del archivo de video")

    args = parser.parse_args()

    if args.command == "serve":
        logger.info(f"Iniciando microservicio Stream Fetcher en http://{args.host}:{args.port}")
        app = create_web_app()
        web.run_app(app, host=args.host, port=args.port)
    elif args.command == "probe-manifest":
        asyncio.run(cli_probe_manifest(args.url, args.headers))
    elif args.command == "probe-media":
        asyncio.run(cli_probe_media(args.url))


if __name__ == "__main__":
    main()
