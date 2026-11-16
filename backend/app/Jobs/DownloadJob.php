<?php

namespace App\Jobs;

use App\Models\DownloadJob as DownloadJobModel;
use App\Services\DownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 horas máximo por descarga
    public int $tries = 3;

    public function __construct(
        public int $downloadJobId
    ) {}

    public function handle(DownloadService $downloadService): void
    {
        $job = DownloadJobModel::find($this->downloadJobId);
        if (!$job) {
            Log::warning("DownloadJob: Trabajo ID {$this->downloadJobId} no encontrado.");
            return;
        }

        if ($job->status === 'CANCELLED') {
            Log::info("DownloadJob: Trabajo ID {$this->downloadJobId} fue cancelado por el usuario.");
            return;
        }

        Log::info("DownloadJob: Procesando trabajo de descarga ID {$job->id} - '{$job->title}'");
        $downloadService->processJob($job);
    }

    public function failed(?\Throwable $exception): void
    {
        $job = DownloadJobModel::find($this->downloadJobId);
        if ($job) {
            $job->update([
                'status' => 'ERROR',
                'error_message' => $exception ? $exception->getMessage() : 'Falla inesperada en la cola de descarga.',
                'completed_at' => now(),
            ]);
            Log::error("DownloadJob: Trabajo {$this->downloadJobId} falló definitivamente: " . ($exception?->getMessage() ?? ''));
        }
    }
}
