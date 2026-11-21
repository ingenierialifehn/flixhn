<?php

namespace AppServicesFlix;

use AppServicesFlixAdaptersOttManagerFlixAdapter;
use AppServicesFlixContractsFlixProviderContract;
use AppServicesFlixDTOFlixProviderResult;
use AppServicesOttOttManager;
use IlluminateSupportFacadesCache;
use IlluminateSupportFacadesLog;

/**
 * Orquestador de Proveedores portado desde plugin.video.flix (lib/providers.py).
 */
class FlixProviderOrchestrator
{
    /** @var array<string, FlixProviderContract> */
    protected array $providers = [];
    protected bool $booted = false;

    public function __construct(
        protected OttManager $ottManager
    ) {}

    public function registerProvider(FlixProviderContract $provider): void
    {
        $this->providers[$provider->getId()] = $provider;
    }

    /** @return array<string, FlixProviderContract> */
    public function getProviders(): array
    {
        if (!$this->booted) {
            $this->booted = true;
            try {
                foreach ($this->ottManager->getActiveAdapters() as $adapter) {
                    $bridged = new OttManagerFlixAdapter($adapter);
                    $this->providers[$bridged->getId()] = $bridged;
                }
            } catch (\Throwable $e) {
                Log::warning('FlixProviderOrchestrator::getProviders error: ' . $e->getMessage());
            }
        }

        return $this->providers;
    }

    public function pingAll(): array
    {
        $statuses = [];
        foreach ($this->getProviders() as $provider) {
            try {
                $statuses[] = $provider->ping();
            } catch (\Throwable $e) {
                $statuses[] = [
                    'id' => $provider->getId(),
                    'name' => $provider->getName(),
                    'online' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $statuses;
    }

    public function searchCandidates(string $method, array $args = [], ?string $replayKey = null): array
    {
        $providers = $this->getProviders();
        $results = [];
        $errors = [];

        foreach ($providers as $providerId => $provider) {
            try {
                $providerResults = match ($method) {
                    'search' => $provider->search((string) ($args['query'] ?? '')),
                    'search_movie' => $provider->searchMovie(
                        (string) ($args['tmdb_id'] ?? ''),
                        (string) ($args['title'] ?? ''),
                        is_array($args['alternative_titles'] ?? null) ? $args['alternative_titles'] : [],
                        isset($args['year']) ? (int) $args['year'] : null
                    ),
                    'search_show' => $provider->searchShow(
                        (string) ($args['tmdb_id'] ?? ''),
                        (string) ($args['title'] ?? ''),
                        is_array($args['alternative_titles'] ?? null) ? $args['alternative_titles'] : [],
                        isset($args['year']) ? (int) $args['year'] : null
                    ),
                    'search_season' => $provider->searchSeason(
                        (string) ($args['tmdb_id'] ?? ''),
                        (string) ($args['title'] ?? ''),
                        (int) ($args['season_number'] ?? 1),
                        is_array($args['alternative_titles'] ?? null) ? $args['alternative_titles'] : []
                    ),
                    'search_episode' => $provider->searchEpisode(
                        (string) ($args['tmdb_id'] ?? ''),
                        (string) ($args['title'] ?? ''),
                        (int) ($args['season_number'] ?? 1),
                        (int) ($args['episode_number'] ?? 1),
                        is_array($args['alternative_titles'] ?? null) ? $args['alternative_titles'] : []
                    ),
                    default => throw new \InvalidArgumentException("Método de proveedor no soportado: {$method}"),
                };

                foreach ($providerResults as $item) {
                    if ($item instanceof FlixProviderResult && $item->isValid()) {
                        $results[] = $item->toArray();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("FlixProviderOrchestrator [{$providerId}::{$method}]: " . $e->getMessage());
                $errors[$providerId] = $e->getMessage();
            }
        }

        usort($results, static function (array $a, array $b): int {
            $scoreA = ($a['requires_external_app'] ?? false) ? 0 : 1;
            $scoreB = ($b['requires_external_app'] ?? false) ? 0 : 1;
            return $scoreB <=> $scoreA;
        });

        $cachedReplay = ($replayKey && config('flix.providers.save_last_result', true))
            ? Cache::get($this->replayCacheKey($replayKey))
            : null;

        return [
            'method' => $method,
            'total_providers' => count($providers),
            'total_results' => count($results),
            'results' => $results,
            'last_played' => $cachedReplay,
            'errors' => $errors,
        ];
    }

    public function resolveCandidate(string $providerId, mixed $providerData, ?string $replayKey = null): array
    {
        $providers = $this->getProviders();
        $provider = $providers[$providerId] ?? null;

        if (!$provider) {
            $prefixed = str_starts_with($providerId, 'flix.provider.') ? $providerId : ('flix.provider.' . $providerId);
            $provider = $providers[$prefixed] ?? null;
        }

        if (!$provider) {
            return [
                'success' => false,
                'action' => 'unavailable',
                'message' => "Proveedor Flix '{$providerId}' no encontrado o inactivo.",
            ];
        }

        $resolved = $provider->resolve($providerData);

        if ($replayKey && !empty($resolved['success']) && config('flix.providers.save_last_result', true)) {
            $ttlMinutes = max(5, (int) config('flix.providers.replay_ttl_minutes', 180));
            Cache::put($this->replayCacheKey($replayKey), [
                'provider' => $provider->getId(),
                'resolved' => $resolved,
                'saved_at' => now()->toIso8601String(),
            ], now()->addMinutes($ttlMinutes));
        }

        return $resolved;
    }

    protected function replayCacheKey(string $replayKey): string
    {
        return 'flix:last_played:' . hash('sha256', $replayKey);
    }
}
