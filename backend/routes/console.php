<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\DatabaseSetting;
use App\Services\DatabaseBackupService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Comando para generar respaldos comprimidos de base de datos.
 */
Artisan::command('flixhn:backup-database {--type=manual : Tipo de respaldo: manual o auto}', function (DatabaseBackupService $backupService) {
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

        return 0;
    } catch (\Throwable $e) {
        $this->error('Error generando el respaldo: ' . $e->getMessage());
        return 1;
    }
})->purpose('Generar un respaldo comprimido de la base de datos de FlixHN');

/**
 * Comando para optimización y análisis de tablas del clúster MySQL.
 */
Artisan::command('flixhn:optimize-database', function (DatabaseBackupService $backupService) {
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

        return 0;
    } catch (\Throwable $e) {
        $this->error('Error optimizando tablas: ' . $e->getMessage());
        return 1;
    }
})->purpose('Optimizar y desfragmentar las tablas del clúster MySQL de FlixHN');

/**
 * Tarea de Respaldo Automático Programado FlixHN.
 * Se ejecuta periódicamente y verifica la configuración activa en database_settings.
 */
Schedule::call(function () {
    try {
        $settings = DatabaseSetting::current();
        if ($settings->auto_backup_enabled) {
            $backupService = app(DatabaseBackupService::class);
            $backupService->createBackup('auto');
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Error en tarea programada de respaldo automático de base de datos: ' . $e->getMessage());
    }
})->dailyAt('03:00')->name('flixhn-database-auto-backup');
