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

        $resolvedPath = null;
        $title = null;
        $episode = null;

        // 1. Localizar el modelo en base de datos si se proporcionó un ID
        if ($targetId) {
            $title = Title::find($targetId);
            if ($title) {
                $rawPath = $rawPath ?: $title->stream_path ?: ($title->source_path ?? null);
                if (!$rawPath && $title->mediaItem) {
                    $rawPath = $title->mediaItem->file_path;
                }
            }

            if (!$rawPath) {
                $episode = Episode::find($targetId);
                if ($episode) {
                    $rawPath = $episode->stream_path ?: ($episode->source_path ?? null);
                }
            }

            if (!$rawPath) {
                $mediaItem = MediaItem::find($targetId);
                if ($mediaItem) {
                    $rawPath = $mediaItem->file_path ?: $mediaItem->stream_url;
                }
            }

            if (!$rawPath) {
                $ingestTask = MediaIngestTask::where('id', $targetId)
                    ->orWhere('title_id', $targetId)
                    ->first();
                if ($ingestTask) {
                    $rawPath = $ingestTask->output_file;
                }
            }

            if (!$rawPath) {
                $ottTask = OttDownloadTask::where('id', $targetId)
                    ->orWhere('external_id', $targetId)
                    ->first();
                if ($ottTask) {
                    $rawPath = $ottTask->file_path;
                }
            }
        }

        // 2. Resolver la ruta física en disco
        $resolvedPath = $this->resolvePhysicalFilePath($rawPath);

        if (!$resolvedPath || !File::exists($resolvedPath)) {
            Log::warning("MediaStreamController@stream: Archivo físico no encontrado para targetId [{$targetId}], rawPath [{$rawPath}]");
            return response()->json([
                'status' => 'error',
                'message' => 'El archivo de video local no se encuentra en el almacenamiento del servidor.',
                'id' => $targetId,
                'path' => $rawPath,
            ], 404);
        }

        return $this->serveStreamWithByteRange($request, $resolvedPath);
    }

    /**
     * Resolver la ruta absoluta del archivo en disco probando rutas estándar.
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

        return null;
    }

    /**
     * Servir archivo multimedia con soporte completo para encabezados HTTP Range: bytes.
     */
    protected function serveStreamWithByteRange(Request $request, string $filePath)
    {
        $fileSize = (int) @filesize($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $this->mimeTypes[$extension] ?? 'video/mp4';

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
