<?php

namespace App\Jobs;

use App\Services\MediaIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $taskId;

    /**
     * Tiempo máximo de ejecución: 4 horas para archivos pesados o transcodificación.
     */
    public int $timeout = 14400;

    /**
     * Un solo intento automático para evitar descargas duplicadas en caso de cancelación.
     */
    public int $tries = 1;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
        $this->onQueue('default');
    }

    public function handle(MediaIngestionService $ingestionService): void
    {
        Log::info("ProcessMediaIngestionJob: Iniciando procesamiento para tarea de ingesta #{$this->taskId}");
        $ingestionService->processTask($this->taskId);
    }
}