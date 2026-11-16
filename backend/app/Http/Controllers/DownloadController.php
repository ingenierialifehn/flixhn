<?php

namespace App\Http\Controllers;

use App\Models\DownloadJob;
use App\Services\DownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DownloadController extends Controller
{
    protected DownloadService $downloadService;

    public function __construct(DownloadService $downloadService)
    {
        $this->downloadService = $downloadService;
    }

    /**
     * POST /api/download/request
     * Solicitar una nueva descarga controlada por FlixGrab.
     */
    public function requestDownload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_url' => 'nullable|string|max:1000',
            'url' => 'nullable|string|max:1000',
            'title' => 'nullable|string|max:255',
            'content_id' => 'nullable|string|max:100',
            'provider' => 'nullable|string|max:50',
            'priority' => 'nullable|integer|min:1|max:10',
            'quality' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetros de solicitud de descarga inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $job = $this->downloadService->requestDownload($request->all(), $request->user());

            $isExisting = $job->wasRecentlyCreated === false;
            $code = $isExisting ? 200 : 201;

            return response()->json([
                'success' => true,
                'message' => $isExisting 
                    ? "Trabajo ya existente para '{$job->title}' en estado {$job->status}."
                    : "Descarga iniciada con éxito para '{$job->title}'.",
                'job' => $job,
                'status' => $job->status,
                'can_play_progressive' => (bool) $job->can_play_progressive,
            ], $code);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 507); // 507 Insufficient Storage
        } catch (\Throwable $e) {
            Log::error('Error en DownloadController@requestDownload: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno al procesar la solicitud de descarga.',
            ], 500);
        }
    }

    /**
     * GET /api/download/{id}/status
     * Consultar el estado y métricas de un trabajo de descarga específico.
     */
    public function status(Request $request, $id): JsonResponse
    {
        $job = $this->downloadService->getJobStatus($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Trabajo de descarga no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'job' => $job,
            'status' => $job->status,
            'progress' => (float) $job->progress,
            'download_speed' => $job->download_speed,
            'eta' => $job->eta,
            'total_bytes' => $job->total_bytes,
            'downloaded_bytes' => $job->downloaded_bytes,
            'segments_ready' => $job->segments_ready,
            'can_play_progressive' => (bool) $job->can_play_progressive,
            'stream_url' => $job->can_play_progressive ? url($job->hls_playlist_url) : ($job->status === 'READY' ? url("/api/streaming/progressive/{$job->id}") : null),
        ]);
    }

    /**
     * POST /api/download/{id}/cancel
     * Cancelar un trabajo de descarga activo o pendiente.
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $job = $this->downloadService->cancelJob($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Trabajo de descarga no encontrado para cancelar.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "El trabajo de descarga '{$job->title}' ha sido cancelado.",
            'job' => $job,
        ]);
    }

    /**
     * GET /api/download/admin/jobs
     * Listado administrativo de tareas para el panel de control FlixGrab.
     */
    public function adminJobs(Request $request): JsonResponse
    {
        $query = DownloadJob::with(['user', 'mediaSource'])
            ->orderBy('created_at', 'desc');

        $status = $request->query('status');
        if ($status && $status !== 'all') {
            $query->where('status', strtoupper($status));
        }

        $search = $request->query('q');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('source_url', 'LIKE', "%{$search}%")
                  ->orWhere('content_id', 'LIKE', "%{$search}%");
            });
        }

        $jobs = $query->limit(100)->get();

        // Calcular métricas agregadas
        $diskInfo = $this->downloadService->checkDiskSpace();
        $activeCount = DownloadJob::whereIn('status', ['QUEUED', 'DOWNLOADING', 'PROCESSING'])->count();
        $completedCount = DownloadJob::where('status', 'READY')->count();
        $failedCount = DownloadJob::where('status', 'ERROR')->count();

        // Sumar velocidades activas
        $totalSpeed = '0.0 MB/s';
        $activeDownloads = DownloadJob::where('status', 'DOWNLOADING')->get();
        $speedSum = 0.0;
        foreach ($activeDownloads as $ad) {
            if (preg_match('/([\d.]+)\s*MB\/s/i', $ad->download_speed, $m)) {
                $speedSum += (float) $m[1];
            }
        }
        if ($speedSum > 0) {
            $totalSpeed = round($speedSum, 1) . ' MB/s';
        }

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
            'metrics' => [
                'active_tasks' => $activeCount,
                'completed_tasks' => $completedCount,
                'failed_tasks' => $failedCount,
                'total_speed' => $totalSpeed,
                'disk_free_formatted' => $diskInfo['free_formatted'],
                'disk_total_formatted' => $diskInfo['total_formatted'],
                'disk_free_mb' => $diskInfo['free_mb'],
                'disk_total_mb' => $diskInfo['total_mb'],
                'storage_path' => config('flixgrab.storage_path'),
                'max_concurrent' => config('flixgrab.max_concurrent_downloads', 3),
            ],
        ]);
    }

    /**
     * POST /api/download/admin/{id}/priority
     * Cambiar la prioridad de una descarga en cola.
     */
    public function updatePriority(Request $request, $id): JsonResponse
    {
        $job = is_numeric($id) ? DownloadJob::find($id) : DownloadJob::where('job_id', $id)->first();
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Trabajo no encontrado.'], 404);
        }

        $priority = max(1, min(10, (int) $request->input('priority', 5)));
        $job->update(['priority' => $priority]);

        return response()->json([
            'success' => true,
            'message' => "Prioridad actualizada a {$priority}.",
            'job' => $job,
        ]);
    }

    /**
     * POST /api/download/admin/{id}/retry
     * Reintentar un trabajo fallido o cancelado.
     */
    public function retryJob(Request $request, $id): JsonResponse
    {
        $job = is_numeric($id) ? DownloadJob::find($id) : DownloadJob::where('job_id', $id)->first();
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Trabajo no encontrado.'], 404);
        }

        $job->update([
            'status' => 'QUEUED',
            'progress' => 0,
            'error_message' => null,
            'retry_count' => $job->retry_count + 1,
            'started_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Trabajo '{$job->title}' re-encolado exitosamente.",
            'job' => $job,
        ]);
    }
}
