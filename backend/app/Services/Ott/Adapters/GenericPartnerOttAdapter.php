<?php

namespace App\Services\Ott\Adapters;

use App\Models\OttProviderConfig;
use App\Models\Title;
use App\Services\Ott\Contracts\OttProviderInterface;
use App\Services\Ott\Security\SsrfValidator;
use App\Services\Ott\Security\StreamTokenSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenericPartnerOttAdapter implements OttProviderInterface
{
    protected OttProviderConfig $config;

    public function __construct(OttProviderConfig $config)
    {
        $this->config = $config;
    }

    public function getSlug(): string
    {
        return $this->config->slug;
    }

    public function getName(): string
    {
        return $this->config->name;
    }

    public function getIntegrationType(): string
    {
        return $this->config->integration_type;
    }

    public function search(string $query, array $options = []): array
    {
        $cleanQuery = trim($query);
        if (mb_strlen($cleanQuery) < 1) {
            return [];
        }

        $limit = min(30, max(1, (int)($options['limit'] ?? 15)));

        // Buscar títulos asociados al slug de este proveedor
        $matching = Title::query()
            ->where('provider', $this->config->slug)
            ->where(function ($w) use ($cleanQuery) {
                $w->where('name', 'LIKE', "%{$cleanQuery}%")
                  ->orWhere('description', 'LIKE', "%{$cleanQuery}%");
            })
            ->take($limit)
            ->get();

        $playbackConfig = $this->config->playback_config ?: [];
        $isDirect = !empty($playbackConfig['direct_playback']);
        $appSchemeTemplate = $playbackConfig['app_scheme'] ?? "{$this->config->slug}://watch/{id}";
        $webUrlTemplate = $playbackConfig['web_url_template'] ?? "https://www.{$this->config->slug}.com/watch/{id}";

        $results = [];
        foreach ($matching as $item) {
            $externalId = $item->imdb_id ?: ($item->tmdb_id ?: $item->id);
            $appScheme = str_replace('{id}', $externalId, $appSchemeTemplate);
            $webUrl = str_replace('{id}', $externalId, $webUrlTemplate);

            $results[] = [
                'id' => "{$this->config->slug}_{$item->id}",
                'external_id' => (string) $externalId,
                'provider' => $this->config->slug,
                'provider_name' => $this->config->name,
                'title' => $item->name,
                'original_title' => $item->name,
                'type' => ($item->type === 'series') ? 'series' : 'movie',
                'release_year' => (int) ($item->release_year ?: date('Y')),
                'genre' => $item->genre ?: 'Catálogo OTT',
                'overview' => $item->description ?: "Contenido oficial distribuido por {$this->config->name}.",
                'poster_url' => $item->poster_url ?: "/api/media/poster?title=" . urlencode($item->name),
                'backdrop_url' => $item->backdrop_url ?: "/api/media/backdrop?title=" . urlencode($item->name),
                'duration_seconds' => (int) ($item->duration_seconds ?: 5400),
                'playback_type' => $isDirect ? 'OTT-HLS-AUTHORIZED' : 'OTT-EXTERNAL',
                'requires_external_app' => !$isDirect,
                'is_available' => true,
                'app_scheme' => $appScheme,
                'external_url' => $webUrl,
                'brand_color' => $playbackConfig['brand_color'] ?? '#6366F1',
            ];
        }

        return $results;
    }

    public function getStreamLink(string $contentId, array $context = []): array
    {
        $playbackConfig = $this->config->playback_config ?: [];
        $isDirect = !empty($playbackConfig['direct_playback']);
        $prefix = "{$this->config->slug}_";
        $cleanId = str_starts_with($contentId, $prefix) ? substr($contentId, strlen($prefix)) : $contentId;
        if (str_starts_with($cleanId, 'ott_')) {
            $parts = explode('_', $cleanId);
            $cleanId = end($parts) ?: $cleanId;
        }

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
        }
        $titleName = $titleRecord?->name ?? ($context['title'] ?? "Contenido {$this->config->name} ({$cleanId})");
        $externalId = $titleRecord?->imdb_id ?: ($titleRecord?->tmdb_id ?: $cleanId);

        // Si el proveedor soporta streaming directo autorizado (HLS/DASH)
        if ($isDirect) {
            $ttl = (int) ($playbackConfig['token_expiry_seconds'] ?? 7200);
            $clientIp = $context['client_ip'] ?? request()->ip();

            $rawStreamUrl = $titleRecord?->stream_path
                ?: ($this->config->api_endpoint ? "{$this->config->api_endpoint}/streams/{$externalId}.m3u8" : "/api/stream/title/{$cleanId}");

            $signedUrl = StreamTokenSigner::signUrl($rawStreamUrl, $contentId, $ttl, $clientIp);

            return [
                'success' => true,
                'content_id' => $contentId,
                'external_id' => (string) $externalId,
                'title' => $titleName,
                'provider' => $this->config->slug,
                'provider_name' => $this->config->name,
                'playback_type' => 'OTT-HLS-AUTHORIZED',
                'stream_url' => $signedUrl,
                'mime_type' => 'application/x-mpegURL',
                'action' => 'play',
                'direct_playback' => true,
                'requires_external_app' => false,
                'disclaimer' => "Transmisión autorizada desde la red del proveedor {$this->config->name}.",
            ];
        }

        // 1. Verificar archivo físico local o en Emby antes de redirigir a la plataforma oficial
        $resolver = app(\App\Services\RealMediaSourceResolver::class);
        if ($titleRecord && $titleRecord->type === 'series') {
            $firstEp = $titleRecord->episodes()->whereNotNull('file_path')->first();
            if ($firstEp && !empty($firstEp->file_path)) {
                $diskEp = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($firstEp->file_path) ?: $firstEp->file_path;
                if (file_exists($diskEp) && !$resolver->isWrongOrLegacyFile($diskEp, $titleName) && filesize($diskEp) > 2097152) {
                    return [
                        'success' => true,
                        'content_id' => $contentId,
                        'title' => $titleName,
                        'provider' => $this->config->slug,
                        'provider_name' => $this->config->name,
                        'playback_type' => 'LOCAL-HLS',
                        'direct_playback' => true,
                        'requires_external_app' => false,
                        'stream_url' => url("/api/stream/episode/{$firstEp->id}"),
                    ];
                }
            }
        }

        $localMedia = $titleRecord?->file_path ?: $titleRecord?->source_path;
        if ($titleRecord && !empty($localMedia)) {
            $diskP = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($localMedia) ?: $localMedia;
            if (file_exists($diskP) && !$resolver->isWrongOrLegacyFile($diskP, $titleName) && filesize($diskP) > 2097152) {
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => $titleName,
                    'provider' => $this->config->slug,
                    'provider_name' => $this->config->name,
                    'playback_type' => 'LOCAL-HLS',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'stream_url' => url("/api/stream/title/{$titleRecord->id}"),
                ];
            }
        }

        $resolvedLocal = $resolver->resolveRealSource($titleName, $context['type'] ?? $titleRecord?->type ?? null, null, $this->config->slug);
        if ($resolvedLocal) {
            if (!empty($resolvedLocal['title_id'])) {
                $streamEndpoint = !empty($resolvedLocal['episode_id'])
                    ? url("/api/stream/episode/{$resolvedLocal['episode_id']}")
                    : url("/api/stream/title/{$resolvedLocal['title_id']}");
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => $resolvedLocal['matched_title'] ?? $titleName,
                    'provider' => $this->config->slug,
                    'provider_name' => $this->config->name,
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
                    'provider' => $this->config->slug,
                    'provider_name' => $this->config->name,
                    'playback_type' => 'LOCAL-DIRECT',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'stream_url' => $resolvedLocal['video_url'],
                ];
            }
        }

        // 2. Resolver enlace oficial real en la plataforma vinculada (Prime Video, Apple TV+, Paramount+, etc.)
        $officialLinks = \App\Services\OttSyncService::resolveOfficialWatchUrl(
            $titleName,
            $this->config->slug,
            $context['web_url'] ?? null,
            $context['app_url'] ?? null
        );

        $brandColor = $playbackConfig['brand_color'] ?? (
            str_contains($this->config->slug, 'prime') || str_contains($this->config->slug, 'amazon')
                ? '#00A8E1'
                : '#6366F1'
        );

        return [
            'success' => true,
            'content_id' => $contentId,
            'external_id' => (string) $externalId,
            'title' => $titleName,
            'provider' => $this->config->slug,
            'provider_name' => $this->config->name,
            'playback_type' => 'OTT-EXTERNAL',
            'direct_playback' => false,
            'requires_external_app' => true,
            'action' => 'redirect_external',
            'app_url' => $officialLinks['app_url'],
            'web_url' => $officialLinks['web_url'],
            'brand_color' => $brandColor,
            'poster_url' => $context['poster_url'] ?? $titleRecord?->poster_url,
            'disclaimer' => "Este título pertenece al catálogo oficial de {$this->config->name} vinculado en tu cuenta OTT Sync y no está almacenado en el servidor local. Debido a la protección DRM oficial (Widevine/FairPlay) de {$this->config->name}, ábrelo directamente en su aplicación o portal oficial.",
        ];
    }

    public function checkHealth(): array
    {
        $start = microtime(true);
        $endpoint = $this->config->api_endpoint;

        if (empty($endpoint)) {
            return [
                'success' => true,
                'status' => 'connected',
                'latency_ms' => 0,
                'message' => "Proveedor {$this->config->name} configurado en modo deep link local.",
            ];
        }

        $ssrf = SsrfValidator::validate($endpoint);
        if (!$ssrf['safe']) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => 0,
                'message' => "Endpoint rechazado por SSRF: {$ssrf['reason']}",
            ];
        }

        try {
            $timeout = $this->config->timeout_seconds ?: 8;
            $res = Http::timeout($timeout)->connectTimeout(3)->head($endpoint);
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => true,
                'status' => 'connected',
                'latency_ms' => $latency,
                'message' => "Endpoint oficial de {$this->config->name} respondió satisfactoriamente.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'message' => "No se pudo contactar el endpoint oficial: {$e->getMessage()}",
            ];
        }
    }
}
