<?php

namespace AppHttpControllers;

use AppModelsTitle;
use AppServicesFlixFlixLibrarySyncService;
use AppServicesFlixFlixProviderOrchestrator;
use AppServicesFlixFlixSearchHistoryService;
use AppServicesFlixOpenSubtitlesService;
use AppServicesFlixTmdbCatalogService;
use IlluminateHttpJsonResponse;
use IlluminateHttpRequest;
use IlluminateSupportFacadesLog;

/**
 * Controlador REST de integración del motor Flix (portado desde plugin.video.flix)
 * acoplado a FlixHN (/api/admin/flix/*).
 */
class FlixIntegrationController extends Controller
{
    public function __construct(
        protected TmdbCatalogService $tmdb,
        protected FlixProviderOrchestrator $orchestrator,
        protected FlixLibrarySyncService $librarySync,
        protected OpenSubtitlesService $openSubtitles,
        protected FlixSearchHistoryService $searchHistory
    ) {}

    public function status(): JsonResponse
    {
        $tmdbSettings = $this->tmdb->getSettings();
        $osSettings = $this->openSubtitles->getSettings();

        return response()->json([
            'success' => true,
            'engine' => 'FlixEngine (plugin.video.flix port)',
            'tmdb' => [
                'configured' => $this->tmdb->hasValidApiKey(),
                'language' => $tmdbSettings['language'],
                'cache_enabled' => $tmdbSettings['cache_enabled'],
                'cache_ttl_days' => $tmdbSettings['cache_ttl_days'],
            ],
            'opensubtitles' => [
                'configured' => $this->openSubtitles->hasValidApiKey(),
                'languages' => $osSettings['languages'],
            ],
            'providers' => $this->orchestrator->pingAll(),
            'tracked_shows_count' => Title::where('type', 'series')->whereNotNull('tmdb_id')->count(),
            'tracked_movies_count' => Title::where('type', 'movie')->whereNotNull('tmdb_id')->count(),
        ]);
    }

