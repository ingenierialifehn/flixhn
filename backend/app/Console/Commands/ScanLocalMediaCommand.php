<?php

namespace App\Console\Commands;

use App\Services\LocalMediaScannerService;
use Illuminate\Console\Command;

class ScanLocalMediaCommand extends Command
{
    /**
     * El nombre y firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'media:scan-local {--path= : Ruta de directorio específica a escanear}';

    /**
     * Los alias del comando.
     *
     * @var array
     */
    protected $aliases = ['flixhn:scan-local', 'media:scan'];

    /**
     * La descripción del comando.
     *
     * @var string
     */
    protected $description = 'Escanear discos y bibliotecas locales (/mnt/media_library/Disk2-7), indexar con TMDb y habilitar streaming HTTP 206';

    /**
     * Ejecuta el comando de consola.
     */
    public function handle(LocalMediaScannerService $scanner): int
    {
        $specificPath = $this->option('path');

        $this->info('=====================================================');
        $this->info(' FlixHN - Escáner de Medios Locales & TMDb Directo   ');
        $this->info('=====================================================');

        if ($specificPath) {
            $this->comment("Ruta seleccionada para escaneo: {$specificPath}");
        } else {
            $this->comment('Escaneando discos configurados (/mnt/media_library/Disk2 a Disk7)...');
        }

        $startTime = microtime(true);
        $results = $scanner->scan($specificPath);
        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('Resultados del escaneo e indexación:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Archivos de video examinados', $results['total_files_scanned']],
                ['Películas indexadas / actualizadas', $results['total_movies']],
                ['Series indexadas / actualizadas', $results['total_series']],
                ['Episodios indexados / actualizadas', $results['total_episodes']],
                ['Errores encontrados', $results['errors']],
                ['Tiempo total de ejecución', "{$duration} segundos"],
            ]
        );

        if (!empty($results['paths_scanned'])) {
            $this->comment('Rutas físicas procesadas: ' . implode(', ', $results['paths_scanned']));
        }

        $this->info('¡Catálogo físico local sincronizado y listo para streaming directo!');
        return Command::SUCCESS;
    }
}

