<?php

namespace App\Http\Controllers;

use App\Models\DatabaseSetting;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseAdminController extends Controller
{
    protected DatabaseBackupService $backupService;

    public function __construct(DatabaseBackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Obtener ajustes de base de datos y estadísticas actuales del clúster.
     * GET /api/admin/database/settings
     */
    public function settings(): JsonResponse
    {
        try {
            $settings = DatabaseSetting::current();
            $stats = $this->backupService->getDatabaseStats();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'settings' => $settings,
                    'stats' => $stats,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('DatabaseAdminController::settings Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al recuperar la configuración de la base de datos: ' . $e->getMessage(),
                'data' => [
                    'settings' => DatabaseSetting::defaults(),
                    'stats' => $this->backupService->getDatabaseStats(),
                ],
            ], 500);
        }
    }

    /**
     * Actualizar configuración de rendimiento y políticas de respaldo.
     * POST /api/admin/database/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'db_cache_size_mb' => 'required|integer|min:16|max:16384',
                'optimize_on_shutdown' => 'required|boolean',
                'analysis_row_limit' => 'required|integer|min:10|max:100000',
                'vacuum_on_next_startup' => 'required|boolean',
                'auto_backup_enabled' => 'required|boolean',
                'auto_backup_frequency' => 'required|string|in:daily,weekly',
                'auto_backup_retention_days' => 'required|integer|min:1|max:365',
            ]);

            $setting = DatabaseSetting::current();
            $setting->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración de base de datos guardada correctamente.',
                'data' => $setting->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parámetros de configuración inválidos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('DatabaseAdminController::updateSettings Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al guardar la configuración: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar todos los respaldos existentes.
     * GET /api/admin/database/backups
     */
    public function backups(): JsonResponse
    {
        try {
            $backups = $this->backupService->getBackups();

            return response()->json([
                'status' => 'success',
                'data' => $backups,
                'total' => count($backups),
            ]);
        } catch (\Throwable $e) {
            Log::error('DatabaseAdminController::backups Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al listar las copias de seguridad: ' . $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * Crear un respaldo manual de inmediato.
     * POST /api/admin/database/backups/create
     */
    public function createBackup(Request $request): JsonResponse
    {
        try {
            $backup = $this->backupService->createBackup('manual');

            return response()->json([
                'status' => 'success',
                'message' => 'Copia de seguridad generada exitosamente.',
                'data' => $backup,
            ]);
        } catch (\Throwable $e) {
            Log::error('DatabaseAdminController::createBackup Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear la copia de seguridad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Descargar archivo de respaldo validando protección contra Path Traversal.
     * GET /api/admin/database/backups/download/{filename}
     */
    public function downloadBackup(string $filename): BinaryFileResponse|JsonResponse
    {
        try {
            return $this->backupService->downloadBackup($filename);
        } catch (\Throwable $e) {
            Log::error("DatabaseAdminController::downloadBackup Error ({$filename}): " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'No fue posible descargar el archivo: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Restaurar la base de datos a partir de una copia seleccionada.
     * POST /api/admin/database/backups/restore/{filename}
     */
    public function restoreBackup(string $filename): JsonResponse
    {
        try {
            $result = $this->backupService->restoreBackup($filename);

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error("DatabaseAdminController::restoreBackup Error ({$filename}): " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al restaurar la base de datos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar un archivo de respaldo.
     * DELETE /api/admin/database/backups/{filename}
     */
    public function deleteBackup(string $filename): JsonResponse
    {
        try {
            $deleted = $this->backupService->deleteBackup($filename);

            if ($deleted) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Copia de seguridad eliminada satisfactoriamente.',
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'El archivo no existe o no pudo ser eliminado.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error("DatabaseAdminController::deleteBackup Error ({$filename}): " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disparar la tarea de optimización y desfragmentación de tablas.
     * POST /api/admin/database/optimize
     */
    public function optimize(): JsonResponse
    {
        try {
            $result = $this->backupService->optimizeTables();

            return response()->json([
                'status' => 'success',
                'message' => "Optimización completada en {$result['total_tables']} tablas del sistema.",
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('DatabaseAdminController::optimize Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error durante el proceso de optimización: ' . $e->getMessage(),
            ], 500);
        }
    }
}
