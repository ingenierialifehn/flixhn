<?php

namespace AppServicesFlix;

use AppModelsCategory;
use AppModelsEpisode;
use AppModelsSeason;
use AppModelsTitle;
use IlluminateSupportFacadesLog;
use IlluminateSupportStr;

/**
 * Sincronizador de Biblioteca portado desde plugin.video.flix (lib/library.py y lib/service.py).
 */
class FlixLibrarySyncService
{
    public function __construct(
        protected TmdbCatalogService $tmdb
    ) {}

    public function syncMovie(int $tmdbId, array $options = []): array
    {
        $details = $this->tmdb->getMovieDetails($tmdbId);
        $provider = (string) ($options['provider'] ?? 'local');
        $category = $this->resolveDefaultCategory('movie');

        $existing = Title::where('tmdb_id', $tmdbId)->where('type', 'movie')->first();
        $created = false;

        $payload = [
            'name' => $details['title'],
            'type' => 'movie',
            'category_id' => $category?->id,
            'description' => $details['overview'],
            'poster_url' => $details['poster_url'],
            'backdrop_url' => $details['backdrop_url'],
            'release_year' => $details['release_year'],
            'release_date' => $details['release_date'] ?: null,
            'genre' => implode(', ', $details['genres']),
            'duration_seconds' => $details['duration_seconds'],
            'community_rating' => $details['rating'],
            'imdb_id' => $details['imdb_id'],
            'tmdb_id' => $details['tmdb_id'],
            'cast' => $details['cast'],
            'studios' => $details['studios'],
            'country' => implode(', ', $details['countries']),
            'metadata_language' => $this->tmdb->getSettings()['language'],
        ];

        if (!$existing) {
            $payload['slug'] = $this->generateUniqueSlug($details['title']);
            $payload['provider'] = $provider;
            $payload['source_type'] = $provider === 'local' ? 'local' : 'ott';
            $existing = Title::create($payload);
            $created = true;
        } else {
            $this->applyMetadataRespectingLocks($existing, $payload);
        }

        return [
            'created' => $created,
            'title' => $existing->fresh(),
            'tmdb' => $details,
        ];
    }

