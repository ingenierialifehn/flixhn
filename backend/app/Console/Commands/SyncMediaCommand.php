<?php

namespace App\Console\Commands;

use App\Services\MediaSyncService;
use Illuminate\Console\Command;

class SyncMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:sync {--media : Alias de sincronización de medios}';

    /**
     * The console command aliases.
     *
     * @var array
     */
    protected $aliases = ['flixhn:sync-media'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Escanear almacenamiento local (/var/media) y sincronizar catálogo real de FlixHN';

    /**
     * Execute the console command.
     */
    public function handle(MediaSyncService $syncService): int
    {
        $this->info('Iniciando sincronización de bibliotecas con el servidor de medios FlixHN...');

        $result = $syncService->sync();

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Títulos reales sincronizados', $result['metrics']['total_titles']],
                ['Películas detectadas', $result['metrics']['total_movies']],
                ['Series detectadas', $result['metrics']['total_series']],
                ['Títulos caducados/eliminados', $result['metrics']['deleted_stale']],
                ['Archivos escaneados en almacenamiento', $result['metrics']['synced_local']],
            ]
        );

        $this->info($result['message']);

        return Command::SUCCESS;
    }
}
