<?php

namespace App\Http\Controllers;

use App\Models\DownloadJob;
use App\Models\Episode;
use App\Models\MediaItem;
use App\Models\Title;
use App\Services\DownloadService;
use App\Services\MediaIntegrityService;
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

    public function __construct(
        DownloadService $downloadService,
        MediaIntegrityService $integrityService
    ) {
        $this->downloadService = $downloadService;
        $this->integrityService = $integrityService;
    }

    /**
     * GET /api/media/{id}/play
     * Resolver inteligente de reproducción:
     * - Si está en disco local -> Stream directo inmediato.
     * - Si está en descarga con segmentos listos -> Streaming progresivo HLS en caliente.
     * - Si está en descarga inicial -> 202 Accepted informando progreso y espera de buffer.
     */
    public function play(Request $request, $id)
    {
        $cleanId = trim((string)$id);

        // 1. Verificar si es un Título local existente
        $title = Title::find($cleanId) ?: Title::where('slug', $cleanId)->first();
        if ($title) {
            $rawPath = $title->file_path ?: $title->source_path;
            if ($rawPath && File::exists($rawPath) && File::size($rawPath) > 1024) {
                return response()->json([
                    'success' => true,
                    'status' => 'LOCAL',
                    'title' => $title->name,
                    'media_id' => $title->id,
                    'mode' => str_ends_with(strtolower($rawPath), '.m3u8') ? 'hls' : 'direct',
                    'stream_url' => url("/api/stream/title/{$title->id}"),
                    'is_progressive' => false,
                    'duration_seconds' => $title->duration_seconds,
                ]);
            }
        }

        // 2. Verificar si es un Episodio local
        $episode = Episode::find($cleanId);
        if ($episode) {
            $epPath = $episode->file_path ?: $episode->stream_path;
            if ($epPath && File::exists($epPath) && File::size($epPath) > 1024) {
                return response()->json([
                    'success' => true,
                    'status' => 'LOCAL',
                    'title' => $episode->title,
                    'episode_id' => $episode->id,
                    'mode' => str_ends_with(strtolower($epPath), '.m3u8') ? 'hls' : 'direct',
                    'stream_url' => url("/api/stream/episode/{$episode->id}"),
                    'is_progressive' => false,
                    'duration_seconds' => $episode->duration_seconds,
                ]);
            }
        }

        // 3. Verificar si existe un DownloadJob (Activo o Completado)
        $job = DownloadJob::where('id', $cleanId)
            ->orWhere('job_id', $cleanId)
            ->orWhere('content_id', $cleanId)
            ->orWhere('title_id', $title?->id)
            ->latest()
            ->first();

        if ($job) {
            $this->downloadService->syncHlsProgressiveAvailability($job);

            // Si ya terminó completamente la descarga
            if ($job->status === 'READY') {
                return response()->json([
                    'success' => true,
                    'status' => 'READY',
                    'title' => $job->title,
                    'job_id' => $job->job_id,
                    'mode' => 'direct',
                    'stream_url' => url("/api/streaming/progressive/{$job->id}"),
                    'is_progressive' => false,
                    'duration_seconds' => $job->duration_seconds,
                ]);
            }

            // Si está descargando y ya tiene suficientes segmentos HLS para reproducir en caliente
            if ($job->can_play_progressive && $job->segments_ready >= (int) config('flixgrab.min_segments_for_playback', 1)) {
                $playlistUrl = url("/api/streaming/hls/{$job->job_id}/playlist.m3u8");
                return response()->json([
                    'success' => true,
                    'status' => 'DOWNLOADING_PROGRESSIVE_READY',
                    'title' => $job->title,
                    'job_id' => $job->job_id,
                    'mode' => 'hls',
                    'stream_url' => $playlistUrl,
                    'is_progressive' => true,
                    'progress' => (float) $job->progress,
                    'download_speed' => $job->download_speed,
                    'segments_ready' => $job->segments_ready,
                    'eta' => $job->eta,
                ]);
            }

            // Si aún no hay suficientes segmentos en el búfer
            return response()->json([
                'success' => false,
                'status' => $job->status,
                'title' => $job->title,
                'job_id' => $job->job_id,
                'message' => 'Descarga en proceso. Preparando segmentos para reproducción progresiva...',
                'can_play_progressive' => false,
                'progress' => (float) $job->progress,
                'download_speed' => $job->download_speed,
                'segments_ready' => $job->segments_ready,
                'required_segments' => (int) config('flixgrab.min_segments_for_playback', 1),
                'eta' => $job->eta,
            ], 202);
        }

        // Si no existe
        return response()->json([
            'success' => false,
            'message' => 'El contenido solicitado no está disponible en el servidor ni en descarga activa.',
        ], 404);
    }

    /**
     * GET /api/streaming/hls/{jobId}/{file}
     * Sirve el manifiesto HLS dinámico y los segmentos .ts con CORS y control estricto de caché.
     */
    public function serveHls(Request $request, string $jobId, string $file)
    {
        session_write_close();

        // Prevención de directory traversal
        $file = basename($file);
        $jobId = basename($jobId);

        $hlsBase = config('flixgrab.hls_storage_path', storage_path('media/hls_live'));
        $jobFolder = rtrim($hlsBase, '/\\') . DIRECTORY_SEPARATOR . $jobId;
        $targetFile = $jobFolder . DIRECTORY_SEPARATOR . $file;

        // Si no existe con ese ID, buscar si el trabajo tiene una ruta personalizada o coincide con el slug
        if (!File::exists($targetFile)) {
            $job = DownloadJob::where('job_id', $jobId)
                ->orWhere('id', $jobId)
                ->orWhere('hls_path', 'LIKE', "%{$jobId}%")
                ->orWhere('hls_playlist_url', 'LIKE', "%{$jobId}%")
                ->first();
            if ($job && !empty($job->hls_path)) {
                $customPath = rtrim($job->hls_path, '/\\') . DIRECTORY_SEPARATOR . $file;
                if (File::exists($customPath)) {
                    $targetFile = $customPath;
                }
            }
        }

        if (!File::exists($targetFile)) {
            return response()->json(['error' => 'Segmento HLS no disponible aún.'], 404);
        }

        $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        if ($ext === 'm3u8') {
            // Manifiesto: NUNCA almacenar en caché en vivo para permitir agregar nuevos segmentos
            return response(File::get($targetFile), 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            ]);
        }

        if ($ext === 'ts' || $ext === 'm4s') {
            // Segmento TS inmutable: caché prolongada y soporte byte range
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
     * Streaming HTTP 206 Partial Content para archivos MP4 progresivos (fMP4) o completados.
     */
    public function serveProgressive(Request $request, $jobId)
    {
        session_write_close();
        set_time_limit(0);

        $job = is_numeric($jobId) ? DownloadJob::find($jobId) : DownloadJob::where('job_id', $jobId)->first();
        if (!$job) {
            return response()->json(['error' => 'Trabajo no encontrado.'], 404);
        }

        $filePath = $job->storage_path;
        if (!File::exists($filePath)) {
            // Verificar si aún está en .part
            $partFile = $filePath . '.part';
            if (File::exists($partFile) && File::size($partFile) > 2 * 1024 * 1024) {
                $filePath = $partFile;
            } else {
                return response()->json(['error' => 'Archivo de video no disponible en disco.'], 404);
            }
        }

        $fileSize = File::size($filePath);
        $fp = @fopen($filePath, 'rb');
        if (!$fp) {
            return response()->json(['error' => 'No es posible abrir el archivo multimedia.'], 500);
        }

        $start = 0;
        $end = $fileSize - 1;
        $status = 200;
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => 'Content-Range, Content-Length, Accept-Ranges',
        ];

        if ($request->server('HTTP_RANGE')) {
            $range = $request->server('HTTP_RANGE');
            if (preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $range, $matches)) {
                $start = (float) $matches[1];
                if (!empty($matches[2])) {
                    $end = (float) $matches[2];
                }
                $status = 206;
                $headers['Content-Range'] = sprintf('bytes %.0f-%.0f/%.0f', $start, $end, $fileSize);
            }
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        fseek($fp, $start);

        return new StreamedResponse(function () use ($fp, $length) {
            $buffer = 256 * 1024; // 256 KB
            $bytesSent = 0;
            while (!feof($fp) && $bytesSent < $length && connection_status() === CONNECTION_NORMAL) {
                $readLength = min($buffer, $length - $bytesSent);
                $data = fread($fp, $readLength);
                echo $data;
                flush();
                $bytesSent += strlen($data);
            }
            fclose($fp);
        }, $status, $headers);
    }
}
