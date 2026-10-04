<?php

namespace App\Console\Commands;

use App\Services\MediaSyncService;
use Illuminate\Console\Command;

class SyncEmbyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:sync-emby {--url= : URL del servidor Emby (default: http://45.4.87.126:6789)} {--key= : API Key de Emby}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincronizar bibliotecas y títulos del servidor real Emby (server01-PowerEdge-R720)';

    /**
     * Execute the console command.
     */
    public function handle(MediaSyncService $syncService): int
    {
        $url = $this->option('url') ?: 'http://45.4.87.126:6789';
        $key = $this->option('key');

        $this->info("Iniciando sincronización con el servidor Emby en {$url}...");

        $result = $syncService->syncEmby($url, $key);

        if (($result['status'] ?? '') === 'error') {
            $this->error($result['message']);
            return Command::FAILURE;
        }

        if (($result['status'] ?? '') === 'unauthorized') {
            $this->warn($result['message']);
            return Command::SUCCESS;
        }

        $this->info($result['message'] ?? 'Sincronización completada exitosamente.');
        $this->info("Servidor: " . ($result['server_name'] ?? 'server01-PowerEdge-R720'));
        $this->info("Títulos reales sincronizados: " . count($result['synced_sources'] ?? []));

        return Command::SUCCESS;
    }
}
