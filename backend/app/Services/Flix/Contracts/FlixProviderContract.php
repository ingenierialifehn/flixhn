<?php

namespace AppServicesFlixContracts;

use AppServicesFlixDTOFlixProviderResult;

/**
 * Contrato de Proveedor portado desde plugin.video.flix (lib/api/flix/provider.py::Provider).
 */
interface FlixProviderContract
{
    public function getId(): string;

    public function getName(): string;

    public function ping(): array;

    /** @return FlixProviderResult[] */
    public function search(string $query): array;

    /** @return FlixProviderResult[] */
    public function searchMovie(string $tmdbId, string $title, array $alternativeTitles = [], ?int $year = null): array;

    /** @return FlixProviderResult[] */
    public function searchShow(string $tmdbId, string $showTitle, array $alternativeTitles = [], ?int $year = null): array;

    /** @return FlixProviderResult[] */
    public function searchSeason(string $tmdbId, string $showTitle, int $seasonNumber, array $alternativeTitles = []): array;

    /** @return FlixProviderResult[] */
    public function searchEpisode(string $tmdbId, string $showTitle, int $seasonNumber, int $episodeNumber, array $alternativeTitles = []): array;

    public function resolve(mixed $providerData): array;
}
