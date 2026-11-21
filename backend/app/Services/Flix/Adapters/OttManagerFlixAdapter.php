<?php

namespace AppServicesFlixAdapters;

use AppModelsEpisode;
use AppModelsTitle;
use AppServicesFlixContractsFlixProviderContract;
use AppServicesFlixDTOFlixProviderResult;
use AppServicesOttContractsOttProviderInterface;

/**
 * Adaptador puente que expone cualquier OttProviderInterface existente en FlixHN
 * bajo el contrato de dos fases de plugin.video.flix (FlixProviderContract).
 */
class OttManagerFlixAdapter implements FlixProviderContract
{
    public function __construct(
        protected OttProviderInterface $ottAdapter
    ) {}

    public function getId(): string
    {
        return 'flix.provider.' . $this->ottAdapter->getSlug();
    }

    public function getName(): string
    {
        return $this->ottAdapter->getName();
    }

    public function ping(): array
    {
        $health = $this->ottAdapter->checkHealth();
        return [
            'id' => $this->getId(),
            'slug' => $this->ottAdapter->getSlug(),
            'name' => $this->getName(),
            'integration_type' => $this->ottAdapter->getIntegrationType(),
            'online' => (bool) ($health['success'] ?? false),
            'latency_ms' => $health['latency_ms'] ?? null,
            'message' => $health['message'] ?? '',
        ];
    }

    public function search(string $query): array
    {
        $raw = $this->ottAdapter->search($query, ['limit' => 15]);
        return $this->mapOttItems($raw);
    }

    public function searchMovie(string $tmdbId, string $title, array $alternativeTitles = [], ?int $year = null): array
    {
        if ($this->ottAdapter->getSlug() === 'local' && is_numeric($tmdbId)) {
            $localTitle = Title::where('type', 'movie')->where('tmdb_id', (int) $tmdbId)->first();
            if ($localTitle) {
                return [
                    new FlixProviderResult(
                        provider: $this->getId(),
                        label: $localTitle->name . ($localTitle->release_year ? " ({$localTitle->release_year})" : ''),
                        label2: 'Catálogo Local On-Net (TMDB Match)',
                        icon: $localTitle->poster_url,
                        url: $localTitle->stream_url ?: "/api/stream/title/{$localTitle->id}",
                        providerData: ['content_id' => (string) $localTitle->id, 'type' => 'movie', 'title' => $localTitle->name],
                        playbackType: 'LOCAL-HLS',
                        requiresExternalApp: false,
                        metadata: ['title_id' => $localTitle->id, 'tmdb_id' => $localTitle->tmdb_id]
                    ),
                ];
            }
        }

        $queries = array_values(array_unique(array_filter([
            $alternativeTitles['auto'] ?? null,
            $alternativeTitles['es'] ?? null,
            $title,
        ])));

        foreach ($queries as $q) {
            $items = $this->ottAdapter->search($q, ['type' => 'movie', 'limit' => 8]);
            if (!empty($items)) {
                return $this->mapOttItems($items);
            }
        }

        return [];
    }

    public function searchShow(string $tmdbId, string $showTitle, array $alternativeTitles = [], ?int $year = null): array
    {
        if ($this->ottAdapter->getSlug() === 'local' && is_numeric($tmdbId)) {
            $localShow = Title::where('type', 'series')->where('tmdb_id', (int) $tmdbId)->first();
            if ($localShow) {
                return [
                    new FlixProviderResult(
                        provider: $this->getId(),
                        label: $localShow->name,
                        label2: 'Serie Local On-Net (TMDB Match)',
                        icon: $localShow->poster_url,
                        url: null,
                        providerData: ['content_id' => (string) $localShow->id, 'type' => 'series', 'title' => $localShow->name],
                        playbackType: 'LOCAL-HLS',
                        requiresExternalApp: false,
                        metadata: ['title_id' => $localShow->id, 'tmdb_id' => $localShow->tmdb_id]
                    ),
                ];
            }
        }

        $queries = array_values(array_unique(array_filter([
            $alternativeTitles['auto'] ?? null,
            $alternativeTitles['es'] ?? null,
            $showTitle,
        ])));

        foreach ($queries as $q) {
            $items = $this->ottAdapter->search($q, ['type' => 'series', 'limit' => 8]);
            if (!empty($items)) {
                return $this->mapOttItems($items);
            }
        }

        return [];
    }

    public function searchSeason(string $tmdbId, string $showTitle, int $seasonNumber, array $alternativeTitles = []): array
    {
        return $this->searchShow($tmdbId, $showTitle, $alternativeTitles);
    }

