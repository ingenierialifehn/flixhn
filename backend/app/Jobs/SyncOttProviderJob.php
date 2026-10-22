<?php

namespace App\Jobs;

use App\Models\OttProviderAccount;
use App\Services\OttSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOttProviderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID de la cuenta del proveedor OTT en ott_provider_accounts.
     */
    public int $providerAccountId;

    /**
     * Tiempo límite de ejecución del Job (segundos).
     */
    public int $timeout = 300;

    /**
     * Número máximo de intentos.
     */
    public int $tries = 1;

    /**
     * Crear una nueva instancia del Job.
     */
    public function __construct(int $providerAccountId)
    {
        $this->providerAccountId = $providerAccountId;
        $this->onConnection('database');
    }

    /**
     * Ejecuta el trabajo asíncrono en cola.
     */
    public function handle(OttSyncService $syncService): void
    {
        Log::info("Iniciando SyncOttProviderJob para la cuenta OTT #{$this->providerAccountId}");

        $account = OttProviderAccount::find($this->providerAccountId);
        if (!$account) {
            Log::warning("SyncOttProviderJob abortado: La cuenta OTT #{$this->providerAccountId} no existe.");
            return;
        }

        try {
            // Asegurar que el estado refleje sincronización en curso
            $account->status = 'syncing';
            $account->error_message = null;
            $account->last_error = null;
            $account->save();

            // Ejecución de la lógica de conexión externa y sincronización de catálogo
            $result = $syncService->executeCatalogSync($account->provider);

            // Actualizar estado tras éxito
            $account->refresh();
            $account->status = 'connected';
            $account->last_sync_at = now();
            $account->error_message = null;
            $account->last_error = null;
            $account->save();

            Log::info("SyncOttProviderJob finalizado con éxito para {$account->provider} (cuenta #{$this->providerAccountId}): " . json_encode($result));
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            Log::error("Error en SyncOttProviderJob para cuenta #{$this->providerAccountId} ({$account->provider}): {$errorMessage}", [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Manejo de errores: actualiza el estado de la cuenta a 'error'
            // y guarda el mensaje detallado en last_error sin tumbar el worker ni la aplicación.
            try {
                $account->refresh();
                $account->status = 'error';
                $account->last_error = $errorMessage;
                $account->error_message = $errorMessage;
                $account->save();
            } catch (\Throwable $dbError) {
                Log::error("No se pudo registrar el estado de error en la cuenta OTT #{$this->providerAccountId}: " . $dbError->getMessage());
            }

            // No relanzamos la excepción para no tumbar el worker
        }
    }

    /**
     * Manejar fallo en cola (fallback seguro).
     */
    public function failed(?\Throwable $exception): void
    {
        $error = $exception ? $exception->getMessage() : 'Error crítico no controlado durante la ejecución del job en cola.';
        Log::error("SyncOttProviderJob marcado como fallido para cuenta #{$this->providerAccountId}: {$error}");

        try {
            $account = OttProviderAccount::find($this->providerAccountId);
            if ($account) {
                $account->status = 'error';
                $account->last_error = $error;
                $account->error_message = $error;
                $account->save();
            }
        } catch (\Throwable $t) {
            Log::error("Error en callback failed() de SyncOttProviderJob: " . $t->getMessage());
        }
    }
}
