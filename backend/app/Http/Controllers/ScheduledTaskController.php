<?php

namespace App\Http\Controllers;

use App\Models\ScheduledTask;
use App\Services\ScheduledTaskRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledTaskController extends Controller
{
    public function __construct(
        protected ScheduledTaskRunnerService $runnerService
    ) {}

    /**
     * Listar todas las tareas programadas agrupadas por categoría y estado actual.
     * GET /api/admin/tasks
     */
    public function index(): JsonResponse
    {
        // Auto-población de seguridad si la tabla está vacía
        if (ScheduledTask::count() === 0) {
            foreach (ScheduledTask::defaultTasks() as $defaultTask) {
                ScheduledTask::create($defaultTask);
            }
        }

        $allTasks = ScheduledTask::orderBy('id')->get();
        $categoriesMeta = ScheduledTask::categories();

        $grouped = [];
        foreach ($categoriesMeta as $catKey => $catInfo) {
            $grouped[$catKey] = [
                'meta' => $catInfo,
                'tasks' => [],
            ];
        }

        $formattedList = [];
        $summary = [
            'total' => $allTasks->count(),
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'idle' => 0,
        ];

        foreach ($allTasks as $task) {
            $formatted = $this->runnerService->formatTask($task);
            $formattedList[] = $formatted;

            $cat = $task->category;
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'meta' => [
                        'name' => ucfirst($cat),
                        'label' => ucfirst($cat),
                        'description' => 'Tareas del módulo ' . $cat,
                        'icon' => 'Server',
                    ],
                    'tasks' => [],
                ];
            }
            $grouped[$cat]['tasks'][] = $formatted;

            if (isset($summary[$task->status])) {
                $summary[$task->status]++;
            } else {
                $summary['idle']++;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'tasks' => $formattedList,
                'grouped' => $grouped,
                'categories' => $categoriesMeta,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Iniciar la ejecución manual de una tarea programada bajo demanda.
     * POST /api/admin/tasks/{key}/run
     */
    public function run(Request $request, string $key): JsonResponse
    {
        $result = $this->runnerService->runTask($key);

        $httpCode = $result['status'] === 'success' ? 200 : 422;

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'data' => $result['task'],
        ], $httpCode);
    }

    /**
     * Consultar el estado en tiempo real de una tarea específica.
     * GET /api/admin/tasks/{key}/status
     */
    public function status(string $key): JsonResponse
    {
        $task = ScheduledTask::where('key', $key)->first();

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => "Tarea '{$key}' no encontrada.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->runnerService->formatTask($task),
        ]);
    }
}
