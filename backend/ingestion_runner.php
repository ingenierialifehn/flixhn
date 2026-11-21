<?php

// Runner CLI desacoplado para MediaIngestionModule (evita bloquear PHP-FPM)
define('LARAVEL_START', microtime(true));

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$arg = isset($argv[1]) ? trim((string) $argv[1]) : '';
$service = $app->make(\App\Services\MediaIngestionService::class);

if (is_numeric($arg) && (int) $arg > 0) {
    $taskId = (int) $arg;
    try {
        $service->processTask($taskId);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("ingestion_runner: Excepción procesando tarea #{$taskId}: " . $e->getMessage());
    }
} elseif ($arg === 'pending') {
    $pending = \App\Models\MediaIngestionTask::where('status', 'pending')->orderBy('id')->get();
    foreach ($pending as $task) {
        try {
            $service->processTask($task->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("ingestion_runner: Excepción en cola para tarea #{$task->id}: " . $e->getMessage());
        }
    }
}