    public function searchEpisode(string $tmdbId, string $showTitle, int $seasonNumber, int $episodeNumber, array $alternativeTitles = []): array
    {
        if ($this->ottAdapter->getSlug() === 'local') {
            $titleQuery = Title::where('type', 'series');
            if (is_numeric($tmdbId)) {
                $titleQuery->where(function ($q) use ($tmdbId, $showTitle) {
                    $q->where('tmdb_id', (int) $tmdbId)->orWhere('name', 'LIKE', "%{$showTitle}%");
                });
            } else {
                $titleQuery->where('name', 'LIKE', "%{$showTitle}%");
            }

            $show = $titleQuery->first();
            if ($show) {
                $episode = Episode::where('title_id', $show->id)
                    ->whereHas('season', static fn ($sq) => $sq->where('season_number', $seasonNumber))
                    ->where('episode_number', $episodeNumber)
                    ->first();

                if ($episode) {
                    $code = sprintf('S%02dE%02d', $seasonNumber, $episodeNumber);
                    return [
                        new FlixProviderResult(
                            provider: $this->getId(),
                            label: "{$show->name} {$code} - {$episode->title}",
                            label2: 'Episodio Local On-Net',
                            icon: $episode->thumbnail_url ?: $show->poster_url,
                            url: $episode->stream_url ?: "/api/stream/episode/{$episode->id}",
                            providerData: [
                                'content_id' => (string) $episode->id,
                                'type' => 'episode',
                                'title' => $episode->title,
                            ],
                            playbackType: 'LOCAL-HLS',
                            requiresExternalApp: false,
                            metadata: [
                                'title_id' => $show->id,
                                'episode_id' => $episode->id,
                                'season' => $seasonNumber,
                                'episode' => $episodeNumber,
                            ]
                        ),
                    ];
                }
            }
        }

        $epQuery = sprintf('%s S%02dE%02d', $showTitle, $seasonNumber, $episodeNumber);
        $items = $this->ottAdapter->search($epQuery, ['type' => 'series', 'limit' => 5]);
        if (empty($items)) {
            $items = $this->ottAdapter->search($showTitle, ['type' => 'series', 'limit' => 5]);
        }

        return $this->mapOttItems($items);
    }

    public function resolve(mixed $providerData): array
    {
        $data = is_array($providerData) ? $providerData : ['content_id' => (string) $providerData];
        $contentId = (string) ($data['content_id'] ?? $data['id'] ?? '');

        if ($contentId === '') {
            return [
                'success' => false,
                'action' => 'unavailable',
                'message' => 'provider_data no contiene un content_id válido.',
            ];
        }

        return $this->ottAdapter->getStreamLink($contentId, [
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? null,
            'original_title' => $data['original_title'] ?? null,
            'web_url' => $data['web_url'] ?? null,
            'app_url' => $data['app_url'] ?? null,
            'poster_url' => $data['poster_url'] ?? null,
        ]);
    }

    protected function mapOttItems(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $title = (string) ($item['title'] ?? $item['name'] ?? 'Sin título');
            $year = !empty($item['release_year']) ? " ({$item['release_year']})" : '';
            $requiresExternal = (bool) ($item['requires_external_app'] ?? false);
            $directUrl = (!$requiresExternal && !empty($item['stream_url'])) ? (string) $item['stream_url'] : null;

            $result = new FlixProviderResult(
                provider: $this->getId(),
                label: $title . $year,
                label2: trim(($item['provider_name'] ?? $this->getName()) . ' • ' . ($item['playback_type'] ?? 'VOD')),
                icon: $item['poster_url'] ?? null,
                url: $directUrl,
                providerData: [
                    'content_id' => (string) ($item['id'] ?? ''),
                    'type' => $item['type'] ?? 'movie',
                    'title' => $title,
                    'original_title' => $item['original_title'] ?? $title,
                    'web_url' => $item['external_url'] ?? $item['web_url'] ?? null,
                    'app_url' => $item['app_scheme'] ?? $item['app_url'] ?? null,
                    'poster_url' => $item['poster_url'] ?? null,
                ],
                playbackType: (string) ($item['playback_type'] ?? ($requiresExternal ? 'OTT-EXTERNAL' : 'LOCAL-HLS')),
                requiresExternalApp: $requiresExternal,
                appUrl: $item['app_scheme'] ?? $item['app_url'] ?? null,
                webUrl: $item['external_url'] ?? $item['web_url'] ?? null,
                metadata: $item
            );

            if ($result->isValid()) {
                $results[] = $result;
            }
        }
        return $results;
    }
}
