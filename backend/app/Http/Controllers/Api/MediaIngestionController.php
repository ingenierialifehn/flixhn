<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MediaIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MediaIngestionController extends Controller
{
    public function __construct(
        protected MediaIngestionService $ingestionService
    ) {}

    /**
     * POST /api/admin/ingestion/enqueue
     * Inicia una tarea de ingesta multimedia en segundo plano.
     */
    public function enqueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_type' => 'required|string|in:movie,series',
            'title' => 'required|string|min:1|max:255',
            'source_url' => 'required|string|max:2048',
            'target_disk' => 'required|string|max:255',
            'target_subfolder' => 'nullable|string|max:255',
            'imdb_id' => ['nullable', 'string', 'max:32', 'regex:/^tt\d{5,12}$/i'],
            'tmdb_id' => 'nullable|string|max:32',
            'release_year' => 'nullable|integer|min:1900|max:2100',
            'overview' => 'nullable|string|max:4000',
            'season_number' => 'nullable|integer|min:0|max:999',
            'episode_number' => 'nullable|integer|min:1|max:9999',
            'episode_title' => 'nullable|string|max:255',
            'quality' => 'nullable|string|max:30',
        ], [
            'imdb_id.regex' => 'El ID de IMDb debe tener el formato válido (ejemplo: tt1375666).',
        ]);

        try {
            $task = $this->ingestionService->enqueue($validated, $request->user()?->id);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => "Ingesta encolada para '{$task->title}' en {$task->target_disk}.",
                'task' => $task,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('MediaIngestionController::enqueue error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'No fue posible iniciar la tarea de ingesta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/ingestion/status
     * Devuelve tareas activas e historial, estado de discos /mnt/media_library y herramientas disponibles.
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $statusFilter = $request->query('status');
            $search = $request->query('q') ?? $request->query('search');

            $overview = $this->ingestionService->getStatusOverview(
                is_string($statusFilter) ? $statusFilter : null,
                is_string($search) ? $search : null
            );

            return response()->json(array_merge([
                'status' => 'success',
                'success' => true,
            ], $overview));
        } catch (\Throwable $e) {
            Log::error('MediaIngestionController::status error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Error consultando el estado de ingesta: ' . $e->getMessage(),
                'tasks' => [],
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/ingestion/{id}
     * Cancela una descarga en curso o limpia una tarea finalizada del historial.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->ingestionService->cancelOrDelete($id);

            return response()->json(array_merge([
                'status' => 'success',
                'success' => true,
            ], $result));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => "La tarea de ingesta #{$id} no existe.",
            ], 404);
        } catch (\Throwable $e) {
            Log::error("MediaIngestionController::destroy #{$id} error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'No fue posible procesar la solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }
}