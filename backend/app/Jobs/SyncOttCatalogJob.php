<?php

namespace App\Jobs;

use App\Services\OttSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOttCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El identificador del proveedor OTT (netflix, disney, hbo_max)
     */
    public string $provider;

    /**
     * Tiempo límite de ejecución del Job (segundos).
     */
    public int $timeout = 180;

    /**
     * Create a new job instance.
     */
    public function __construct(string $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Execute the job.
     */
    public function handle(OttSyncService $syncService): void
    {
        Log::info("Iniciando SyncOttCatalogJob para proveedor: {$this->provider}");

        try {
            $result = $syncService->executeCatalogSync($this->provider);
            Log::info("SyncOttCatalogJob completado exitosamente para {$this->provider}: " . json_encode($result));
        } catch (\Throwable $e) {
            Log::error("Error en SyncOttCatalogJob para {$this->provider}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
