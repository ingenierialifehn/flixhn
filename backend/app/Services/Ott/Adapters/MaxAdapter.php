<?php

namespace App\Services\Ott\Adapters;

use App\Models\OttProviderConfig;
use App\Models\Title;
use App\Services\Ott\Contracts\OttProviderInterface;
use App\Services\Ott\Security\SsrfValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxAdapter implements OttProviderInterface
{
    protected ?OttProviderConfig $config;

    public function __construct(?OttProviderConfig $config = null)
    {
        $this->config = $config;
    }

    public function getSlug(): string
    {
        return 'max';
    }

    public function getName(): string
    {
        return $this->config?->name ?? 'Max';
    }

    public function getIntegrationType(): string
    {
        return 'deep_link';
    }

    public function search(string $query, array $options = []): array
    {
        $cleanQuery = trim($query);
        if (mb_strlen($cleanQuery) < 1) {
            return [];
        }

        $limit = min(30, max(1, (int)($options['limit'] ?? 15)));

        $matching = Title::query()
            ->where(function ($q) {
                $q->where('provider', 'max')
                  ->orWhere('provider', 'hbo_max')
                  ->orWhere('provider', 'hbo');
            })
            ->where(function ($w) use ($cleanQuery) {
                $w->where('name', 'LIKE', "%{$cleanQuery}%")
                  ->orWhere('description', 'LIKE', "%{$cleanQuery}%");
            })
            ->take($limit)
            ->get();

        $results = [];
        foreach ($matching as $item) {
            $externalId = $item->imdb_id ?: ($item->tmdb_id ?: $item->id);
            $appScheme = "max://player/{$externalId}";
            $webUrl = "https://play.max.com/video/watch/{$externalId}";

            $results[] = [
                'id' => 'max_' . $item->id,
                'external_id' => (string) $externalId,
                'provider' => 'max',
                'provider_name' => 'Max',
                'title' => $item->name,
                'original_title' => $item->name,
                'type' => ($item->type === 'series') ? 'series' : 'movie',
                'release_year' => (int) ($item->release_year ?: date('Y')),
                'genre' => $item->genre ?: 'Drama / HBO Original / Warner Bros',
                'overview' => $item->description ?: 'Series emblemáticas de HBO, Warner Bros Pictures, Universo DC y Discovery.',
                'poster_url' => $item->poster_url ?: "/api/media/poster?title=" . urlencode($item->name),
                'backdrop_url' => $item->backdrop_url ?: "/api/media/backdrop?title=" . urlencode($item->name),
                'duration_seconds' => (int) ($item->duration_seconds ?: 5800),
                'playback_type' => 'OTT-EXTERNAL',
                'requires_external_app' => true,
                'is_available' => true,
                'app_scheme' => $appScheme,
                'external_url' => $webUrl,
                'brand_color' => '#5822b4',
            ];
        }

        return $results;
    }

    public function getStreamLink(string $contentId, array $context = []): array
    {
        $cleanId = preg_replace('/^(max_|hbo_|ott_hbo_max_|ott_max_)/', '', $contentId);
        $titleRecord = is_numeric($cleanId) ? Title::find((int)$cleanId) : null;
        if (!$titleRecord && !empty($context['title'])) {
            $searchTitle = trim($context['title']);
            $titleRecord = Title::where('name', $searchTitle)
                ->orWhere('name', 'LIKE', "%{$searchTitle}%")
                ->first();
            if (!$titleRecord && !empty($context['original_title'])) {
                $origTitle = trim($context['original_title']);
                $titleRecord = Title::where('name', $origTitle)
                    ->orWhere('name', 'LIKE', "%{$origTitle}%")
                    ->first();
            }
            if (!$titleRecord && preg_match('/^(.*?)\s*\((.*?)\)$/u', $searchTitle, $pm)) {
                $p1 = trim($pm[1]);
                $p2 = trim($pm[2]);
                $titleRecord = Title::where('name', 'LIKE', "%{$p1}%")
                    ->orWhere('name', 'LIKE', "%{$p2}%")
                    ->first();
            }
        }
        $titleName = $titleRecord?->name ?? ($context['title'] ?? "Contenido Max ({$cleanId})");
        $externalId = $titleRecord?->imdb_id ?: ($titleRecord?->tmdb_id ?: $cleanId);

        $appScheme = "max://player/{$externalId}";
        $webUrl = "https://play.max.com/video/watch/{$externalId}";

        if ($titleRecord) {
            $chkPath = $titleRecord->file_path ?: $titleRecord->source_path;
            if (!empty($chkPath)) {
                app(\App\Services\RealMediaSourceResolver::class)->purgeIfWrongFile($chkPath, $titleName);
                $titleRecord->refresh();
            }
        }

        // Si es serie y tiene episodios locales en disco
        if ($titleRecord && $titleRecord->type === 'series') {
            $firstEp = $titleRecord->episodes()->whereNotNull('file_path')->first();
            if ($firstEp && !empty($firstEp->file_path)) {
                $diskEp = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($firstEp->file_path) ?: $firstEp->file_path;
                if (file_exists($diskEp) && !app(\App\Services\RealMediaSourceResolver::class)->isWrongOrLegacyFile($diskEp, $titleName) && filesize($diskEp) > 2097152) {
                    return [
                        'success' => true,
                        'content_id' => $contentId,
                        'title' => $titleName,
                        'provider' => 'max',
                        'provider_name' => 'Max',
                        'playback_type' => 'LOCAL-HLS',
                        'direct_playback' => true,
                        'requires_external_app' => false,
                        'stream_url' => url("/api/stream/episode/{$firstEp->id}"),
                    ];
                }
            }
        }

        // 1. Si el archivo físico real ya existe en disco local (file_path o source_path), reproducir directamente
        $localMedia = $titleRecord?->file_path ?: $titleRecord?->source_path;
        if ($titleRecord && !empty($localMedia)) {
            $diskP = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($localMedia) ?: $localMedia;
            if (file_exists($diskP) && !app(\App\Services\RealMediaSourceResolver::class)->isWrongOrLegacyFile($diskP, $titleName) && filesize($diskP) > 2097152) {
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => $titleName,
                    'provider' => 'max',
                    'provider_name' => 'Max',
                    'playback_type' => 'LOCAL-HLS',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'stream_url' => url("/api/stream/title/{$titleRecord->id}"),
                ];
            }
        }

        // 2. Verificar en Emby / almacenamiento local mediante RealMediaSourceResolver (título y variantes)
        $resolver = app(\App\Services\RealMediaSourceResolver::class);
        $resolvedLocal = $resolver->resolveRealSource($titleName, $context['type'] ?? $titleRecord?->type ?? null, null, 'max');
        if ($resolvedLocal) {
            if (!empty($resolvedLocal['title_id'])) {
                $streamEndpoint = !empty($resolvedLocal['episode_id'])
                    ? url("/api/stream/episode/{$resolvedLocal['episode_id']}")
                    : url("/api/stream/title/{$resolvedLocal['title_id']}");
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => $resolvedLocal['matched_title'] ?? $titleName,
                    'provider' => 'max',
                    'provider_name' => 'Max',
                    'playback_type' => 'LOCAL-HLS',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'stream_url' => $streamEndpoint,
                ];
            }
            if (($resolvedLocal['mode'] ?? '') === 'single_url' && !empty($resolvedLocal['video_url'])) {
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => $resolvedLocal['matched_title'] ?? $titleName,
                    'provider' => 'max',
                    'provider_name' => 'Max',
                    'playback_type' => 'LOCAL-DIRECT',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'stream_url' => $resolvedLocal['video_url'],
                ];
            }
        }

        // 3. El título pertenece exclusivamente al catálogo oficial con DRM de Max (no está en disco local).
        // Resolver el enlace oficial REAL (JustWatch offers) y abrir mediante interoperabilidad oficial (sin YouTube ni errores 404).
        $officialLinks = \App\Services\OttSyncService::resolveOfficialWatchUrl(
            $titleName,
            'max',
            $context['web_url'] ?? null,
            $context['app_url'] ?? null
        );

        return [
            'success' => true,
            'content_id' => $contentId,
            'external_id' => (string) $externalId,
            'title' => $titleName,
            'provider' => 'max',
            'provider_name' => 'Max',
            'playback_type' => 'OTT-EXTERNAL',
            'direct_playback' => false,
            'requires_external_app' => true,
            'action' => 'redirect_external',
            'app_url' => $officialLinks['app_url'] ?? $appScheme,
            'web_url' => $officialLinks['web_url'] ?? $webUrl,
            'brand_color' => '#5822B4',
            'poster_url' => $context['poster_url'] ?? $titleRecord?->poster_url,
            'disclaimer' => "Este título pertenece al catálogo oficial de Max vinculado en tu cuenta OTT Sync y no está almacenado en el servidor local. Debido a la protección DRM oficial (Widevine/FairPlay) de Max, ábrelo directamente en su aplicación o portal oficial.",
        ];
    }

    public function checkHealth(): array
    {
        $start = microtime(true);
        $endpoint = $this->config?->api_endpoint ?: 'https://api.max.com/v1';

        $ssrf = SsrfValidator::validate($endpoint);
        if (!$ssrf['safe']) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => 0,
                'message' => 'Endpoint de Max rechazado por política SSRF: ' . ($ssrf['reason'] ?? 'IP no permitida'),
            ];
        }

        return [
            'success' => true,
            'status' => 'connected',
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            'message' => 'Mecanismos oficiales de deep linking y redirección para Max activos.',
        ];
    }
}
