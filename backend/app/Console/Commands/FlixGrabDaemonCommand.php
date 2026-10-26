<?php

namespace App\Console\Commands;

use App\Services\FlixGrabDaemonService;
use Illuminate\Console\Command;

class FlixGrabDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixgrab:daemon {action=status : Acción (status, start, stop, restart, run, run-once)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Controlador del servicio en segundo plano FlixGrab OTT Daemon';

    /**
     * Execute the console command.
     */
    public function handle(FlixGrabDaemonService $service): int
    {
        $action = strtolower($this->argument('action'));
        $this->info("Controlando FlixGrab OTT Daemon -> Acción: [{$action}]");

        switch ($action) {
            case 'start':
                $status = $service->startDaemon();
                $this->info("Daemon iniciado. PID: " . ($status['pid'] ?? 'N/A'));
                break;

            case 'stop':
                $status = $service->stopDaemon();
                $this->info("Daemon detenido.");
                break;

            case 'restart':
                $service->stopDaemon();
                sleep(1);
                $status = $service->startDaemon();
                $this->info("Daemon reiniciado. PID: " . ($status['pid'] ?? 'N/A'));
                break;

            case 'run':
                $this->info("Iniciando loop continuo del daemon FlixGrab...");
                $service->runDaemonLoop(false);
                break;

            case 'run-once':
                $this->info("Ejecutando iteración única del daemon FlixGrab...");
                $service->runDaemonLoop(true);
                $this->info("Iteración completada.");
                break;

            case 'status':
            default:
                $status = $service->getStatus();
                $this->table(['Parámetro', 'Valor'], [
                    ['Estado', $status['status_label']],
                    ['PID', $status['pid'] ?? 'N/A'],
                    ['Tareas Activas', $status['active_tasks']],
                    ['Tareas Completadas', $status['completed_tasks']],
                    ['Proveedores Conectados', implode(', ', $status['connected_providers'])],
                    ['Última Verificación', $status['last_check']],
                ]);
                break;
        }

        return self::SUCCESS;
    }
}
