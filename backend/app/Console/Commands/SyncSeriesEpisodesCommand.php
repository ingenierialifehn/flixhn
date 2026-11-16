<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMediaIngestJob;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Services\RemoteMediaConnectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSeriesEpisodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixengine:sync-series-episodes {title_id : ID del título} {series_dir : Directorio de origen o ID de Emby} {provider=netflix : Proveedor de streaming}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingesta y vincula en segundo plano todos los episodios de una serie de forma progresiva e imperceptible para el usuario';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $titleId = (int) $this->argument('title_id');
        $seriesDir = trim((string) $this->argument('series_dir'));
        $provider = strtolower(trim((string) $this->argument('provider')));

        $title = Title::find($titleId);
        if (!$title) {
            $this->error("Título #{$titleId} no encontrado.");
            return self::FAILURE;
        }

        $this->info("Iniciando sincronización en segundo plano de episodios para '{$title->name}'...");
        Log::info("SyncSeriesEpisodesCommand: Iniciando para '{$title->name}' (ID: {$titleId}) desde {$seriesDir}");

        // 1. Si es un ID de Emby o fuente remota
        if (is_numeric($seriesDir) || str_starts_with($seriesDir, 'emby') || str_starts_with($seriesDir, 'remote://')) {
            try {
                $embyService = app(RemoteMediaConnectorService::class);
                $title->source_path = $seriesDir;
                $title->save();
                $eps = $embyService->syncSeriesEpisodes($title);
                $this->info("Sincronizados " . count($eps) . " episodios desde Emby.");
                Log::info("SyncSeriesEpisodesCommand: Sincronizados " . count($eps) . " episodios desde Emby para '{$title->name}'");
                return self::SUCCESS;
            } catch (\Throwable $e) {
                Log::warning("SyncSeriesEpisodesCommand error con Emby: " . $e->getMessage());
            }
        }

        // 2. Si es un directorio local en disco (/mnt/media_library/...)
        $diskPath = ProcessMediaIngestJob::resolveDiskPath($seriesDir);
        if (!$diskPath || !File::isDirectory($diskPath)) {
            // Si $seriesDir es un archivo de video, tomar su carpeta padre
            if ($diskPath && File::isFile($diskPath)) {
                $diskPath = dirname($diskPath);
            } else {
                $this->error("Directorio no válido: {$seriesDir}");
                return self::FAILURE;
            }
        }

        // Si la carpeta es "Temporada 1", verificar si la carpeta padre es la raíz de la serie para encontrar todas las temporadas
        $parentDir = dirname($diskPath);
        if (preg_match('/(?:temporada|season)\s*\d+/i', basename($diskPath)) && File::isDirectory($parentDir)) {
            $searchDir = $parentDir;
        } else {
            $searchDir = $diskPath;
        }

        $escaped = escapeshellarg($searchDir);
        $findCmd = "find {$escaped} -maxdepth 4 \( -name lost+found -prune \) -o \( -type f \( -name '*.mp4' -o -name '*.mkv' -o -name '*.avi' -o -name '*.ts' \) ! -name '.*' ! -iname '*sample*' ! -iname '*trailer*' ! -iname '*extra*' -print \) 2>/dev/null";
        $output = @shell_exec($findCmd);

        $videoFiles = array_filter(array_map('trim', explode("\n", (string) $output)));
        if (empty($videoFiles)) {
            $this->warn("No se encontraron episodios adicionales en {$searchDir}");
            return self::SUCCESS;
        }

        natsort($videoFiles);
        $videoFiles = array_values($videoFiles);

        $slug = $title->slug ?: Str::slug($title->name);
        $totalSynced = 0;
        $epCounter = 1;

        foreach ($videoFiles as $videoFile) {
            if (!File::exists($videoFile) || @filesize($videoFile) < 15000000) {
                continue;
            }

            if (ProcessMediaIngestJob::isBannedOrPiratedSource($videoFile)) {
                continue;
            }

            $info = self::parseSeasonAndEpisode($videoFile, $epCounter);
            $seasonNum = max(1, $info['season']);
            $epNum = max(1, $info['episode'] ?? $epCounter);
            $epCounter++;

            // Temporada en base de datos
            $season = Season::firstOrCreate(
                ['title_id' => $title->id, 'season_number' => $seasonNum],
                ['title' => "Temporada {$seasonNum}"]
            );

            // Destino en Disk5 para almacenamiento perimetral FlixHN
            $targetDir = "/mnt/media_library/Disk5/FlixHN/{$provider}/series/{$slug}/Temporada {$seasonNum}";
            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0777, true, true);
                @chmod($targetDir, 0777);
            }

            $ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION)) ?: 'mp4';
            $epNumFormatted = sprintf('%02d', $epNum);
            $seasonNumFormatted = sprintf('%02d', $seasonNum);
            $targetFile = "{$targetDir}/{$slug}_S{$seasonNumFormatted}E{$epNumFormatted}.{$ext}";

            // Si el archivo de destino no existe, vincularlo mediante hardlink o copia
            if (!File::exists($targetFile)) {
                if (!@link($videoFile, $targetFile)) {
                    @copy($videoFile, $targetFile);
                }
                @chmod($targetFile, 0666);
            }

            $effectiveFile = File::exists($targetFile) ? $targetFile : $videoFile;
            $duration = (int) ProcessMediaIngestJob::getVideoDuration($effectiveFile) ?: 1380;

            $episode = Episode::firstOrCreate(
                ['title_id' => $title->id, 'season_id' => $season->id, 'episode_number' => $epNum],
                [
                    'title' => "Capítulo {$epNum}",
                    'file_path' => $effectiveFile,
                    'stream_path' => "/api/stream/title/{$title->id}",
                    'duration_seconds' => $duration,
                ]
            );

            // Garantizar ruta de stream y archivo exacto
            $episode->update([
                'file_path' => $effectiveFile,
                'stream_path' => "/api/stream/episode/{$episode->id}",
                'duration_seconds' => $duration,
            ]);

            $totalSynced++;
        }

        // Actualizar duración y metadata del título
        $title->update([
            'type' => 'series',
            'file_path' => $title->file_path ?: ($videoFiles[0] ?? null),
            'stream_path' => "/api/stream/title/{$title->id}",
        ]);

        $this->info("Proceso completado: {$totalSynced} episodios sincronizados para '{$title->name}'.");
        Log::info("SyncSeriesEpisodesCommand: Sincronizados exitosamente {$totalSynced} episodios para '{$title->name}' (ID: {$titleId})");

        return self::SUCCESS;
    }

    /**
     * Parsea número de temporada y episodio a partir del nombre del archivo y directorio padre.
     */
    public static function parseSeasonAndEpisode(string $filePath, int $fallbackEpisode = 1): array
    {
        $filename = basename($filePath);
        $parentDir = basename(dirname($filePath));

        $season = 1;
        if (preg_match('/(?:temporada|season)\s*(\d+)/i', $parentDir, $sm)) {
            $season = (int) $sm[1];
        } elseif (preg_match('/(?:temporada|season)\s*(\d+)/i', $filename, $sm)) {
            $season = (int) $sm[1];
        }

        $episode = null;
        if (preg_match('/[sS](\d+)[eE](\d+)/i', $filename, $m)) {
            $season = (int) $m[1];
            $episode = (int) $m[2];
        } elseif (preg_match('/(\d+)[xX](\d+)/i', $filename, $m)) {
            $season = (int) $m[1];
            $episode = (int) $m[2];
        } elseif (preg_match('/^[pP](\d{3})/i', $filename, $m)) {
            $episode = (int) $m[1];
        } elseif (preg_match('/(?:cap[ií]tulo|capitulo|episodio)\s*(\d+)/i', $filename, $m)) {
            $episode = (int) $m[1];
        } elseif (preg_match('/(?:^|\D)(\d{1,3})\s*(?:-|_|\.)/i', $filename, $m)) {
            $episode = (int) $m[1];
        }

        return [
            'season' => $season,
            'episode' => $episode !== null ? $episode : $fallbackEpisode,
        ];
    }
}
