<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\Title;
use App\Services\PosterDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SyncOttPostersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixengine:sync-posters {--force : Forzar re-descarga de pósters existentes} {--provider= : Filtrar por proveedor específico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descarga físicamente las carátulas/pósters a storage/app/public/posters/{slug}.jpg y actualiza las rutas relativas (/storage/posters/{slug}.jpg) en la base de datos.';

    /**
     * Execute the console command.
     */
    public function handle(PosterDownloadService $posterService): int
    {
        $force = (bool) $this->option('force');
        $providerFilter = $this->option('provider');

        $this->info("Iniciando sincronización física de pósters a storage/app/public/posters/...");

        // 1. Procesar Titles
        $titlesQuery = Title::query();
        if ($providerFilter) {
            $titlesQuery->where('provider', $providerFilter);
        } else {
            // Priorizar proveedores OTT o URLs externas
            $titlesQuery->where(function ($q) {
                $q->whereIn('provider', ['netflix', 'disney', 'hbo_max', 'amazon_prime', 'apple_tv', 'paramount', 'external'])
                  ->orWhere('poster_url', 'LIKE', 'http%');
            });
        }

        $titles = $titlesQuery->get();
        $this->info("Procesando {$titles->count()} títulos del catálogo...");

        $bar = $this->output->createProgressBar($titles->count());
        $bar->start();

        $updatedCount = 0;

        foreach ($titles as $title) {
            $slug = Str::slug($title->slug ?: $title->name);
            if (empty($slug)) {
                $slug = 'title-' . $title->id;
            }

            $currentPoster = $title->poster_url;
            $localUrl = $posterService->downloadAndStorePoster(
                slug: $slug,
                candidateUrl: $currentPoster,
                title: $title->name,
                tmdbId: $title->tmdb_id,
                force: $force
            );

            if ($title->poster_url !== $localUrl) {
                $title->poster_url = $localUrl;
                $title->save();
                $updatedCount++;
            }

            // También actualizar MediaItem asociado si existe
            $mediaItems = MediaItem::where('title_id', $title->id)->orWhere('title', $title->name)->get();
            foreach ($mediaItems as $mi) {
                if ($mi->poster_url !== $localUrl) {
                    $mi->poster_url = $localUrl;
                    $mi->save();
                }
            }

            // Actualizar tareas de descarga asociadas
            $tasks = OttDownloadTask::where('title_id', $title->id)->orWhere('title', $title->name)->get();
            foreach ($tasks as $tk) {
                $meta = $tk->metadata ?: [];
                if (($meta['poster_url'] ?? '') !== $localUrl) {
                    $meta['poster_url'] = $localUrl;
                    $tk->metadata = $meta;
                    $tk->save();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Limpiar caché de catálogo para que el frontend reciba las nuevas rutas de inmediato
        Cache::forget('catalog_home_base');

        $this->info("¡Sincronización de pósters completada con éxito! ({$updatedCount} títulos actualizados con /storage/posters/{slug}.jpg)");

        return Command::SUCCESS;
    }
}