    public function genres(string $type = 'movie'): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'type' => $type,
                'genres' => $this->tmdb->getGenres($type),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'genres' => [],
            ], 422);
        }
    }

    public function discover(Request $request): JsonResponse
    {
        $mediaType = strtolower((string) $request->query('type', 'movie'));
        $section = strtolower((string) $request->query('section', 'trending'));
        $page = max(1, (int) $request->query('page', 1));

        try {
            if ($section === 'trending') {
                $data = $this->tmdb->trending($mediaType, (string) $request->query('window', 'week'), $page);
            } elseif ($section === 'discover') {
                $filters = [
                    'page' => $page,
                    'with_genres' => $request->query('with_genres'),
                    'year' => $request->query('year'),
                    'sort_by' => $request->query('sort_by', 'popularity.desc'),
                ];
                $data = in_array($mediaType, ['tv', 'series', 'show'], true)
                    ? $this->tmdb->discoverShows($filters)
                    : $this->tmdb->discoverMovies($filters);
            } else {
                $data = in_array($mediaType, ['tv', 'series', 'show'], true)
                    ? $this->tmdb->listShows($section, $page)
                    : $this->tmdb->listMovies($section, $page);
            }

            $data['results'] = $this->markLocalAvailability($data['results'] ?? []);

            return response()->json(array_merge(['success' => true], $data));
        } catch (\Throwable $e) {
            Log::warning('FlixIntegrationController::discover error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'results' => [],
            ], 422);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->query('q') ?: $request->query('query', '')));
        $type = strtolower((string) $request->query('type', 'multi'));
        $page = max(1, (int) $request->query('page', 1));

        if ($query === '') {
            return response()->json([
                'success' => true,
                'page' => 1,
                'total_pages' => 0,
                'total_results' => 0,
                'results' => [],
            ]);
        }

        try {
            $this->searchHistory->record($type, $query, $request->user()?->id);
            $data = $this->tmdb->search($type, $query, $page);
            if ($type !== 'person') {
                $data['results'] = $this->markLocalAvailability($data['results'] ?? []);
            }

            return response()->json(array_merge(['success' => true, 'query' => $query], $data));
        } catch (\Throwable $e) {
            Log::warning('FlixIntegrationController::search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'results' => [],
            ], 422);
        }
    }

    public function details(Request $request, string $type, int $tmdbId): JsonResponse
    {
        $isSeries = in_array(strtolower($type), ['tv', 'series', 'show'], true);
        $includeUnaired = filter_var($request->query('include_unaired', 'true'), FILTER_VALIDATE_BOOLEAN);

        try {
            $details = $isSeries
                ? $this->tmdb->getShowDetails($tmdbId, $includeUnaired)
                : $this->tmdb->getMovieDetails($tmdbId);

            $localTitle = Title::where('tmdb_id', $tmdbId)
                ->where('type', $isSeries ? 'series' : 'movie')
                ->first();

            $similar = [];
            try {
                $similar = array_slice($this->tmdb->getSimilar($isSeries ? 'series' : 'movie', $tmdbId, 1)['results'] ?? [], 0, 8);
            } catch (\Throwable $e) {
                // Opcional
            }

            return response()->json([
                'success' => true,
                'details' => $details,
                'in_library' => $localTitle !== null,
                'local_title_id' => $localTitle?->id,
                'similar' => $similar,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function season(Request $request, int $tmdbId, int $seasonNumber): JsonResponse
    {
        $includeUnaired = filter_var($request->query('include_unaired', 'true'), FILTER_VALIDATE_BOOLEAN);
        try {
            return response()->json([
                'success' => true,
                'season' => $this->tmdb->getSeasonDetails($tmdbId, $seasonNumber, $includeUnaired),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function trailer(Request $request, string $type, int $tmdbId): JsonResponse
    {
        try {
            $trailer = $this->tmdb->getTrailer(
                $type,
                $tmdbId,
                $request->filled('season') ? (int) $request->query('season') : null,
                $request->filled('episode') ? (int) $request->query('episode') : null,
                $request->query('language')
            );

            return response()->json([
                'success' => $trailer !== null,
                'trailer' => $trailer,
                'message' => $trailer ? null : 'No se encontró tráiler oficial en YouTube para este título.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function providersSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => 'required|string|in:search,search_movie,search_show,search_season,search_episode',
            'query' => 'nullable|string|max:255',
            'tmdb_id' => 'nullable',
            'title' => 'nullable|string|max:255',
            'alternative_titles' => 'nullable|array',
            'year' => 'nullable|integer',
            'season_number' => 'nullable|integer|min:0',
            'episode_number' => 'nullable|integer|min:1',
        ]);

        $replayKey = sprintf(
            '%s:%s:%s:%s',
            $validated['method'],
            $validated['tmdb_id'] ?? ($validated['query'] ?? ''),
            $validated['season_number'] ?? '',
            $validated['episode_number'] ?? ''
        );

        $data = $this->orchestrator->searchCandidates($validated['method'], $validated, $replayKey);

        return response()->json(array_merge(['success' => true], $data));
    }

    public function providersResolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:120',
            'provider_data' => 'required',
            'replay_key' => 'nullable|string|max:255',
        ]);

        $resolved = $this->orchestrator->resolveCandidate(
            $validated['provider'],
            $validated['provider_data'],
            $validated['replay_key'] ?? null
        );

        return response()->json($resolved);
    }

    public function librarySync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:movie,series,tv,show',
            'tmdb_id' => 'required|integer|min:1',
            'provider' => 'nullable|string|max:50',
            'include_specials' => 'nullable|boolean',
            'include_unaired' => 'nullable|boolean',
        ]);

        try {
            $isSeries = in_array($validated['type'], ['series', 'tv', 'show'], true);
            $result = $isSeries
                ? $this->librarySync->syncShow((int) $validated['tmdb_id'], $validated)
                : $this->librarySync->syncMovie((int) $validated['tmdb_id'], $validated);

            return response()->json([
                'success' => true,
                'message' => $result['created']
                    ? "Título '{$result['title']->name}' agregado a la biblioteca de FlixHN."
                    : "Metadatos de '{$result['title']->name}' actualizados desde TMDB.",
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('FlixIntegrationController::librarySync error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function libraryUpdateShows(): JsonResponse
    {
        try {
            $summary = $this->librarySync->updateTrackedShows();
            return response()->json([
                'success' => true,
                'message' => "Revisión completada: {$summary['shows_updated']}/{$summary['shows_checked']} series actualizadas ({$summary['episodes_synced']} episodios sincronizados).",
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function subtitlesSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'nullable|string|max:255',
            'imdb_id' => 'nullable|string|max:32',
            'tmdb_id' => 'nullable|integer',
            'year' => 'nullable|integer',
            'season_number' => 'nullable|integer|min:0',
            'episode_number' => 'nullable|integer|min:1',
            'languages' => 'nullable',
            'preferred_language' => 'nullable|string|max:16',
            'file_path' => 'nullable|string|max:1000',
            'title_id' => 'nullable|integer',
        ]);

        if (!empty($validated['title_id']) && empty($validated['file_path'])) {
            $title = Title::find((int) $validated['title_id']);
            if ($title) {
                $validated['file_path'] = $title->file_path ?: $title->source_path;
                $validated['imdb_id'] = $validated['imdb_id'] ?? $title->imdb_id;
                $validated['tmdb_id'] = $validated['tmdb_id'] ?? (is_numeric($title->tmdb_id) ? (int) $title->tmdb_id : null);
                $validated['query'] = $validated['query'] ?? $title->name;
                $validated['year'] = $validated['year'] ?? $title->release_year;
            }
        }

        try {
            $res = $this->openSubtitles->searchSubtitles($validated);
            return response()->json(array_merge(['success' => true], $res));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'results' => [],
            ], 422);
        }
    }

    public function subtitlesDownload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_id' => 'required|integer|min:1',
            'title_id' => 'nullable|integer',
            'file_name' => 'nullable|string|max:255',
        ]);

        $targetDir = null;
        if (!empty($validated['title_id'])) {
            $title = Title::find((int) $validated['title_id']);
            $mediaPath = $title?->file_path ?: $title?->source_path;
            if ($mediaPath && is_dir(dirname($mediaPath))) {
                $targetDir = dirname($mediaPath);
            }
        }

        try {
            $result = $this->openSubtitles->downloadSubtitle(
                (int) $validated['file_id'],
                $targetDir,
                $validated['file_name'] ?? null
            );
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'all');
        $page = max(1, (int) $request->query('page', 1));
        $data = $this->searchHistory->getPage($type, $page, $request->user()?->id);

        return response()->json(array_merge(['success' => true], $data));
    }

    public function deleteHistoryEntry(Request $request, int $id): JsonResponse
    {
        $deleted = $this->searchHistory->deleteById($id, $request->user()?->id);
        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Entrada eliminada del historial.' : 'Entrada no encontrada.',
        ]);
    }

    public function clearHistory(Request $request): JsonResponse
    {
        $count = $this->searchHistory->clear($request->input('type'), $request->user()?->id);
        return response()->json([
            'success' => true,
            'deleted' => $count,
            'message' => 'Historial de búsquedas limpiado.',
        ]);
    }

    public function clearCache(): JsonResponse
    {
        $cleared = $this->tmdb->clearCache();
        return response()->json([
            'success' => true,
            'cleared_keys' => $cleared,
            'message' => "Caché del motor Flix (TMDB) purgada ({$cleared} entradas).",
        ]);
    }

    protected function markLocalAvailability(array $items): array
    {
        $tmdbIds = array_values(array_filter(array_map(static fn ($i) => $i['tmdb_id'] ?? null, $items)));
        if (empty($tmdbIds)) {
            return $items;
        }

        $existingMap = Title::whereIn('tmdb_id', $tmdbIds)
            ->get(['id', 'tmdb_id', 'type', 'file_path', 'stream_path'])
            ->keyBy(static fn ($t) => $t->type . ':' . $t->tmdb_id);

        foreach ($items as &$item) {
            $key = ($item['type'] ?? 'movie') . ':' . ($item['tmdb_id'] ?? '');
            $match = $existingMap->get($key);
            $item['in_library'] = $match !== null;
            $item['local_title_id'] = $match?->id;
            $item['has_local_media'] = $match !== null && (!empty($match->file_path) || !empty($match->stream_path));
        }

        return $items;
    }
}
