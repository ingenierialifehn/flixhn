<?php

namespace App\Console\Commands;

use App\Services\MediaIngestService;
use Illuminate\Console\Command;

class FlixEngineIngestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixengine:ingest-process {id : ID de la tarea en media_ingest_tasks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta en segundo plano la descarga, transcodificación e indexación de una tarea de ingesta FlixEngine';

    /**
     * Execute the console command.
     */
    public function handle(MediaIngestService $ingestService): int
    {
        $id = (int) $this->argument('id');

        $this->info("Iniciando proceso de ingesta FlixEngine para tarea #{$id}...");

        try {
            $job = new \App\Jobs\ProcessMediaIngestJob($id);
            $job->handle($ingestService);
            $this->info("Tarea #{$id} procesada e indexada exitosamente.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("La tarea #{$id} finalizó con error o fue cancelada: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
