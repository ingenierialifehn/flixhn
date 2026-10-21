<?php

namespace App\Jobs;

use App\Models\MediaIngestTask;
use App\Services\MediaIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID de la tarea de ingesta en media_ingest_tasks.
     */
    public int $taskId;

    /**
     * Tiempo límite de ejecución (segundos): 4 horas.
     */
    public int $timeout = 14400;

    /**
     * Create a new job instance.
     */
    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
    }

    /**
     * Execute the job.
     */
    public function handle(MediaIngestService $ingestService): void
    {
        Log::info("ProcessMediaIngestJob ejecutando para tarea #{$this->taskId}");

        try {
            $ingestService->executeDownloadById($this->taskId);
        } catch (\Throwable $e) {
            Log::error("ProcessMediaIngestJob falló para tarea #{$this->taskId}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $task = MediaIngestTask::find($this->taskId);
            if ($task && !in_array($task->status, ['completed', 'cancelled'])) {
                $task->update([
                    'status' => 'failed',
                    'error_message' => 'Fallo en cola de procesamiento: ' . $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Manejar fallo irrecuperable del Job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("ProcessMediaIngestJob marcado como fallido permanentemente para tarea #{$this->taskId}: " . ($exception?->getMessage() ?? 'Desconocido'));

        $task = MediaIngestTask::find($this->taskId);
        if ($task && !in_array($task->status, ['completed', 'cancelled'])) {
            $task->update([
                'status' => 'failed',
                'error_message' => $exception ? $exception->getMessage() : 'Error crítico en el Job de cola asíncrona.',
            ]);
        }
    }
}
