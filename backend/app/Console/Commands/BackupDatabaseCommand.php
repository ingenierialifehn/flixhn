<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixhn:backup-database {--type=manual : Tipo de respaldo: manual o auto}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generar un respaldo comprimido de la base de datos de FlixHN';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupService $backupService): int
    {
        $type = $this->option('type') === 'auto' ? 'auto' : 'manual';
        $this->info("Iniciando creación de respaldo de base de datos FlixHN [Tipo: {$type}]...");

        try {
            $backup = $backupService->createBackup($type);

            $this->info('✓ Respaldo generado satisfactoriamente.');
            $this->table(
                ['Propiedad', 'Detalle'],
                [
                    ['Archivo', $backup['filename']],
                    ['Tamaño', $backup['human_size']],
                    ['Método empleado', $backup['method'] ?? 'Nativo'],
                    ['Tipo', ucfirst($backup['type'])],
                    ['Fecha', $backup['created_at']],
                    ['Ruta absoluta', $backup['path']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error generando el respaldo: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
