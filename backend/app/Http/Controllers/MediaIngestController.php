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
        // Asegurar sincronización de tareas existentes de proveedores OTT
        $this->ingestService->syncLegacyTasksIfEmpty();

        $status = $request->query('status');
        $search = $request->query('q') ?? $request->query('search');

        $query = MediaIngestTask::with(['mediaItem', 'titleModel'])
            ->orderBy('updated_at', 'desc');

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
     * Busca la tarea, si tiene media_item_id y archivo físico los elimina, y ejecuta delete().
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $task = MediaIngestTask::find($id);
            if (!$task) {
                return response()->json([
                    'success' => true,
                    'status' => 'success',
                    'message' => 'Tarea ya eliminada o inexistente.',
                ]);
            }

            // Si tiene un media_item_id asociado y el archivo físico existe, elimina el registro y el archivo
            if ($task->media_item_id) {
                $mediaItem = \App\Models\MediaItem::find($task->media_item_id);
                if ($mediaItem) {
                    if (!empty($mediaItem->file_path) && file_exists($mediaItem->file_path) && !str_contains($mediaItem->file_path, 'samples/sample.mp4')) {
                        @unlink($mediaItem->file_path);
                    }
                    $mediaItem->delete();
                }
            }

            // Si tiene output_file y el archivo físico existe
            if (!empty($task->output_file) && file_exists($task->output_file) && !str_contains($task->output_file, 'samples/sample.mp4')) {
                @unlink($task->output_file);
            }

            // Ejecuta $task->delete(); en la base de datos MySQL
            $task->delete();

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Tarea eliminada',
            ]);
        } catch (\Throwable $e) {
            Log::error("Error eliminando tarea de ingesta #{$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'No fue posible eliminar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/ingest/tasks/delete-all
     * Ejecuta MediaIngestTask::truncate(); y limpia archivos temporales asociados.
     */
    public function deleteAll(): JsonResponse
    {
        try {
            $tasks = MediaIngestTask::all();
            foreach ($tasks as $task) {
                if (!empty($task->output_file) && file_exists($task->output_file) && !str_contains($task->output_file, 'samples/sample.mp4')) {
                    @unlink($task->output_file);
                }
                if ($task->media_item_id) {
                    $mediaItem = \App\Models\MediaItem::find($task->media_item_id);
                    if ($mediaItem) {
                        if (!empty($mediaItem->file_path) && file_exists($mediaItem->file_path) && !str_contains($mediaItem->file_path, 'samples/sample.mp4')) {
                            @unlink($mediaItem->file_path);
                        }
                        $mediaItem->delete();
                    }
                }
            }

            // Ejecuta MediaIngestTask::truncate();
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            MediaIngestTask::truncate();
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Todas las tareas han sido eliminadas',
            ]);
        } catch (\Throwable $e) {
            Log::error("Error eliminando todas las tareas de ingesta: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'No fue posible eliminar las tareas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST/DELETE /api/admin/ingest/tasks/clear-completed
     * Limpia todas las tareas completadas o al 100% de la base de datos física.
     */
    public function clearCompleted(): JsonResponse
    {
        try {
            $deletedIngest = MediaIngestTask::where('status', 'completed')
                ->orWhere('progress', '>=', 100)
                ->delete();

            $deletedOtt = \App\Models\OttDownloadTask::where('status', 'completed')
                ->orWhere('progress', '>=', 100)
                ->delete();

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('ott_contents')) {
                    \App\Models\OttContent::where('download_status', 'completed')->delete();
                }
            } catch (\Throwable $e) {}

            \Illuminate\Support\Facades\Cache::forget('ott_tasks_overview');
            \Illuminate\Support\Facades\Cache::forget('ott_downloading_tasks');

            $totalDeleted = $deletedIngest + $deletedOtt;

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => "Se limpiaron {$totalDeleted} tareas completadas de la base de datos.",
                'cleared_count' => $totalDeleted,
            ]);
        } catch (\Throwable $e) {
            Log::error("Error limpiando tareas completadas en MediaIngestController: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Error al limpiar tareas: ' . $e->getMessage(),
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

