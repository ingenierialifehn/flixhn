<?php

namespace App\Http\Controllers;

use App\Models\DownloadJob;
use App\Models\Episode;
use App\Models\MediaItem;
use App\Models\Title;
use App\Services\DownloadService;
use App\Services\MediaIntegrityService;
use App\Services\RealMediaSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamingController extends Controller
{
    protected DownloadService $downloadService;
    protected MediaIntegrityService $integrityService;
    protected RealMediaSourceResolver $sourceResolver;

    public function __construct(
        DownloadService $downloadService,
        MediaIntegrityService $integrityService,
        RealMediaSourceResolver $sourceResolver
    ) {
        $this->downloadService = $downloadService;
        $this->integrityService = $integrityService;
        $this->sourceResolver = $sourceResolver;
    }

    /**
     * GET /api/media/{id}/play
     */
    public function play(Request $request, $id)
    {
        $cleanId = trim((string) $id);

        // 1. Verificar si es un Título local existente
        $title = is_numeric($cleanId) ? Title::find($cleanId) : Title::where('slug', $cleanId)->first();
        if ($title) {
            $rawPath = $title->file_path ?: $title->source_path;
            $safePath = $this->resolveBrowserSafeMediaFile($rawPath, $title->name);
            if ($safePath && File::exists($safePath) && File::size($safePath) > 2 * 1024 * 1024) {
                return response()->json([
                    'success' => true,
                    'status' => 'LOCAL',
                    'title' => $title->name,
                    'media_id' => $title->id,
                    'mode' => 'direct',
                    'stream_url' => url("/api/stream/title/{$title->id}"),
                    'is_progressive' => false,
                    'duration_seconds' => $title->duration_seconds,
                ]);
            }
        }

        // 2. Verificar si es un Episodio local
        $episode = is_numeric($cleanId) ? Episode::find($cleanId) : null;
        if ($episode) {
            $epPath = $episode->file_path ?: $episode->stream_path;
            $parentTitle = Title::find($episode->title_id);
            $safeEpPath = $this->resolveBrowserSafeMediaFile($epPath, $parentTitle?->name ?: $episode->title);
            if ($safeEpPath && File::exists($safeEpPath) && File::size($safeEpPath) > 2 * 1024 * 1024) {
                return response()->json([
                    'success' => true,
                    'status' => 'LOCAL',
                    'title' => $episode->title,
                    'episode_id' => $episode->id,
                    'mode' => 'direct',
                    'stream_url' => url("/api/stream/episode/{$episode->id}"),
                    'is_progressive' => false,
                    'duration_seconds' => $episode->duration_seconds,
                ]);
            }
        }

        // 3. Verificar si existe un DownloadJob (Activo o Completado)
        $job = DownloadJob::where('id', is_numeric($cleanId) ? (int) $cleanId : -1)
            ->orWhere('job_id', $cleanId)
            ->orWhere('content_id', $cleanId)
            ->when($title, fn($q) => $q->orWhere('title_id', $title->id)->orWhere('title', $title->name))
            ->latest()
            ->first();

        if ($job) {
            $this->sourceResolver->purgeIfWrongFile($job->storage_path, $job->title, $job);
            $this->downloadService->syncHlsProgressiveAvailability($job);
            $job->refresh();

            $progressiveUrl = url("/api/streaming/progressive/{$job->id}");
            $transcodeUrl = url("/api/streaming/progressive/{$job->id}?transcode=1");
            $playlistUrl = url($job->hls_playlist_url ?: "/api/streaming/hls/{$job->job_id}/playlist.m3u8");

            if ($job->status === 'READY') {
                return response()->json([
                    'success' => true,
                    'status' => 'READY',
                    'title' => $job->title,
                    'job_id' => $job->job_id,
                    'download_job_id' => $job->id,
                    'mode' => 'direct',
                    'stream_url' => $progressiveUrl,
                    'direct_url' => $progressiveUrl,
                    'progressive_url' => $progressiveUrl,
                    'transcode_url' => $transcodeUrl,
                    'hls_playlist_url' => $playlistUrl,
                    'is_progressive' => false,
                    'duration_seconds' => $job->duration_seconds,
                ]);
            }

            $this->sourceResolver->ensureBackgroundDownloadRunning($job);

            return response()->json([
                'success' => true,
                'status' => 'DOWNLOADING_PROGRESSIVE_READY',
                'title' => $job->title,
                'job_id' => $job->job_id,
                'download_job_id' => $job->id,
                'mode' => 'direct',
                'stream_url' => $progressiveUrl,
                'direct_url' => $progressiveUrl,
                'progressive_url' => $progressiveUrl,
                'transcode_url' => $transcodeUrl,
                'hls_playlist_url' => $playlistUrl,
                'is_progressive' => true,
                'can_play_progressive' => true,
                'progress' => (float) $job->progress,
                'download_speed' => $job->download_speed,
                'segments_ready' => $job->segments_ready,
                'eta' => $job->eta,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El contenido solicitado no está disponible en el servidor ni en descarga activa.',
        ], 404);
    }

    /**
     * GET /api/streaming/hls/{jobId}/{file}
     */
    public function serveHls(Request $request, string $jobId, string $file)
    {
        session_write_close();

        $file = basename($file);
        $jobId = basename($jobId);

        $hlsBase = config('flixgrab.hls_storage_path', storage_path('media/hls_live'));
        $jobFolder = rtrim($hlsBase, '/\\') . DIRECTORY_SEPARATOR . $jobId;
        $targetFile = $jobFolder . DIRECTORY_SEPARATOR . $file;

        if (!File::exists($targetFile)) {
            $job = DownloadJob::where('job_id', $jobId)
                ->orWhere('id', is_numeric($jobId) ? (int) $jobId : -1)
                ->orWhere('hls_path', 'LIKE', "%{$jobId}%")
                ->orWhere('hls_playlist_url', 'LIKE', "%{$jobId}%")
                ->first();
            if ($job && !empty($job->hls_path)) {
                $sub = preg_replace('#^.*?storage/media/hls_live/#', '', str_replace('\\', '/', $job->hls_path));
                $mapped = rtrim(storage_path('media/hls_live'), '/\\') . '/' . ltrim($sub, '/\\') . '/' . $file;
                if (File::exists($mapped)) {
                    $targetFile = $mapped;
                } else {
                    $customPath = rtrim($job->hls_path, '/\\') . DIRECTORY_SEPARATOR . $file;
                    if (File::exists($customPath)) {
                        $targetFile = $customPath;
                    }
                }
            }
        }

        if (!File::exists($targetFile)) {
            return response()->json(['error' => 'Segmento HLS no disponible aún.'], 404);
        }

        $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        if ($ext === 'm3u8') {
            return response(File::get($targetFile), 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            ]);
        }

        if ($ext === 'ts' || $ext === 'm4s') {
            return response()->file($targetFile, [
                'Content-Type' => $ext === 'm4s' ? 'video/iso.segment' : 'video/mp2t',
                'Cache-Control' => 'public, max-age=31536000',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return response()->file($targetFile, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * GET /api/streaming/progressive/{jobId}
     * Si el archivo real ya terminó de descargarse, lo sirve por HTTP 206 Partial Content.
     * Mientras está en descarga (0% - 99%), garantiza la descarga en segundo plano y transmite
     * en vivo el stream real del título solicitado mediante FFmpeg.
     */
    public function serveProgressive(Request $request, $jobId)
    {
        session_write_close();
        set_time_limit(0);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
                'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        $cleanJobId = trim((string) $jobId);
        $job = is_numeric($cleanJobId)
            ? DownloadJob::find((int) $cleanJobId)
            : DownloadJob::where('job_id', $cleanJobId)
                ->orWhere('content_id', $cleanJobId)
                ->orWhere('storage_path', 'LIKE', "%{$cleanJobId}%")
                ->latest()
                ->first();

        $filePath = $job?->storage_path;

        if ($filePath && !File::exists($filePath)) {
            $sub = preg_replace('#^.*?storage/media/#', '', str_replace('\\', '/', $filePath));
            $mapped = rtrim(storage_path('media'), '/\\') . '/' . ltrim($sub, '/\\');
            if (File::exists($mapped)) {
                $filePath = $mapped;
            }
        }

        // Purgar cualquier reemplazo incorrecto anterior (como 'Hasta que la boda nos separe' en 'plata nos separe')
        $filePath = $this->resolveBrowserSafeMediaFile($filePath, $job?->title, $job);

        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
            'Access-Control-Expose-Headers' => 'Content-Range, Content-Length, Accept-Ranges',
            'Cache-Control' => 'no-cache, private',
        ];

        // 1. Si el archivo real del título ya está completamente descargado en disco, servirlo con soporte HTTP 206 Range
        if ($filePath && File::exists($filePath) && !File::isDirectory($filePath) && @File::size($filePath) > 2 * 1024 * 1024) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($request->boolean('transcode') || in_array($ext, ['avi', 'mkv'], true)) {
                return $this->streamTranscodedMp4($request, $filePath);
            }
            return response()->file($filePath, $headers);
        }

        // 2. Si el archivo aún se está descargando o preparando, iniciar el descargador en segundo plano y transmitir el video REAL en vivo
        if ($job) {
            $this->sourceResolver->ensureBackgroundDownloadRunning($job);
            $mediaType = $job->metadata['type'] ?? null;
            $resolved = $this->sourceResolver->resolveRealSource($job->title, $mediaType, $job->source_url);

            if ($resolved) {
                if (($resolved['mode'] ?? '') === 'local_file' && !empty($resolved['file_path']) && File::exists($resolved['file_path'])) {
                    return response()->file($resolved['file_path'], $headers);
                }
                return $this->sourceResolver->streamLiveToBrowser($request, $resolved);
            }
        }

        return response()->json(['error' => 'Preparando transmisión del contenido real...'], 404);
    }

    /**
     * Verifica que el archivo físico pertenezca realmente al título solicitado y no sea uno de los
     * reemplazos o muestras antiguas incompatibles. NUNCA sustituye por una película distinta.
     */
    public function resolveBrowserSafeMediaFile(?string $filePath, ?string $titleName = null, ?DownloadJob $job = null): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        if ($this->sourceResolver->purgeIfWrongFile($filePath, $titleName, $job)) {
            return null;
        }

        if (File::exists($filePath) && !File::isDirectory($filePath) && @File::size($filePath) > 512 * 1024) {
            return $filePath;
        }

        return null;
    }

    /**
     * Transcodificación universal al vuelo con FFmpeg hacia MP4 fragmentado (H.264 par + AAC Estéreo 2.0).
     */
    public function streamTranscodedMp4(Request $request, string $filePath)
    {
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'none',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        return new StreamedResponse(function () use ($filePath) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $escapedPath = escapeshellarg($filePath);
            $cmd = "ffmpeg -hide_banner -loglevel error -i {$escapedPath} -vf \"scale=trunc(iw/2)*2:trunc(ih/2)*2\" -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -ac 2 -ar 48000 -b:a 128k -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                return;
            }

            @fclose($pipes[0]);
            @fclose($pipes[2]);

            $stdout = $pipes[1];
            $chunkSize = 64 * 1024;

            while (!feof($stdout) && !connection_aborted()) {
                $buffer = fread($stdout, $chunkSize);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                echo $buffer;
                flush();
            }

            @fclose($stdout);
            @proc_terminate($process);
            @proc_close($process);
        }, 200, $headers);
    }
}
