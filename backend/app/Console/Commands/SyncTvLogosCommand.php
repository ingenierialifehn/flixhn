<?php

namespace App\Console\Commands;

use App\Services\TvLogoService;
use Illuminate\Console\Command;

class SyncTvLogosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:sync-logos {--force : Forzar la re-descarga de logos locales existentes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descargar y almacenar localmente los logotipos de los canales de televisión en vivo';

    /**
     * Execute the console command.
     */
    public function handle(TvLogoService $logoService): int
    {
        $force = (bool)$this->option('force');

        $this->info("Iniciando procesamiento y persistencia local de logotipos de TV en vivo...");
        if ($force) {
            $this->warn("Modo --force activado: se re-descargarán los logotipos existentes.");
        }

        $stats = $logoService->syncAllChannelLogos($force);

        $this->newLine();
        $this->info("Procesamiento de logotipos completado exitosamente:");
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Total Canales', $stats['total']],
                ['Descargados de la Web', $stats['downloaded']],
                ['Ya en Caché Local', $stats['cached']],
                ['Fallbacks Generados', $stats['generated']],
            ]
        );

        return Command::SUCCESS;
    }
}
