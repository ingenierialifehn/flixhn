<?php

namespace App\Http\Controllers;

use App\Models\MediaIngestTask;
use App\Services\MediaIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MediaIngestController extends Controller
{
    public function __construct(
        protected MediaIngestService $ingestService
    ) {}

    /**
     * GET /api/admin/ingest/tasks
     * Listado de descargas activas, en cola y completadas junto con métricas del servidor.
     */
    public function tasks(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $search = $request->query('q') ?? $request->query('search');

        $query = MediaIngestTask::with(['mediaItem', 'titleModel'])
            ->orderBy('id', 'desc');

        if (!empty($status) && $status !== 'all') {
            $query->where('status', $status);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('source_url', 'LIKE', "%{$search}%")
                  ->orWhere('output_file', 'LIKE', "%{$search}%");
            });
        }

        $tasks = $query->limit(150)->get();
        $metrics = $this->ingestService->getSystemMetrics();

        return response()->json([
            'status' => 'success',
            'tasks' => $tasks,
            'metrics' => $metrics,
            'total' => $tasks->count(),
        ]);
    }

    /**
     * POST /api/admin/ingest/tasks
     * Crear una nueva tarea de ingesta en segundo plano.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string',
            'target_folder' => 'nullable|string|max:500',
            'quality' => 'nullable|string|max:30',
            'auto_index' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'admin_password' => 'nullable|string',
        ]);

        // Si se envió confirmación de contraseña administrativa, validarla estrictamente
        if ($request->filled('admin_password')) {
            $user = $request->user();
            if ($user && !Hash::check($request->admin_password, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contraseña administrativa incorrecta. No se autorizó la tarea de ingesta.',
                ], 422);
            }
        }

        try {
            $task = $this->ingestService->createTask($request->all());

            return response()->json([
                'status' => 'success',
                'message' => "Tarea de ingesta '{$task->title}' programada e iniciada exitosamente.",
                'task' => $task,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error creando tarea de ingesta: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible iniciar la tarea de ingesta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ingest/tasks/{id}/cancel
     * Detiene el proceso y marca la tarea como cancelada.
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $task = $this->ingestService->cancelTask($id);

            return response()->json([
                'status' => 'success',
                'message' => "Descarga de '{$task->title}' detenida y cancelada.",
                'task' => $task,
            ]);
        } catch (\Throwable $e) {
            Log::error("Error cancelando tarea de ingesta #{$id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible cancelar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ingest/tasks/{id}/retry
     * Reinicia una descarga fallida o cancelada.
     */
    public function retry(int $id): JsonResponse
    {
        try {
            $task = $this->ingestService->retryTask($id);

            return response()->json([
                'status' => 'success',
                'message' => "Reiniciando proceso de ingesta para '{$task->title}'...",
                'task' => $task,
            ]);
        } catch (\Throwable $e) {
            Log::error("Error reintentando tarea de ingesta #{$id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible reintentar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/ingest/tasks/{id}
     * Elimina la tarea del historial y purga temporales.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->ingestService->deleteTask($id, true);

            return response()->json([
                'status' => 'success',
                'message' => 'Tarea eliminada del historial y temporales purgados.',
            ]);
        } catch (\Throwable $e) {
            Log::error("Error eliminando tarea de ingesta #{$id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible eliminar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/ingest/metrics
     * Métricas de rendimiento, velocidad total y disco en tiempo real.
     */
    public function metrics(): JsonResponse
    {
        $metrics = $this->ingestService->getSystemMetrics();

        return response()->json([
            'status' => 'success',
            'metrics' => $metrics,
        ]);
    }
}
