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
    protected $signature = 'media:sync-posters {--all : Sincronizar todos los títulos, incluso si ya tienen carátula}';

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
    protected $description = 'Extrae carátulas, fondos y sinopsis desde .nfo, carpetas locales o APIs públicas (TVMaze/iTunes) para películas y series';

    /**
     * Ejecuta el comando de consola.
     */
    public function handle(LocalMediaScannerService $scanner): int
    {
        $forceAll = (bool) $this->option('all');

        $this->info('========================================================================');
        $this->info(' FlixHN - Sincronizador de Carátulas, Fondos y Sinopsis (Local / TVMaze / iTunes) ');
        $this->info('========================================================================');
        $this->comment($forceAll
            ? 'Escaneando TODOS los títulos en chunks de 100...'
            : 'Escaneando en chunks de 100 títulos pendientes de carátula o con carátula por defecto...');

        $startTime = microtime(true);

        $results = $scanner->syncPosters(function ($title, $posterUrl, $updated) {
            if ($updated) {
                $this->line(" <info>✓ Actualizado:</info> [ID: {$title->id}] {$title->name} -> {$posterUrl}");
            }
        }, $forceAll);

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('Resumen de sincronización de carátulas:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total títulos en base de datos', $results['total']],
                ['Películas con carátula actualizada', $results['updated_movies']],
                ['Series con carátula actualizada', $results['updated_series']],
                ['Logos de canales TV verificados/sincronizados', $results['synced_channels'] ?? 0],
                ['Carpetas no encontradas / inaccesibles', $results['not_found']],
                ['Tiempo de ejecución', "{$duration} segundos"],
            ]
        );

        $this->info('¡Carátulas locales sincronizadas y caché de catálogo purgada!');
        return Command::SUCCESS;
    }
}

