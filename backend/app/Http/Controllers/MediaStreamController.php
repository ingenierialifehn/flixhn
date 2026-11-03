<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $titleQuery = trim((string) ($request->query('title') ?: ''));

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
        }

        // 2. Determinar la ruta y resolver el archivo real
        $rawPath = $mediaItem ? $mediaItem->file_path : ($request->query('path') ?: $request->query('file'));
        $realFile = null;

        if (!empty($rawPath)) {
            // Rutas de resolución prioritarias según requerimiento
            $possiblePaths = [
                $rawPath,
                storage_path('media/' . basename(dirname($rawPath)) . '/' . basename($rawPath)),
                storage_path('app/' . ltrim($rawPath, '/')),
                public_path(ltrim($rawPath, '/')),
            ];

            // Rutas adicionales de compatibilidad de almacenamiento Docker/Linux y Windows
            $cleanedStorage = preg_replace('#^/?var/www/html/storage/#', '', $rawPath);
            $cleanedStorage = preg_replace('#^/?storage/#', '', $cleanedStorage);
            $cleanedStorage = ltrim($cleanedStorage, '/');

            // Variación de guiones y guiones bajos en nombres de carpetas
            $parts = explode('/', $cleanedStorage);
            $altParts = $parts;
            if (count($parts) >= 2) {
                $dirIdx = count($parts) - 2;
                $altParts[$dirIdx] = str_contains($altParts[$dirIdx], '_')
                    ? str_replace('_', '-', $altParts[$dirIdx])
                    : str_replace('-', '_', $altParts[$dirIdx]);
            }
            $altStorage = implode('/', $altParts);

            $possiblePaths[] = storage_path($cleanedStorage);
            $possiblePaths[] = storage_path($altStorage);
            $possiblePaths[] = storage_path('media/' . $cleanedStorage);
            $possiblePaths[] = storage_path('media/' . $altStorage);
            $possiblePaths[] = base_path($rawPath);
            $possiblePaths[] = base_path('storage/' . $cleanedStorage);
            $possiblePaths[] = '/var/www/html/storage/' . $cleanedStorage;
            $possiblePaths[] = '/var/www/html/storage/' . $altStorage;
            $possiblePaths[] = '/storage/' . $cleanedStorage;
            $possiblePaths[] = '/var/media/' . basename($rawPath);

            // Verificar si el archivo real existe en cualquiera de esas rutas
            foreach ($possiblePaths as $cand) {
                if (!empty($cand) && file_exists($cand) && !is_dir($cand) && !str_ends_with($cand, '.part') && !str_ends_with($cand, '.download') && @filesize($cand) > 1024) {
                    $realFile = $cand;
                    break;
                }
            }

            // Si aún no se ubicó, intentar resolución aproximada en disco
            if (!$realFile) {
                $resolved = $this->resolvePhysicalFilePath($rawPath);
                if ($resolved && file_exists($resolved) && !is_dir($resolved) && @filesize($resolved) > 1024) {
                    $realFile = $resolved;
                }
            }
        }

        // 2.5 Si aún no se ubicó y se proporcionó título, buscar por coincidencia de tarea o slug
        if (!$realFile && !empty($titleQuery)) {
            $matchedTask = MediaIngestTask::where('title', 'LIKE', "%{$titleQuery}%")
                ->orWhere('title', $titleQuery)
                ->orderBy('id', 'desc')
                ->first();

            if ($matchedTask && !empty($matchedTask->output_file)) {
                $cand = $this->resolvePhysicalFilePath($matchedTask->output_file);
                if ($cand && file_exists($cand) && @filesize($cand) > 1024) {
                    $realFile = $cand;
                }
            }

            if (!$realFile) {
                $matchedOtt = OttDownloadTask::where('title', 'LIKE', "%{$titleQuery}%")
                    ->orWhere('title', $titleQuery)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($matchedOtt && !empty($matchedOtt->file_path)) {
                    $cand = $this->resolvePhysicalFilePath($matchedOtt->file_path);
                    if ($cand && file_exists($cand) && @filesize($cand) > 1024) {
                        $realFile = $cand;
                    }
                }
            }

            if (!$realFile) {
                $cleanSlug = Str::slug($titleQuery);
                $patterns = [
                    storage_path("media/*/*/*{$cleanSlug}*"),
                    storage_path("media/*/*{$cleanSlug}*"),
                    storage_path("media/*{$cleanSlug}*"),
                    "/var/www/html/storage/media/*/*/*{$cleanSlug}*",
                    "/var/www/html/storage/media/*/*{$cleanSlug}*",
                    "/var/www/html/storage/media/*{$cleanSlug}*",
                ];
                foreach ($patterns as $pattern) {
                    $matches = glob($pattern) ?: [];
                    foreach ($matches as $match) {
                        if (file_exists($match) && !is_dir($match) && !str_ends_with($match, '.part') && !str_ends_with($match, '.download') && @filesize($match) > 1024) {
                            $realFile = $match;
                            break 2;
                        }
                    }
                }
            }

                    }

        // Si el archivo real existe en almacenamiento local On-Net, entregarlo inmediatamente por streaming HTTP 206
        if ($realFile && file_exists($realFile) && @filesize($realFile) > 1024) {
            return $this->serveStreamWithByteRange($request, $realFile);
        }

        // Si no está en disco aún, verificar si hay un stream auténtico disponible (Emby / Red On-Net)
        $lookupTitle = $titleQuery ?: ($mediaItem?->title ?? basename($rawPath));
        if (!empty($lookupTitle)) {
            try {
                $streamInfo = app(\App\Services\MediaIngestService::class)->resolveUniversalStreamUrl($lookupTitle);
                if ($streamInfo && !empty($streamInfo['stream_url'])) {
                    Log::info("MediaStreamController@stream: Sirviendo stream auténtico para [{$lookupTitle}] desde {$streamInfo['source']}");
                    return $this->proxyRemoteStreamWithByteRange($request, $streamInfo['stream_url']);
                }
            } catch (\Throwable $e) {
                Log::warning("Error resolviendo stream remoto para [{$lookupTitle}]: " . $e->getMessage());
            }
        }

        Log::warning("MediaStreamController@stream: Archivo físico o stream no encontrado para [{$rawPath}]");
        return response()->json([
            'error' => 'Archivo físico no encontrado en el servidor',
            'path' => $mediaItem?->file_path ?? $rawPath,
        ], 404);
    }

    /**
     * Obtener o generar dinámicamente un sample MP4 funcional y válido para pruebas y fallback.
     */
    protected function getFallbackSamplePath(): ?string
    {
        $samplePath = storage_path('media/samples/sample.mp4');
        if (file_exists($samplePath) && @filesize($samplePath) > 50000) {
            return $samplePath;
        }

        try {
            $dir = dirname($samplePath);
            if (!file_exists($dir)) {
                @mkdir($dir, 0777, true);
            }
            $escaped = escapeshellarg($samplePath);
            // Sin generador testsrc
            clearstatcache(true, $samplePath);
            if (file_exists($samplePath) && @filesize($samplePath) > 50000) {
                @chmod($samplePath, 0666);
                return $samplePath;
            }
        } catch (\Throwable $e) {
            Log::warning("No se pudo generar sample.mp4 en fallback: " . $e->getMessage());
        }

        return null;
    }

    public static function resolveDiskPathStatic(?string $path): ?string
    {
        if (empty($path)) return null;

        $normalized = str_replace('\\', '/', $path);
        if (File::exists($normalized) && !File::isDirectory($normalized)) {
            return $normalized;
        }

        $baseNormalized = str_replace('\\', '/', base_path());
        if (str_starts_with($normalized, '/var/www/html/') ||
            str_starts_with($normalized, $baseNormalized) ||
            preg_match('/^[a-zA-Z]:[\/\\]/', $normalized)) {
            return $normalized;
        }

        if (str_starts_with($normalized, '/storage/') || str_starts_with($normalized, 'storage/')) {
            $rel = ltrim(preg_replace('#^/?storage/#', '', $normalized), '/');
            return storage_path($rel);
        }

        if (str_starts_with($normalized, '/media/') || str_starts_with($normalized, 'media/')) {
            $rel = ltrim(preg_replace('#^/?media/#', '', $normalized), '/');
            return storage_path('media/' . $rel);
        }

        return storage_path(ltrim($normalized, '/'));
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
            if (!empty($candidate) && File::exists($candidate) && !File::isDirectory($candidate) && @filesize($candidate) > 1024) {
                return $candidate;
            }
        }

        // Búsqueda aproximada convirtiendo guiones bajos a guiones normales (ej: contra_el_hurac_n -> contra-el-huracan)
        $cleanName = str_replace('_', '-', pathinfo($fileName, PATHINFO_FILENAME));
        $cleanSlug = preg_replace('/(\.(1080p|720p|4k|4khdr|hdr|sd|uhd|bluray))+$/i', '', $cleanName);
        $cleanSlug = trim($cleanSlug, '-');

        if (!empty($cleanSlug)) {
            $patterns = [
                storage_path("media/*/*/*{$cleanSlug}*.mp4"),
                storage_path("media/*/*{$cleanSlug}*.mp4"),
                storage_path("media/*{$cleanSlug}*.mp4"),
                "/var/www/html/storage/media/*/*/*{$cleanSlug}*.mp4",
                "/var/www/html/storage/media/*/*{$cleanSlug}*.mp4",
                "/var/www/html/storage/media/*{$cleanSlug}*.mp4",
            ];
            foreach ($patterns as $pattern) {
                $matches = glob($pattern) ?: [];
                foreach ($matches as $match) {
                    if (File::exists($match) && !File::isDirectory($match) && @filesize($match) > 1024) {
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
    
    /**
     * Hace proxy transparente del stream remoto auténtico (Emby / CDN) hacia el navegador
     * soportando cabeceras HTTP 206 Partial Content y Range requests nativamente.
     */
    protected function proxyRemoteStreamWithByteRange(Request $request, string $remoteUrl)
    {
        $rangeHeader = $request->header('Range');
        $headers = [
            'User-Agent: FlixGrab/3.0',
            'Accept: */*',
        ];
        if (!empty($rangeHeader)) {
            $headers[] = "Range: {$rangeHeader}";
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 30,
                'follow_location' => 1,
            ]
        ];

        $context = stream_context_create($opts);
        $fp = @fopen($remoteUrl, 'rb', false, $context);
        if (!$fp) {
            return response()->json(['error' => 'No fue posible conectar al stream remoto de alta definición'], 502);
        }

        $responseHeaders = $http_response_header ?? [];
        $status = 200;
        $contentRange = null;
        $contentLength = null;
        $contentType = 'video/mp4';

        foreach ($responseHeaders as $h) {
            if (preg_match('#HTTP/[0-9\.]+\s+([0-9]+)#i', $h, $sm)) {
                $status = (int) $sm[1];
            } elseif (stripos($h, 'Content-Range:') === 0) {
                $contentRange = trim(substr($h, 14));
            } elseif (stripos($h, 'Content-Length:') === 0) {
                $contentLength = trim(substr($h, 15));
            } elseif (stripos($h, 'Content-Type:') === 0) {
                $contentType = trim(substr($h, 13));
            }
        }

        $outHeaders = [
            'Content-Type' => $contentType,
            'Accept-Ranges' => 'bytes',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];
        if ($contentLength !== null) $outHeaders['Content-Length'] = $contentLength;
        if ($contentRange !== null) $outHeaders['Content-Range'] = $contentRange;

        if ($request->isMethod('HEAD')) {
            @fclose($fp);
            return response('', $status, $outHeaders);
        }

        return new StreamedResponse(function () use ($fp) {
            while (ob_get_level() > 0) ob_end_clean();
            $chunkSize = 256 * 1024;
            while (!feof($fp) && !connection_aborted()) {
                $data = fread($fp, $chunkSize);
                if ($data === false || $data === '') break;
                echo $data;
                flush();
            }
            @fclose($fp);
        }, $status, $outHeaders);
    }

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
