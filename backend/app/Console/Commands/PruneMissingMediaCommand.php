<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Title;
use App\Models\Episode;
use App\Models\TvChannel;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Log;

class PruneMissingMediaCommand extends Command
{
    /**
     * El nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'media:prune-missing';

    /**
     * Descripción del comando Artisan.
     *
     * @var string
     */
    protected $description = 'Recorre películas y episodios locales cuya ruta file_path ya no exista en /mnt/media_library/ y los purga automáticamente de MySQL, junto con series huérfanas (0 episodios) y canales desactivados/eliminados.';

    /**
     * Ejecuta el comando Artisan.
     */
    public function handle(MediaIntegrityService $integrityService): int
    {
        $this->info('[' . now()->toDateTimeString() . '] Iniciando saneamiento e integridad de medios en FlixHN...');

        $purgedMovies = 0;
        $purgedEpisodes = 0;
        $purgedSeries = 0;
        $purgedChannels = 0;

        // 1. Recorrer películas locales
        $localMovies = Title::where('type', 'movie')
            ->where(function ($q) {
                $q->where('provider', 'local')
                  ->orWhere('source_path', 'LIKE', '/mnt/%')
                  ->orWhere('source_path', 'LIKE', '%media_library%');
            })
            ->get();

        foreach ($localMovies as $movie) {
            $filePath = $movie->source_path ?: $movie->stream_path;
            if (!$integrityService->doesPhysicalFileExist($filePath)) {
                $this->warn("  - Película eliminada del disco: [ID: {$movie->id}] {$movie->name} (Ruta: {$filePath})");
                $integrityService->purgeTitle($movie);
                $purgedMovies++;
            }
        }

        // 2. Recorrer episodios locales
        $localEpisodes = Episode::where(function ($q) {
                $q->where('source_type', 'local')
                  ->orWhere('file_path', 'LIKE', '/mnt/%')
                  ->orWhere('file_path', 'LIKE', '/var/%')
                  ->orWhere('file_path', 'LIKE', '%media_library%')
                  ->orWhereNotNull('file_path');
            })
            ->get();

        foreach ($localEpisodes as $episode) {
            if ($integrityService->isLocalEpisode($episode)) {
                $filePath = $episode->file_path ?: $episode->stream_path;
                if (!$integrityService->doesPhysicalFileExist($filePath)) {
                    $this->warn("  - Episodio eliminado del disco: [ID: {$episode->id}] {$episode->title} (Ruta: {$filePath})");
                    $integrityService->purgeEpisode($episode, false);
                    $purgedEpisodes++;
                }
            }
        }

        // 3. Purgar series huérfanas (series con 0 episodios válidos)
        $seriesList = Title::where('type', 'series')->get();
        foreach ($seriesList as $series) {
            $epCount = Episode::where('title_id', $series->id)->count();
            if ($epCount === 0) {
                $this->warn("  - Serie huérfana sin episodios: [ID: {$series->id}] {$series->name}");
                $integrityService->purgeTitle($series);
                $purgedSeries++;
            }
        }

        // 4. Purgar canales desactivados o eliminados
        $inactiveChannels = TvChannel::where('is_active', false)
            ->orWhere('status', 'offline_deleted')
            ->orWhere('status', 'deleted')
            ->get();

        foreach ($inactiveChannels as $channel) {
            $this->warn("  - Canal inactivo/eliminado: [ID: {$channel->id}] {$channel->name}");
            $integrityService->purgeChannel($channel);
            $purgedChannels++;
        }

        // 5. Invalidador final de caché de catálogo
        if ($purgedMovies > 0 || $purgedEpisodes > 0 || $purgedSeries > 0 || $purgedChannels > 0) {
            $integrityService->clearCatalogCache();
        }

        $totalPurged = $purgedMovies + $purgedEpisodes + $purgedSeries + $purgedChannels;

        $this->table(
            ['Categoría', 'Purgados de MySQL'],
            [
                ['Películas locales inexistentes', $purgedMovies],
                ['Episodios locales inexistentes', $purgedEpisodes],
                ['Series huérfanas (0 episodios)', $purgedSeries],
                ['Canales de TV desactivados', $purgedChannels],
                ['Total elementos saneados', $totalPurged],
            ]
        );

        Log::info("media:prune-missing: Saneamiento completado. Películas: {$purgedMovies}, Episodios: {$purgedEpisodes}, Series: {$purgedSeries}, Canales: {$purgedChannels}");

        $this->info("✓ Saneamiento completado satisfactoriamente.");

        return 0;
    }
}