    public function syncShow(int $tmdbId, array $options = []): array
    {
        $includeSpecials = array_key_exists('include_specials', $options)
            ? (bool) $options['include_specials']
            : (bool) config('flix.library.include_specials', false);
        $includeUnaired = array_key_exists('include_unaired', $options)
            ? (bool) $options['include_unaired']
            : (bool) config('flix.library.include_unaired_episodes', false);
        $provider = (string) ($options['provider'] ?? 'local');

        $details = $this->tmdb->getShowDetails($tmdbId, $includeUnaired);
        $category = $this->resolveDefaultCategory('series');

        $existing = Title::where('tmdb_id', $tmdbId)->where('type', 'series')->first();
        $created = false;

        $payload = [
            'name' => $details['title'],
            'type' => 'series',
            'category_id' => $category?->id,
            'description' => $details['overview'],
            'poster_url' => $details['poster_url'],
            'backdrop_url' => $details['backdrop_url'],
            'release_year' => $details['release_year'],
            'release_date' => $details['release_date'] ?: null,
            'genre' => implode(', ', $details['genres']),
            'community_rating' => $details['rating'],
            'imdb_id' => $details['imdb_id'],
            'tmdb_id' => $details['tmdb_id'],
            'tvdb_id' => $details['tvdb_id'],
            'cast' => $details['cast'],
            'studios' => $details['studios'],
            'country' => implode(', ', $details['countries']),
            'metadata_language' => $this->tmdb->getSettings()['language'],
        ];

        if (!$existing) {
            $payload['slug'] = $this->generateUniqueSlug($details['title']);
            $payload['provider'] = $provider;
            $payload['source_type'] = $provider === 'local' ? 'local' : 'ott';
            $existing = Title::create($payload);
            $created = true;
        } else {
            $this->applyMetadataRespectingLocks($existing, $payload);
        }

        $seasonsSynced = 0;
        $episodesSynced = 0;

        foreach ($details['seasons'] ?? [] as $seasonSummary) {
            $seasonNumber = (int) $seasonSummary['season_number'];
            if (!$includeSpecials && $seasonNumber === 0) {
                continue;
            }

            $seasonRecord = Season::firstOrCreate(
                ['title_id' => $existing->id, 'season_number' => $seasonNumber],
                ['title' => $seasonSummary['title'] ?: "Temporada {$seasonNumber}"]
            );
            $seasonsSynced++;

            try {
                $seasonFull = $this->tmdb->getSeasonDetails($tmdbId, $seasonNumber, $includeUnaired);
                foreach ($seasonFull['episodes'] ?? [] as $ep) {
                    $epNum = (int) $ep['episode_number'];
                    $epRecord = Episode::where('title_id', $existing->id)
                        ->where('season_id', $seasonRecord->id)
                        ->where('episode_number', $epNum)
                        ->first();

                    $epPayload = [
                        'title' => $ep['title'] ?: "Episodio {$epNum}",
                        'description' => $ep['overview'],
                        'release_date' => $ep['air_date'] ?: null,
                        'duration_seconds' => $ep['duration_seconds'] ?: 2700,
                        'community_rating' => $ep['rating'],
                        'thumbnail_url' => $ep['thumbnail_url'],
                        'tmdb_id' => $ep['tmdb_id'],
                    ];

                    if (!$epRecord) {
                        Episode::create(array_merge($epPayload, [
                            'title_id' => $existing->id,
                            'season_id' => $seasonRecord->id,
                            'episode_number' => $epNum,
                            'source_type' => $provider === 'local' ? 'local' : 'ott',
                        ]));
                        $episodesSynced++;
                    } elseif (!$epRecord->is_locked) {
                        $epRecord->update(array_filter($epPayload, static fn ($v) => $v !== null && $v !== ''));
                        $episodesSynced++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("FlixLibrarySyncService season {$seasonNumber} show {$tmdbId}: " . $e->getMessage());
            }
        }

        return [
            'created' => $created,
            'title' => $existing->fresh(['seasons.episodes']),
            'seasons_synced' => $seasonsSynced,
            'episodes_synced' => $episodesSynced,
            'tmdb' => $details,
        ];
    }

    public function updateTrackedShows(): array
    {
        $shows = Title::where('type', 'series')
            ->whereNotNull('tmdb_id')
            ->where('tmdb_id', '!=', '')
            ->get();

        $updated = 0;
        $episodesTotal = 0;
        $errors = [];

        foreach ($shows as $show) {
            if (!is_numeric($show->tmdb_id)) {
                continue;
            }
            try {
                $res = $this->syncShow((int) $show->tmdb_id, ['provider' => $show->provider ?: 'local']);
                $updated++;
                $episodesTotal += (int) ($res['episodes_synced'] ?? 0);
            } catch (\Throwable $e) {
                $errors[$show->id] = $e->getMessage();
            }
        }

        return [
            'shows_checked' => $shows->count(),
            'shows_updated' => $updated,
            'episodes_synced' => $episodesTotal,
            'errors' => $errors,
        ];
    }

    public function enrichExistingCatalog(int $limit = 25): array
    {
        if (!$this->tmdb->hasValidApiKey()) {
            return ['checked' => 0, 'enriched' => 0, 'skipped_reason' => 'TMDB API Key no configurada'];
        }

        $titles = Title::where('is_locked', false)
            ->where(function ($q) {
                $q->whereNull('tmdb_id')
                    ->orWhere('tmdb_id', '')
                    ->orWhereNull('description')
                    ->orWhere('description', '');
            })
            ->limit(max(1, $limit))
            ->get();

        $enriched = 0;
        foreach ($titles as $title) {
            try {
                $searchType = $title->type === 'series' ? 'tv' : 'movie';
                $search = $this->tmdb->search($searchType, $title->name, 1);
                $best = $search['results'][0] ?? null;
                if (!$best || empty($best['tmdb_id'])) {
                    continue;
                }

                $details = $searchType === 'tv'
                    ? $this->tmdb->getShowDetails((int) $best['tmdb_id'], false)
                    : $this->tmdb->getMovieDetails((int) $best['tmdb_id']);

                $this->applyMetadataRespectingLocks($title, [
                    'tmdb_id' => $details['tmdb_id'],
                    'imdb_id' => $details['imdb_id'] ?? null,
                    'description' => $title->description ?: ($details['overview'] ?? null),
                    'poster_url' => (empty($title->getRawOriginal('poster_url')) || str_contains((string) $title->getRawOriginal('poster_url'), '/api/media/poster'))
                        ? ($details['poster_url'] ?? null)
                        : null,
                    'backdrop_url' => (empty($title->getRawOriginal('backdrop_url')) || str_contains((string) $title->getRawOriginal('backdrop_url'), '/api/media/backdrop'))
                        ? ($details['backdrop_url'] ?? null)
                        : null,
                    'release_year' => $title->release_year ?: ($details['release_year'] ?? null),
                    'community_rating' => $title->community_rating ?: ($details['rating'] ?? null),
                    'genre' => $title->genre ?: implode(', ', $details['genres'] ?? []),
                    'cast' => !empty($title->cast) ? null : ($details['cast'] ?? null),
                    'studios' => !empty($title->studios) ? null : ($details['studios'] ?? null),
                ]);
                $enriched++;
            } catch (\Throwable $e) {
                Log::debug("FlixLibrarySyncService::enrichExistingCatalog [{$title->name}]: " . $e->getMessage());
            }
        }

        return [
            'checked' => $titles->count(),
            'enriched' => $enriched,
        ];
    }

    protected function applyMetadataRespectingLocks(Title $title, array $payload): void
    {
        if ($title->is_locked) {
            return;
        }
        $lockedFields = is_array($title->locked_fields) ? $title->locked_fields : [];
        $filtered = [];

        foreach ($payload as $field => $value) {
            if (in_array($field, $lockedFields, true)) {
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $filtered[$field] = $value;
            }
        }

        if (!empty($filtered)) {
            $title->update($filtered);
        }
    }

    protected function resolveDefaultCategory(string $type): ?Category
    {
        return Category::where('type', $type)->where('is_active', true)->orderBy('sort_order')->first()
            ?? Category::where('is_active', true)->orderBy('sort_order')->first();
    }

    protected function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: ('flix-' . time());
        $slug = $base;
        $counter = 1;
        while (Title::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }
        return $slug;
    }
}
