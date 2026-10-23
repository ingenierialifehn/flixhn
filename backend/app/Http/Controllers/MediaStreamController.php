<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStreamController extends Controller
{
    /**
     * Extensiones de medios soportadas y sus tipos MIME.
     */
    protected array $mimeTypes = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'webm' => 'video/webm',
        'ts' => 'video/mp2t',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Endpoint de streaming directo para archivos locales OTT y catálogo On-Net.
     * Soporta solicitudes de rango de bytes (HTTP 206 Partial Content) para MP4/MKV.
     *
     * Rutas:
     * GET/HEAD /api/media/{id}/stream
     * GET/HEAD /api/stream/{id}
     * GET/HEAD /api/media/stream
     */
    public function stream(Request $request, $id = null)
    {
        $targetId = $id ?: $request->query('id') ?: $request->query('title_id');
        $rawPath = $request->query('path') ?: $request->query('file');

        $mediaItem = null;

        // 1. Obtener el modelo MediaItem
        if ($targetId) {
            $mediaItem = MediaItem::find($targetId);

            // Si se suministró un ID de Title, buscar el mediaItem vinculado
            if (!$mediaItem) {
                $mediaItem = MediaItem::where('title_id', $targetId)->first();
            }

            // Si aún no se encuentra, verificar en Title o Episode
            if (!$mediaItem) {
                $title = Title::find($targetId);
                if ($title) {
                    if ($title->mediaItem) {
                        $mediaItem = $title->mediaItem;
                    } elseif (!empty($title->stream_path) || !empty($title->source_path)) {
                        $mediaItem = new MediaItem([
                            'id' => $title->id,
                            'title' => $title->name,
                            'file_path' => $title->stream_path ?: $title->source_path,
                            'stream_url' => $title->stream_url,
                        ]);
                    }
                }
            }

            if (!$mediaItem) {
                $episode = Episode::find($targetId);
                if ($episode && (!empty($episode->stream_path) || !empty($episode->source_path))) {
                    $mediaItem = new MediaItem([
                        'id' => $episode->id,
                        'title' => $episode->title,
                        'file_path' => $episode->stream_path ?: $episode->source_path,
                    ]);
                }
            }

            // Si no se encontró por ningún modelo y no hay ruta manual
            if (!$mediaItem && !$rawPath) {
                return response()->json([
                    'error' => 'Archivo físico no encontrado en el servidor',
                    'id' => $targetId,
                    'path' => null,
                ], 404);
            }
        }

        if ($mediaItem && empty($rawPath)) {
            $rawPath = $mediaItem->file_path ?: $mediaItem->stream_url;
        }

        if (empty($rawPath)) {
            return response()->json([
                'error' => 'Archivo físico no encontrado en el servidor',
                'path' => $mediaItem?->file_path ?? '',
            ], 404);
        }

        // 2. Verifica la existencia real del archivo:
        $path = $rawPath;

        // Si es ruta relativa dentro de storage:
        if (!file_exists($path) || is_dir($path)) {
            $checkApp = storage_path('app/' . ltrim($path, '/'));
            if (file_exists($checkApp) && !is_dir($checkApp)) {
                $path = $checkApp;
            }
        }
        if (!file_exists($path) || is_dir($path)) {
            $checkPublic = public_path(ltrim($mediaItem?->file_path ?? $rawPath, '/'));
            if (file_exists($checkPublic) && !is_dir($checkPublic)) {
                $path = $checkPublic;
            }
        }
        if (!file_exists($path) || is_dir($path)) {
            // Verificar dentro de storage_path si contiene prefijo /storage/ o storage/
            $relStorage = preg_replace('#^/?storage/#', '', $rawPath);
            $checkStorage = storage_path($relStorage);
            if (file_exists($checkStorage) && !is_dir($checkStorage)) {
                $path = $checkStorage;
            }
        }
        if (!file_exists($path) || is_dir($path)) {
            $resolved = $this->resolvePhysicalFilePath($rawPath);
            if ($resolved && file_exists($resolved) && !is_dir($resolved)) {
                $path = $resolved;
            }
        }

        if (empty($path) || !file_exists($path) || is_dir($path)) {
            Log::warning("MediaStreamController@stream: Archivo físico no encontrado para targetId [{$targetId}], path [{$rawPath}]");
            return response()->json([
                'error' => 'Archivo físico no encontrado en el servidor',
                'path' => $mediaItem?->file_path ?? $rawPath,
            ], 404);
        }

        return $this->serveStreamWithByteRange($request, $path);
    }

    /**
     * Resolver la ruta absoluta del archivo en disco probando rutas estándar y slug aproximado.
     */
    protected function resolvePhysicalFilePath(?string $rawPath): ?string
    {
        if (empty($rawPath)) {
            return null;
        }

        // Si ya existe tal cual
        if (File::exists($rawPath) && !File::isDirectory($rawPath)) {
            return $rawPath;
        }

        $normalized = str_replace('\\', '/', $rawPath);
        $fileName = basename($normalized);

        // Lista de carpetas candidatas prioritarias en el servidor
        $candidates = [
            $normalized,
            storage_path(ltrim($normalized, '/')),
            storage_path('media/' . $fileName),
            storage_path('media/movies/' . $fileName),
            storage_path('media/series/' . $fileName),
            storage_path('media/netflix/' . $fileName),
            storage_path('media/disney/' . $fileName),
            storage_path('media/hbo_max/' . $fileName),
            storage_path('media/amazon_prime/' . $fileName),
            storage_path('media/apple_tv/' . $fileName),
            storage_path('media/paramount/' . $fileName),
            "/var/www/html/storage/media/{$fileName}",
            "/var/www/html/storage/media/movies/{$fileName}",
            "/var/www/html/storage/media/series/{$fileName}",
            "/var/www/html/storage/media/netflix/{$fileName}",
            "/var/www/html/storage/media/disney/{$fileName}",
            "/var/www/html/storage/media/hbo_max/{$fileName}",
            "/var/www/html/storage/media/amazon_prime/{$fileName}",
            "/var/www/html/storage/media/apple_tv/{$fileName}",
            "/var/www/html/storage/media/paramount/{$fileName}",
            "/storage/media/{$fileName}",
            "/var/media/{$fileName}",
            base_path("media/{$fileName}"),
            storage_path("app/public/{$fileName}"),
        ];

        // Si viene con prefijo /storage/ o storage/
        if (str_starts_with($normalized, '/storage/') || str_starts_with($normalized, 'storage/')) {
            $rel = ltrim(preg_replace('#^/?storage/#', '', $normalized), '/');
            $candidates[] = storage_path($rel);
            $candidates[] = storage_path("app/public/{$rel}");
        }

        // Si viene con prefijo /media/ o media/
        if (str_starts_with($normalized, '/media/') || str_starts_with($normalized, 'media/')) {
            $rel = ltrim(preg_replace('#^/?media/#', '', $normalized), '/');
            $candidates[] = storage_path("media/{$rel}");
            $candidates[] = base_path("media/{$rel}");
            $candidates[] = "/var/media/{$rel}";
        }

        foreach ($candidates as $candidate) {
            if (!empty($candidate) && File::exists($candidate) && !File::isDirectory($candidate)) {
                return $candidate;
            }
        }

        // Búsqueda aproximada por slug base eliminando etiquetas de resolución/calidad
        // ej: stranger-things.4khdr.mkv -> busca en storage/media/*/*stranger-things*
        $cleanSlug = preg_replace('/(\.(1080p|720p|4k|4khdr|hdr|sd|uhd|bluray))+\.[a-z0-9]+$/i', '', $fileName);
        if (!empty($cleanSlug) && $cleanSlug !== $fileName) {
            $patterns = [
                storage_path("media/*/*{$cleanSlug}*"),
                storage_path("media/*{$cleanSlug}*"),
                "/var/www/html/storage/media/*/*{$cleanSlug}*",
                "/var/media/*{$cleanSlug}*",
            ];
            foreach ($patterns as $pattern) {
                $matches = glob($pattern) ?: [];
                foreach ($matches as $match) {
                    if (File::exists($match) && !File::isDirectory($match)) {
                        return $match;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Servir archivo multimedia con soporte completo para encabezados HTTP Range: bytes.
     */
    protected function serveStreamWithByteRange(Request $request, string $filePath)
    {
        $fileSize = (int) @filesize($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = in_array($extension, ['mp4', 'm4v', 'mkv'])
            ? 'video/mp4'
            : ($this->mimeTypes[$extension] ?? 'video/mp4');

        // Manejo de preflight OPTIONS
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
                'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        $start = 0;
        $end = $fileSize > 0 ? $fileSize - 1 : 0;
        $status = 200;
        $httpRange = $request->header('Range') ?: $request->server('HTTP_RANGE');

        if ($httpRange && preg_match('/bytes=\s*(\d*)-(\d*)/i', $httpRange, $matches)) {
            $rangeStart = $matches[1];
            $rangeEnd = $matches[2];

            if ($rangeStart === '' && $rangeEnd !== '') {
                // bytes=-500 -> últimos 500 bytes
                $start = max(0, $fileSize - (int) $rangeEnd);
                $end = $fileSize - 1;
            } elseif ($rangeStart !== '' && $rangeEnd === '') {
                // bytes=500- -> desde byte 500 hasta el final
                $start = (int) $rangeStart;
                $end = $fileSize - 1;
            } elseif ($rangeStart !== '' && $rangeEnd !== '') {
                // bytes=500-1000
                $start = (int) $rangeStart;
                $end = (int) $rangeEnd;
            }

            // Validar límites
            if ($start > $end || $start >= $fileSize) {
                return response('', 416, [
                    'Content-Range' => "bytes */{$fileSize}",
                    'Accept-Ranges' => 'bytes',
                    'Access-Control-Allow-Origin' => '*',
                ]);
            }

            $end = min($end, $fileSize - 1);
            $status = 206; // Partial Content
        }

        $length = ($fileSize > 0) ? ($end - $start + 1) : 0;

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, private',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        }

        // Si es solicitud HEAD, responder únicamente con los encabezados
        if ($request->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        // Streaming de fragmento mediante StreamedResponse sin agotar memoria RAM
        return new StreamedResponse(function () use ($filePath, $start, $length) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $fp = @fopen($filePath, 'rb');
            if (!$fp) {
                return;
            }

            if ($start > 0) {
                fseek($fp, $start);
            }

            $chunkSize = 256 * 1024; // 256 KB por iteración
            $bytesRemaining = $length;

            while ($bytesRemaining > 0 && !feof($fp) && !connection_aborted()) {
                $readLength = min($chunkSize, $bytesRemaining);
                $buffer = fread($fp, $readLength);
                if ($buffer === false || $buffer === '') {
                    break;
                }

                echo $buffer;
                flush();
                $bytesRemaining -= strlen($buffer);
            }

            fclose($fp);
        }, $status, $headers);
    }

    /**
     * Servir carátulas y assets desde storage/app/public con fallback automático si no existe.
     * Garantiza que nunca se muestre una imagen rota ni error 404 al solicitar carátulas.
     *
     * Ruta: GET /storage/{path}
     */
    public function serveStorage(Request $request, string $path)
    {
        $cleanPath = ltrim(str_replace('..', '', $path), '/');
        $fullPath = storage_path("app/public/{$cleanPath}");

        if (File::exists($fullPath) && !File::isDirectory($fullPath)) {
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = $this->mimeTypes[$ext] ?? File::mimeType($fullPath) ?: 'image/jpeg';
            return response()->file($fullPath, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=2592000, immutable',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        // Buscar en storage/media o public/
        $mediaAlt = storage_path("media/{$cleanPath}");
        if (File::exists($mediaAlt) && !File::isDirectory($mediaAlt)) {
            $ext = strtolower(pathinfo($mediaAlt, PATHINFO_EXTENSION));
            $mime = $this->mimeTypes[$ext] ?? 'image/jpeg';
            return response()->file($mediaAlt, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=2592000, immutable',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        // Fallback al placeholder institucional del sistema
        $placeholders = [
            public_path('img/placeholder-poster.png'),
            public_path('placeholder-poster.png'),
            base_path('../frontend/public/img/placeholder-poster.png'),
            base_path('../frontend/public/placeholder-poster.png'),
        ];

        foreach ($placeholders as $ph) {
            if (File::exists($ph)) {
                return response()->file($ph, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                    'Access-Control-Allow-Origin' => '*',
                ]);
            }
        }

        // Fallback transparente 1x1 si no hay archivo de placeholder
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        return response($tinyPng, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
