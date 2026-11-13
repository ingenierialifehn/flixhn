<?php

namespace App\Console\Commands;

use App\Services\LocalMediaScannerService;
use Illuminate\Console\Command;

class SyncLocalPostersCommand extends Command
{
    /**
     * El nombre y firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'media:sync-posters';

    /**
     * Los alias del comando.
     *
     * @var array
     */
    protected $aliases = ['flixhn:sync-posters'];

    /**
     * La descripción del comando de consola.
     *
     * @var string
     */
    protected $description = 'Busca y asocia carátulas locales (poster, folder, cover, *-poster, etc.) en /mnt/media_library/Disk2-7 para películas y series en MySQL';

    /**
     * Ejecuta el comando de consola.
     */
    public function handle(LocalMediaScannerService $scanner): int
    {
        $this->info('=====================================================');
        $this->info(' FlixHN - Sincronizador Rápido de Carátulas Locales  ');
        $this->info('=====================================================');
        $this->comment('Escaneando carpetas de películas y series en /mnt/media_library/Disk2..Disk7...');

        $startTime = microtime(true);

        $results = $scanner->syncPosters(function ($title, $posterUrl, $updated) {
            if ($updated) {
                $this->line(" <info>✓ Actualizado:</info> [ID: {$title->id}] {$title->name} -> {$posterUrl}");
            }
        });

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('Resumen de sincronización de carátulas:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total títulos en base de datos', $results['total']],
                ['Películas con carátula actualizada', $results['updated_movies']],
                ['Series con carátula actualizada', $results['updated_series']],
                ['Carpetas no encontradas / inaccesibles', $results['not_found']],
                ['Tiempo de ejecución', "{$duration} segundos"],
            ]
        );

        $this->info('¡Carátulas locales sincronizadas y caché de catálogo purgada!');
        return Command::SUCCESS;
    }
}

