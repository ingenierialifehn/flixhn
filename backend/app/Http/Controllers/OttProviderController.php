<?php

namespace App\Http\Controllers;

use App\Jobs\SyncOttCatalogJob;
use App\Jobs\SyncOttProviderJob;
use App\Models\OttProviderAccount;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OttProviderController extends Controller
{
    protected OttSyncService $ottService;

    public function __construct(OttSyncService $ottService)
    {
        $this->ottService = $ottService;
    }

    /**
     * GET /api/admin/ott-providers
     * Lista los proveedores configurados, su estado de sesión y telemetría de medios.
     * Si no hay cuentas configuradas en ott_accounts / ott_provider_accounts,
     * devuelve los proveedores con status = 'disconnected'.
     */
    public function index(): JsonResponse
    {
        try {
            $providers = $this->ottService->getProvidersOverview();

            return response()->json([
                'success' => true,
                'providers' => $providers,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error consultando proveedores OTT: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la lista de proveedores OTT: ' . $e->getMessage(),
                'providers' => [],
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/connect
     * POST /api/admin/ott-providers/{provider}/connect
     * Valida, cifra y almacena de forma segura las credenciales/cookies/token de sesión.
     * Garantiza la creación física automática de la ruta destino con permisos 0777.
     */
    public function connect(Request $request, ?string $provider = null): JsonResponse
    {
        $targetProvider = $provider ?: $request->input('provider');

        if (empty($targetProvider)) {
            return response()->json([
                'success' => false,
                'message' => 'El identificador de plataforma/proveedor es requerido.',
            ], 422);
        }

        // Si se envió un ID numérico
        if (is_numeric($targetProvider)) {
            $acc = OttProviderAccount::find((int) $targetProvider);
            if ($acc) {
                $targetProvider = $acc->provider;
            }
        }

        $request->validate([
            'auth_method' => 'nullable|string|in:credentials,cookies,token',
            'account_name' => 'nullable|string|max:150',
            'display_name' => 'nullable|string|max:150',
            'email' => 'nullable|string|max:150',
            'password' => 'nullable|string|max:255',
            'cookies' => 'nullable',
            'token' => 'nullable|string',
            'api_token' => 'nullable|string',
            'quality_profile' => 'nullable|string|in:720p,1080p,4K HDR',
            'storage_path' => 'nullable|string|max:500',
            'target_folder' => 'nullable|string|max:500',
            'auto_download' => 'nullable|boolean',
        ]);

        try {
            // 1. Cifrado seguro de credenciales (password, cookies, token)
            // Verificar si la APP_KEY está configurada correctamente; si falla, manejar fallback seguro:
            $encryptedPassword = null;
            if (!empty($request->password)) {
                try {
                    $encryptedPassword = Crypt::encryptString($request->password);
                } catch (\Illuminate\Contracts\Encryption\DecryptException | \Exception $e) {
                    // Fallback si la clave APP_KEY no tiene longitud adecuada:
                    $cipherKey = substr(hash('sha256', config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!'), 0, 32);
                    $iv = openssl_random_pseudo_bytes(16);
                    $encryptedPassword = base64_encode($iv . openssl_encrypt($request->password, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv));
                }
            }

            $encryptedCookies = null;
            if (!empty($request->cookies)) {
                $cookieStr = is_string($request->cookies) ? trim($request->cookies) : json_encode($request->cookies);
                try {
                    $encryptedCookies = Crypt::encryptString($cookieStr);
                } catch (\Illuminate\Contracts\Encryption\DecryptException | \Exception $e) {
                    $cipherKey = substr(hash('sha256', config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!'), 0, 32);
                    $iv = openssl_random_pseudo_bytes(16);
                    $encryptedCookies = base64_encode($iv . openssl_encrypt($cookieStr, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv));
                }
            }

            $encryptedToken = null;
            $tokenInput = $request->input('token') ?: $request->input('api_token');
            if (!empty($tokenInput)) {
                $tokenStr = trim($tokenInput);
                try {
                    $encryptedToken = Crypt::encryptString($tokenStr);
                } catch (\Illuminate\Contracts\Encryption\DecryptException | \Exception $e) {
                    $cipherKey = substr(hash('sha256', config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!'), 0, 32);
                    $iv = openssl_random_pseudo_bytes(16);
                    $encryptedToken = base64_encode($iv . openssl_encrypt($tokenStr, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv));
                }
            }

            // 2. Creación Automática de Carpetas con Lectura y Escritura:
            $targetFolder = $request->input('target_folder') ?: $request->input('storage_path');
            $fullTargetPath = OttSyncService::resolveAndEnsureTargetFolder($targetFolder, $targetProvider);

            if (!File::isDirectory($fullTargetPath)) {
                File::makeDirectory($fullTargetPath, 0777, true, true);
            }
            // Asegurar permisos de lectura y escritura completos
            @chmod($fullTargetPath, 0777);

            // Preparar payload con rutas validadas y credenciales protegidas
            $payload = $request->all();
            $payload['storage_path'] = $fullTargetPath;
            $payload['target_folder'] = $fullTargetPath;
            if ($encryptedPassword !== null) {
                $payload['encrypted_password'] = $encryptedPassword;
            }
            if ($encryptedCookies !== null) {
                $payload['encrypted_cookies'] = $encryptedCookies;
            }
            if ($encryptedToken !== null) {
                $payload['encrypted_token'] = $encryptedToken;
            }

            $account = $this->ottService->connectAccount($targetProvider, $payload);

            return response()->json([
                'success' => true,
                'message' => "Cuenta de {$targetProvider} conectada exitosamente.",
                'account' => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'account_name' => $account->account_name,
                    'status' => $account->status,
                    'quality_profile' => $account->quality_profile,
                    'auto_download' => (bool) $account->auto_download,
                    'storage_path' => $fullTargetPath,
                    'target_folder' => $fullTargetPath,
                    'last_sync_at' => $account->last_sync_at ? $account->last_sync_at->toIso8601String() : null,
                    'has_credentials' => true,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error("Error al conectar proveedor OTT [{$targetProvider}]: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No fue posible conectar la cuenta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/{provider}/disconnect
     * Desconecta la cuenta y purga credenciales de sesión de forma segura.
     */
    public function disconnect(string $provider): JsonResponse
    {
        try {
            $targetProvider = $provider;
            if (is_numeric($provider)) {
                $acc = OttProviderAccount::find((int) $provider);
                if ($acc) {
                    $targetProvider = $acc->provider;
                }
            }

            $account = $this->ottService->disconnectAccount($targetProvider);

            return response()->json([
                'success' => true,
                'message' => "La cuenta de {$targetProvider} ha sido desconectada y sus credenciales purgadas.",
                'provider' => $targetProvider,
                'status' => $account->status,
            ]);
        } catch (\Throwable $e) {
            Log::error("Error al desconectar proveedor [{$provider}]: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al desconectar la cuenta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/ott-providers/{provider}
     * Elimina un proveedor personalizado.
     */
    public function destroy(string $provider): JsonResponse
    {
        try {
            $deleted = $this->ottService->deleteAccount($provider);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => "Proveedor '{$provider}' eliminado correctamente.",
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Proveedor no encontrado.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error eliminando el proveedor: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/{provider}/toggle-auto-download
     * Conmuta la bandera de descarga automática de estrenos.
     */
    public function toggleAutoDownload(string $provider): JsonResponse
    {
        try {
            $account = $this->ottService->toggleAutoDownload($provider);

            return response()->json([
                'success' => true,
                'message' => 'Preferencia de descarga automática actualizada.',
                'auto_download' => (bool) $account->auto_download,
                'provider' => $account->provider,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al alternar descargas automáticas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/{provider}/sync
     * Dispara el escaneo e ingesta real hacia el almacenamiento local en segundo plano.
     */
    public function sync(string $provider): JsonResponse
    {
        try {
            $providerAccount = is_numeric($provider)
                ? OttProviderAccount::find((int) $provider)
                : OttProviderAccount::where('provider', $provider)->first();

            // 1. Validar la existencia del proveedor
            if (!$providerAccount) {
                return response()->json([
                    'success' => false,
                    'message' => "El proveedor [{$provider}] no está registrado.",
                ], 404);
            }

            // 2. Validar credenciales de acceso
            if (!$providerAccount->hasCredentials()) {
                return response()->json([
                    'success' => false,
                    'message' => "El proveedor [{$providerAccount->account_name}] no tiene credenciales configuradas o válidas.",
                ], 422);
            }

            // 3. Cambiar el estado de la cuenta en base de datos a 'syncing'
            $providerAccount->status = 'syncing';
            $providerAccount->error_message = null;
            $providerAccount->last_error = null;
            $providerAccount->save();

            // 4. Despacha inmediatamente un Job a la cola
            SyncOttProviderJob::dispatch($providerAccount->id);

            // Asegurar que el worker procese la cola desacoplado de PHP-FPM
            $this->ensureQueueWorkerRunning();

            // 5. Retorna INMEDIATAMENTE una respuesta JSON sin esperar la finalización
            return response()->json([
                'success' => true,
                'message' => 'Sincronización iniciada en segundo plano.',
                'status' => 'syncing',
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error("Error despachando sincronización OTT para [{$provider}]: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar la sincronización: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/ott-tasks
     * GET /api/admin/ott-providers/tasks
     * Consulta la tabla ott_download_tasks real vinculada con la cola de Laravel (jobs).
     * Si no hay tareas en BD, retorna [].
     */
    public function tasks(Request $request): JsonResponse
    {
        try {
            $filters = [
                'provider' => $request->query('provider'),
                'status' => $request->query('status'),
                'limit' => $request->query('limit', 100),
            ];

            $tasks = $this->ottService->getDownloadTasksList($filters);

            // Si se solicita formato envuelto explícitamente (o llamada legacy ott-providers/tasks)
            if ($request->query('format') === 'wrapped' || $request->is('*/ott-providers/tasks')) {
                $metrics = $this->ottService->calculateMetrics($tasks);
                return response()->json([
                    'success' => true,
                    'tasks' => $tasks,
                    'metrics' => $metrics,
                ]);
            }

            // GET /api/admin/ott-tasks retorna [] cuando no hay tareas o el array real de tareas
            return response()->json($tasks);
        } catch (\Throwable $e) {
            Log::error('Error consultando cola de descargas OTT: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/tasks/{id}/cancel
     */
    public function cancelTask(int $id): JsonResponse
    {
        try {
            $task = $this->ottService->cancelTask($id);
            try {
                app(\App\Services\MediaIngestService::class)->cancelTask($id);
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'message' => "Descarga de '{$task->title}' cancelada.",
                'task' => $task,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo cancelar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/tasks/{id}/retry
     */
    public function retryTask(int $id): JsonResponse
    {
        try {
            $task = $this->ottService->retryTask($id);
            try {
                app(\App\Services\MediaIngestService::class)->retryTask($id);
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'message' => "Reanudando descarga de '{$task->title}'...",
                'task' => $task,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo reintentar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/ott-providers/tasks/{id}
     */
    public function deleteTask(int $id): JsonResponse
    {
        try {
            $this->ottService->deleteTask($id);

            return response()->json([
                'success' => true,
                'message' => 'Tarea eliminada de la cola.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar la tarea: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/tasks/clear-completed
     */
    public function clearCompleted(): JsonResponse
    {
        try {
            $count = $this->ottService->clearCompletedTasks();

            return response()->json([
                'success' => true,
                'message' => "Se limpiaron {$count} tareas completadas.",
                'cleared_count' => $count,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar tareas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/ott-providers/{provider}/reset-status
     * POST /api/admin/ott-providers/{id}/reset-status
     * Desbloquea manualmente o cancela una sincronización huérfana o atascada.
     */
    public function resetStatus(Request $request, string $provider): JsonResponse
    {
        try {
            $account = is_numeric($provider)
                ? OttProviderAccount::find((int) $provider)
                : OttProviderAccount::where('provider', $provider)->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => "El proveedor [{$provider}] no fue encontrado.",
                ], 404);
            }

            $requestedStatus = $request->input('status', 'connected');
            if (!in_array($requestedStatus, ['connected', 'error', 'disconnected'])) {
                $requestedStatus = 'connected';
            }

            $previousStatus = $account->status;
            $account->status = $requestedStatus;

            if ($requestedStatus === 'error') {
                $reason = $request->input('reason', 'Sincronización cancelada manualmente por el administrador.');
                $account->last_error = $reason;
                $account->error_message = $reason;
                $account->last_sync_at = now();
            } else {
                // Si vuelve a connected o disconnected, se purga el error
                $account->error_message = null;
                $account->last_error = null;
            }

            $account->save();

            Log::warning("Estado de cuenta OTT [{$account->provider}] reseteado manualmente de [{$previousStatus}] a [{$account->status}] por el administrador.");

            return response()->json([
                'success' => true,
                'message' => "El estado de '{$account->provider}' ha sido restablecido a '{$account->status}'.",
                'provider' => $account->provider,
                'status' => $account->status,
                'last_error' => $account->last_error,
                'last_sync_at' => $account->last_sync_at ? $account->last_sync_at->toIso8601String() : null,
            ]);
        } catch (\Throwable $e) {
            Log::error("Error restableciendo estado de proveedor [{$provider}]: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No se pudo restablecer el estado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Garantiza la ejecución de un worker en segundo plano desacoplado del hilo HTTP.
     */
    protected function ensureQueueWorkerRunning(): void
    {
        try {
            $artisanPath = base_path('artisan');

            // Determinar el binario CLI de PHP real (evitar php-fpm que falla al invocar artisan)
            $phpBinary = 'php';
            if (file_exists('/usr/local/bin/php')) {
                $phpBinary = '/usr/local/bin/php';
            } elseif (file_exists('/usr/bin/php')) {
                $phpBinary = '/usr/bin/php';
            } elseif (defined('PHP_BINARY') && !str_contains(PHP_BINARY, 'php-fpm')) {
                $phpBinary = PHP_BINARY;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $command = "start /B {$phpBinary} \"{$artisanPath}\" queue:work database --stop-when-empty --tries=1 --timeout=60 > NUL 2>&1";
                pclose(popen($command, 'r'));
            } else {
                $command = "{$phpBinary} \"{$artisanPath}\" queue:work database --stop-when-empty --tries=1 --timeout=60 > /dev/null 2>&1 &";
                exec($command);
            }
        } catch (\Throwable $e) {
            Log::warning("No se pudo iniciar el worker de cola en segundo plano: " . $e->getMessage());
        }
    }
}
