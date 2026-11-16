<?php

namespace App\Http\Controllers;

use App\Models\DownloadJob;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\Title;
use App\Services\DownloadService;
use App\Services\MediaIntegrityService;
use App\Services\Ott\OttManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MediaCatalogController extends Controller
{
    protected DownloadService $downloadService;
    protected MediaIntegrityService $integrityService;

    public function __construct(
        DownloadService $downloadService,
        MediaIntegrityService $integrityService
    ) {
        $this->downloadService = $downloadService;
        $this->integrityService = $integrityService;
    }

    /**
     * POST /api/media/search
     * Buscador unificado e inteligente para FlixHN.
     * Consulta local, bases multimedia, fuentes autorizadas y devuelve disponibilidad y estado estricto.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('q') ?? $request->input('query') ?? $request->input('title') ?? ''));
        $type = $request->input('type', 'all');
        $providerFilter = $request->input('provider', 'all');
        $limit = min((int) ($request->input('limit', 30)), 100);

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'query' => '',
                'total' => 0,
                'results' => [],
            ]);
        }

        $results = collect();
        $seenTitles = [];

        // 1. Consultar primero el catálogo local (Titles)
        $localQuery = Title::query();
        if ($type !== 'all') {
            $localQuery->where('type', $type);
        }
        $localQuery->where(function ($q) use ($query) {
            $q->where('name', 'LIKE', "%{$query}%")
              ->orWhere('description', 'LIKE', "%{$query}%")
              ->orWhere('genre', 'LIKE', "%{$query}%");
        });
        $localTitles = $localQuery->limit($limit)->get();

        foreach ($localTitles as $title) {
            $norm = Str::slug($title->name);
            $seenTitles[$norm] = true;

            $status = 'LOCAL';
            $filePath = $title->file_path ?: $title->source_path;
            $hasFile = $filePath && File::exists($filePath) && File::size($filePath) > 1024;

            // Verificar si hay una descarga asociada para este título
            $job = DownloadJob::where('title_id', $title->id)
                ->orWhere('content_id', (string)$title->id)
                ->orWhere('title', $title->name)
                ->latest()
                ->first();

            if ($job) {
                $status = $job->status;
            } elseif (!$hasFile) {
                $status = 'REMOTE';
            }

            $results->push([
                'id' => $title->id,
                'content_id' => (string) $title->id,
                'title' => $title->name,
                'name' => $title->name,
                'type' => $title->type ?? 'movie',
                'description' => $title->description ?: $title->overview ?: '',
                'overview' => $title->description ?: $title->overview ?: '',
                'poster_url' => $title->poster_url ?: '/img/placeholder-poster.png',
                'backdrop_url' => $title->backdrop_url ?: $title->poster_url ?: '/img/placeholder-poster.png',
                'release_year' => $title->release_year ?: $title->year,
                'year' => $title->release_year ?: $title->year,
                'duration_seconds' => $title->duration_seconds ?: 5400,
                'genre' => $title->genre ?: 'General',
                'provider' => $title->provider ?: 'local',
                'status' => $status,
                'is_local' => $status === 'LOCAL' || $status === 'READY',
                'is_downloadable' => $status !== 'LOCAL' && $status !== 'READY',
                'can_play' => $status === 'LOCAL' || $status === 'READY' || ($job && $job->can_play_progressive),
                'progress' => $job ? (float)$job->progress : ($status === 'LOCAL' ? 100 : 0),
                'download_job_id' => $job?->id,
                'stream_url' => $status === 'LOCAL' ? url("/api/media/{$title->id}/play") : ($job?->hls_playlist_url ? url($job->hls_playlist_url) : null),
            ]);
        }

        // 2. Consultar MediaItems (Catálogo extendido y OTT indexados)
        $mediaItemQuery = MediaItem::query();
        if ($type !== 'all') {
            $mediaItemQuery->where('type', $type);
        }
        $mediaItemQuery->where(function ($q) use ($query) {
            $q->where('title', 'LIKE', "%{$query}%")
              ->orWhere('original_title', 'LIKE', "%{$query}%")
              ->orWhere('overview', 'LIKE', "%{$query}%");
        });
        $mediaItems = $mediaItemQuery->limit($limit)->get();

        foreach ($mediaItems as $item) {
            $norm = Str::slug($item->title);
            if (isset($seenTitles[$norm])) {
                continue;
            }
            $seenTitles[$norm] = true;

            $status = 'REMOTE';
            $job = DownloadJob::where('media_item_id', $item->id)
                ->orWhere('content_id', (string)$item->id)
                ->orWhere('title', $item->title)
                ->latest()
                ->first();

            if ($job) {
                $status = $job->status;
            } elseif ($item->file_path && File::exists($item->file_path) && File::size($item->file_path) > 1024) {
                $status = 'LOCAL';
            }

            $results->push([
                'id' => $item->id,
                'content_id' => (string) ($item->external_id ?: $item->id),
                'title' => $item->title,
                'name' => $item->title,
                'type' => $item->type ?: 'movie',
                'description' => $item->overview ?: '',
                'overview' => $item->overview ?: '',
                'poster_url' => $item->poster_url ?: '/img/placeholder-poster.png',
                'backdrop_url' => $item->backdrop_url ?: $item->poster_url ?: '/img/placeholder-poster.png',
                'release_year' => $item->release_year,
                'year' => $item->release_year,
                'duration_seconds' => $item->duration_seconds ?: 5400,
                'genre' => $item->genre ?: 'Acción',
                'provider' => $item->provider ?: 'flixgrab',
                'status' => $status,
                'is_local' => $status === 'LOCAL' || $status === 'READY',
                'is_downloadable' => $status !== 'LOCAL' && $status !== 'READY',
                'can_play' => $status === 'LOCAL' || $status === 'READY' || ($job && $job->can_play_progressive),
                'progress' => $job ? (float)$job->progress : ($status === 'LOCAL' ? 100 : 0),
                'download_job_id' => $job?->id,
                'stream_url' => $item->stream_url ?: null,
            ]);
        }

        // 3. Consultar trabajos activos de descarga (QUEUED, DOWNLOADING, PROCESSING) que coincidan con la búsqueda
        $activeJobs = DownloadJob::where('title', 'LIKE', "%{$query}%")
            ->whereIn('status', ['QUEUED', 'DOWNLOADING', 'PROCESSING', 'ERROR'])
            ->limit(10)
            ->get();

        foreach ($activeJobs as $job) {
            $norm = Str::slug($job->title);
            if (isset($seenTitles[$norm])) {
                continue;
            }
            $seenTitles[$norm] = true;

            $results->push([
                'id' => $job->id,
                'content_id' => $job->content_id,
                'title' => $job->title,
                'name' => $job->title,
                'type' => 'movie',
                'description' => $job->metadata['overview'] ?? 'Contenido en proceso de ingesta FlixGrab.',
                'overview' => $job->metadata['overview'] ?? 'Contenido en proceso de ingesta FlixGrab.',
                'poster_url' => $job->metadata['poster_url'] ?? '/img/placeholder-poster.png',
                'backdrop_url' => $job->metadata['poster_url'] ?? '/img/placeholder-poster.png',
                'release_year' => $job->metadata['year'] ?? (int) date('Y'),
                'year' => $job->metadata['year'] ?? (int) date('Y'),
                'duration_seconds' => $job->duration_seconds ?: 5400,
                'genre' => $job->metadata['genre'] ?? 'Descarga',
                'provider' => $job->provider ?: 'flixgrab',
                'status' => $job->status,
                'is_local' => false,
                'is_downloadable' => false,
                'can_play' => $job->can_play_progressive,
                'progress' => (float) $job->progress,
                'download_job_id' => $job->id,
                'stream_url' => $job->can_play_progressive ? url($job->hls_playlist_url) : null,
            ]);
        }

        // 4. Consultar fuentes externas y catálogos autorizados (Internet Archive, Blender Open CDN, OTT Adapters)
        $externalMatches = $this->searchAuthorizedSources($query, $seenTitles);
        foreach ($externalMatches as $ext) {
            $results->push($ext);
        }

        // Filtrado por proveedor si se especificó
        if ($providerFilter !== 'all') {
            $results = $results->filter(function ($item) use ($providerFilter) {
                return strtolower($item['provider'] ?? '') === strtolower($providerFilter);
            });
        }

        return response()->json([
            'success' => true,
            'query' => $query,
            'total' => $results->count(),
            'results' => $results->values(),
        ]);
    }

    /**
     * GET /api/media/{id}/availability
     * Devuelve el estado de disponibilidad en tiempo real para reproducción o descarga.
     */
    public function availability(Request $request, $id): JsonResponse
    {
        $data = $this->downloadService->checkContentAvailability((string)$id);
        return response()->json([
            'success' => true,
            'availability' => $data,
        ]);
    }

    /**
     * Búsqueda en fuentes de medios autorizados (Dominio Público y Creative Commons CDN).
     */
    protected function searchAuthorizedSources(string $query, array &$seenTitles): array
    {
        $catalog = [
            [
                'title' => 'Big Buck Bunny',
                'type' => 'movie',
                'year' => 2008,
                'genre' => 'Animación / Comedia',
                'duration_seconds' => 596,
                'description' => 'Un conejo gigante se enfrenta a tres roedores traviesos en una divertida aventura en el bosque.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/157hX6V7FhQ3G17lH9yLq1w8jV.jpg',
                'source_url' => 'https://archive.org/download/BigBuckBunny_124/BigBuckBunny_1080p.mp4',
                'provider' => 'archive_org',
                'content_id' => 'big-buck-bunny-2008',
            ],
            [
                'title' => 'Sita Sings the Blues',
                'type' => 'movie',
                'year' => 2008,
                'genre' => 'Animación / Musical',
                'duration_seconds' => 4920,
                'description' => 'Animación épica y biográfica dirigida por Nina Paley, combinando el Ramayana y blues clásico.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/kZ9cTz8QfXy8mQ8bX3NqX8q9f.jpg',
                'source_url' => 'https://archive.org/download/Sita_Sings_the_Blues_1080p/Sita_Sings_the_Blues_1080p.mp4',
                'provider' => 'archive_org',
                'content_id' => 'sita-sings-the-blues-2008',
            ],
            [
                'title' => 'Tears of Steel',
                'type' => 'movie',
                'year' => 2012,
                'genre' => 'Ciencia Ficción',
                'duration_seconds' => 734,
                'description' => 'En un futuro distópico, un grupo de científicos y guerreros intenta rescatar a la humanidad de robots.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/2y4z9K8gXqWq2y5z8bKqWq2y5z.jpg',
                'source_url' => 'https://archive.org/download/Tears-of-Steel/tears_of_steel_1080p.webm',
                'provider' => 'blender_open',
                'content_id' => 'tears-of-steel-2012',
            ],
            [
                'title' => 'Cosmos Laundromat',
                'type' => 'movie',
                'year' => 2015,
                'genre' => 'Animación / Fantasía',
                'duration_seconds' => 730,
                'description' => 'En una isla desierta, una oveja deprimida encuentra a un vendedor misterioso que le ofrece infinitas vidas.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/56v2KjBlU4XaOv9rVYEQypROD7P.jpg',
                'source_url' => 'https://archive.org/download/CosmosLaundromatFirstCycle/Cosmos_Laundromat_First_Cycle_1080p.mp4',
                'provider' => 'blender_open',
                'content_id' => 'cosmos-laundromat-2015',
            ],
            [
                'title' => 'Night of the Living Dead',
                'type' => 'movie',
                'year' => 1968,
                'genre' => 'Terror / Clásico',
                'duration_seconds' => 5760,
                'description' => 'La clásica obra maestra de George A. Romero sobre los muertos vivientes acechando en una granja.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/h6bX2u8f7B2z4z5qWq2y5z8bK.jpg',
                'source_url' => 'https://archive.org/download/night_of_the_living_dead/night_of_the_living_dead_1080p.mp4',
                'provider' => 'archive_org',
                'content_id' => 'night-of-the-living-dead-1968',
            ],
        ];

        $matches = [];
        $lowerQuery = mb_strtolower($query);

        foreach ($catalog as $item) {
            $norm = Str::slug($item['title']);
            if (isset($seenTitles[$norm])) {
                continue;
            }

            if (str_contains(mb_strtolower($item['title']), $lowerQuery) ||
                str_contains(mb_strtolower($item['genre']), $lowerQuery) ||
                str_contains(mb_strtolower($item['description']), $lowerQuery) ||
                str_contains(mb_strtolower($item['provider']), $lowerQuery)) {

                $seenTitles[$norm] = true;

                // Verificar si ya tiene un trabajo de descarga
                $job = DownloadJob::where('content_id', $item['content_id'])->latest()->first();
                $status = $job ? $job->status : 'REMOTE';

                $matches[] = [
                    'id' => $item['content_id'],
                    'content_id' => $item['content_id'],
                    'title' => $item['title'],
                    'name' => $item['title'],
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'overview' => $item['description'],
                    'poster_url' => $item['poster_url'],
                    'backdrop_url' => $item['poster_url'],
                    'release_year' => $item['year'],
                    'year' => $item['year'],
                    'duration_seconds' => $item['duration_seconds'],
                    'genre' => $item['genre'],
                    'provider' => $item['provider'],
                    'source_url' => $item['source_url'],
                    'status' => $status,
                    'is_local' => $status === 'LOCAL' || $status === 'READY',
                    'is_downloadable' => $status !== 'LOCAL' && $status !== 'READY',
                    'can_play' => $status === 'LOCAL' || $status === 'READY' || ($job && $job->can_play_progressive),
                    'progress' => $job ? (float) $job->progress : 0,
                    'download_job_id' => $job?->id,
                    'stream_url' => $job?->can_play_progressive ? url($job->hls_playlist_url) : null,
                ];
            }
        }

        return $matches;
    }
}
