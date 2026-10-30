<?php
// Dedicated ultra-fast background runner for FlixEngine / OTT Ingest
define('LARAVEL_START', microtime(true));

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

$arg = isset($argv[1]) ? trim($argv[1]) : '';
$ingestService = $app->make(\App\Services\MediaIngestService::class);

if (is_numeric($arg) && (int)$arg > 0) {
    $taskId = (int)$arg;
    try {
        $job = new \App\Jobs\ProcessMediaIngestJob($taskId);
        $job->handle($ingestService);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("ingest_runner: Error en tarea #{$taskId}: " . $e->getMessage());
    }
} elseif ($arg === 'all' || empty($arg)) {
    $pendingTasks = \App\Models\MediaIngestTask::whereIn('status', ['pending', 'downloading'])->get();
    foreach ($pendingTasks as $t) {
        try {
            $job = new \App\Jobs\ProcessMediaIngestJob($t->id);
            $job->handle($ingestService);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("ingest_runner: Error en tarea #{$t->id}: " . $e->getMessage());
        }
    }
}
