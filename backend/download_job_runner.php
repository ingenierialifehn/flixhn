<?php

/**
 * Ejecutor asíncrono en segundo plano para descargas reales de FlixGrab (dentro del contenedor backend).
 * Descarga el stream real resuelto (HLS/MP4) con FFmpeg actualizando progreso, velocidad y ETA en tiempo real.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId <= 0) {
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\DownloadJob;
use App\Services\DownloadService;
use App\Services\RealMediaSourceResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

set_time_limit(0);
ini_set('memory_limit', '512M');

$lockFile = sys_get_temp_dir() . '/flixgrab_job_' . $jobId . '.lock';
@file_put_contents($lockFile, (string) getmypid());

try {
    $job = DownloadJob::find($jobId);
    if (!$job) {
        @unlink($lockFile);
        exit(0);
    }

    /** @var RealMediaSourceResolver $resolver */
    $resolver = app(RealMediaSourceResolver::class);
    /** @var DownloadService $downloadService */
    $downloadService = app(DownloadService::class);

    $destFile = $job->storage_path;
    $resolver->purgeIfWrongFile($destFile, $job->title, $job);

    if ($destFile && file_exists($destFile) && !is_dir($destFile) && @filesize($destFile) > 2 * 1024 * 1024) {
        $fSize = (int) @filesize($destFile);
        $job->update([
            'status' => 'READY',
            'progress' => 100,
            'total_bytes' => $fSize,
            'downloaded_bytes' => $fSize,
            'download_speed' => 'Finalizado',
            'eta' => '00:00',
            'can_play_progressive' => true,
            'completed_at' => now(),
        ]);
        $downloadService->indexCompletedDownload($job);
        @unlink($lockFile);
        exit(0);
    }

    $mediaType = $job->metadata['type'] ?? null;
    $resolved = $resolver->resolveRealSource($job->title, $mediaType, $job->source_url);

    if (!$resolved) {
        $job->update([
            'status' => 'ERROR',
            'error_message' => 'No se encontró una fuente de video verificada para este título.',
        ]);
        @unlink($lockFile);
        exit(1);
    }

    $dir = dirname($destFile);
    if (!File::isDirectory($dir)) {
        File::makeDirectory($dir, 0777, true, true);
    }

    // Caso 1: Coincidencia EXACTA en biblioteca local del servidor
    if (($resolved['mode'] ?? '') === 'local_file' && !empty($resolved['file_path']) && file_exists($resolved['file_path'])) {
        $srcLocal = $resolved['file_path'];
        if (file_exists($destFile)) {
            @unlink($destFile);
        }
        if (!@symlink($srcLocal, $destFile) && !@link($srcLocal, $destFile)) {
            @copy($srcLocal, $destFile);
        }
        $fSize = (int) @filesize($srcLocal);
        $job->update([
            'status' => 'READY',
            'progress' => 100,
            'total_bytes' => $fSize,
            'downloaded_bytes' => $fSize,
            'download_speed' => 'Finalizado',
            'eta' => '00:00',
            'can_play_progressive' => true,
            'completed_at' => now(),
        ]);
        $downloadService->indexCompletedDownload($job);
        @unlink($lockFile);
        exit(0);
    }

    // Caso 2: Descarga real desde stream externo con FFmpeg
    $expectedBytes = max(25 * 1024 * 1024, (int) ($resolved['total_bytes'] ?? (140 * 1024 * 1024)));
    $durationSecs = (int) ($resolved['duration_seconds'] ?? $job->duration_seconds ?: 1800);

    $job->update([
        'status' => 'DOWNLOADING',
        'total_bytes' => $expectedBytes,
        'duration_seconds' => $durationSecs,
        'can_play_progressive' => true,
        'download_speed' => 'Conectando...',
        'eta' => 'Calculando...',
    ]);

    $partFile = $destFile . '.part';
    if (file_exists($partFile)) {
        @unlink($partFile);
    }

    $ua = $resolved['user_agent'] ?? RealMediaSourceResolver::UA_VISIONOS;
    $uaArg = escapeshellarg($ua);
    $partArg = escapeshellarg($partFile);

    if (($resolved['mode'] ?? '') === 'hls_dual' && !empty($resolved['video_url']) && !empty($resolved['audio_url'])) {
        $vArg = escapeshellarg($resolved['video_url']);
        $aArg = escapeshellarg($resolved['audio_url']);
        $cmd = "ffmpeg -hide_banner -loglevel error -y "
            . "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto -i {$vArg} "
            . "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto -i {$aArg} "
            . "-map 0:v:0 -map 1:a:0 -c:v copy -c:a copy -bsf:a aac_adtstoasc "
            . "-f mp4 -movflags frag_keyframe+empty_moov+default_base_moof {$partArg}";
    } else {
        $srcUrl = $resolved['video_url'] ?? '';
        $srcArg = escapeshellarg($srcUrl);
        $cmd = "ffmpeg -hide_banner -loglevel error -y "
            . "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto -i {$srcArg} "
            . "-vf \"scale=trunc(iw/2)*2:trunc(ih/2)*2\" -c:v libx264 -preset ultrafast -pix_fmt yuv420p "
            . "-c:a aac -ac 2 -ar 48000 -b:a 128k "
            . "-f mp4 -movflags frag_keyframe+empty_moov+default_base_moof {$partArg}";
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('No fue posible iniciar FFmpeg para la descarga.');
    }

    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $lastBytes = 0;
    $lastTime = microtime(true);

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        @touch($lockFile);
        usleep(1000000); // 1.0s

        $fresh = DownloadJob::find($jobId);
        if (!$fresh || $fresh->status === 'CANCELLED') {
            @proc_terminate($proc);
            @proc_close($proc);
            @unlink($partFile);
            @unlink($lockFile);
            exit(0);
        }

        clearstatcache(true, $partFile);
        $nowBytes = file_exists($partFile) ? (int) @filesize($partFile) : 0;
        $nowTime = microtime(true);
        $dt = max(0.1, $nowTime - $lastTime);
        $dBytes = max(0, $nowBytes - $lastBytes);

        $speedBps = $dBytes / $dt;
        $speedMb = round($speedBps / (1024 * 1024), 2);
        $lastBytes = $nowBytes;
        $lastTime = $nowTime;

        if ($nowBytes >= $expectedBytes) {
            $expectedBytes = (int) ($nowBytes * 1.15);
        }

        $pct = min(99.0, round(($nowBytes / max(1, $expectedBytes)) * 100, 1));
        $remBytes = max(0, $expectedBytes - $nowBytes);
        $etaSecs = $speedBps > 0 ? (int) ($remBytes / $speedBps) : 0;
        $etaStr = $etaSecs > 0 ? sprintf('%02d:%02d min', intdiv($etaSecs, 60), $etaSecs % 60) : '00:05 min';

        $fresh->update([
            'status' => 'DOWNLOADING',
            'downloaded_bytes' => $nowBytes,
            'total_bytes' => $expectedBytes,
            'progress' => max((float) $fresh->progress, $pct),
            'download_speed' => "{$speedMb} MB/s",
            'eta' => $etaStr,
            'can_play_progressive' => true,
        ]);
    }

    @fclose($pipes[1]);
    @fclose($pipes[2]);
    @proc_close($proc);

    clearstatcache(true, $partFile);
    if (!file_exists($partFile) || @filesize($partFile) < 512 * 1024) {
        throw new RuntimeException('El archivo descargado está incompleto o vacío.');
    }

    // Remux rápido con +faststart para fijar duración exacta e índice de búsqueda en el encabezado moov
    $fastFile = $destFile . '.fast.mp4';
    $remuxCmd = "ffmpeg -hide_banner -loglevel error -y -i " . escapeshellarg($partFile) . " -c copy -movflags +faststart " . escapeshellarg($fastFile);
    @exec($remuxCmd, $out, $ret);

    if ($ret === 0 && file_exists($fastFile) && @filesize($fastFile) > 512 * 1024) {
        @unlink($partFile);
        if (file_exists($destFile)) {
            @unlink($destFile);
        }
        @rename($fastFile, $destFile);
    } else {
        @unlink($fastFile);
        if (file_exists($destFile)) {
            @unlink($destFile);
        }
        @rename($partFile, $destFile);
    }

    clearstatcache(true, $destFile);
    $finalSize = (int) @filesize($destFile);

    $job->refresh();
    $job->update([
        'status' => 'READY',
        'progress' => 100.0,
        'downloaded_bytes' => $finalSize,
        'total_bytes' => $finalSize,
        'download_speed' => 'Finalizado',
        'eta' => '00:00',
        'can_play_progressive' => true,
        'completed_at' => now(),
    ]);

    $downloadService->indexCompletedDownload($job);
    Log::info("download_job_runner: Descarga real completada para [{$job->title}] ({$finalSize} bytes).");
} catch (\Throwable $e) {
    Log::error("download_job_runner error [Job #{$jobId}]: " . $e->getMessage());
    try {
        DownloadJob::where('id', $jobId)->update([
            'status' => 'ERROR',
            'error_message' => $e->getMessage(),
        ]);
    } catch (\Throwable $ex) {}
} finally {
    @unlink($lockFile);
}
