<?php

namespace App\Console\Commands;

use App\Services\M3uSyncService;
use Illuminate\Console\Command;

class SyncIptvCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:sync-iptv {--url= : URL directa de la lista M3U (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descargar y sincronizar canales de televisión en vivo desde la fuente M3U activa';

    /**
     * Execute the console command.
     */
    public function handle(M3uSyncService $syncService): int
    {
        $customUrl = $this->option('url');

        $this->info("Iniciando sincronización IPTV desde la fuente M3U activa...");
        if ($customUrl) {
            $this->info("URL personalizada: {$customUrl}");
        }

        $result = $syncService->syncActiveSource($customUrl);

        if (!$result['success']) {
            $this->error("Error en sincronización: " . $result['message']);
            return Command::FAILURE;
        }

        $this->info("✅ " . $result['message']);
        if (!empty($result['source_url'])) {
            $this->info("Fuente procesada: " . $result['source_url']);
        }
        $this->info("Total canales sincronizados: " . $result['count']);

        return Command::SUCCESS;
    }
}
