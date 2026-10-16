<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class OptimizeDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:optimize-database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimizar y desfragmentar las tablas del clúster MySQL de FlixHN';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupService $backupService): int
    {
        $this->info('Iniciando optimización y análisis de tablas de base de datos...');

        try {
            $result = $backupService->optimizeTables();

            $rows = [];
            foreach ($result['tables'] as $t) {
                $rows[] = [
                    $t['table'],
                    $t['analyze_status'] ?? 'N/A',
                    $t['optimize_status'] ?? 'N/A',
                    $t['status'] === 'success' ? 'OK' : 'ERROR',
                ];
            }

            $this->table(['Tabla', 'Analyze Status', 'Optimize Status', 'Resultado'], $rows);
            $this->info("✓ Proceso completado. Total de tablas analizadas: {$result['total_tables']}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error optimizando tablas: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
