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

        $titleRecord = is_numeric($cleanId) ? Title::find((int)$cleanId) : null;
        $titleName = $titleRecord?->name ?? "Contenido {$this->config->name} ({$cleanId})";
        $externalId = $titleRecord?->imdb_id ?: ($titleRecord?->tmdb_id ?: $cleanId);

        // Si el proveedor soporta streaming directo (HLS/DASH)
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

        // Si es integración deep-link / aplicación externa
        $appSchemeTemplate = $playbackConfig['app_scheme'] ?? "{$this->config->slug}://watch/{id}";
        $webUrlTemplate = $playbackConfig['web_url_template'] ?? "https://www.{$this->config->slug}.com/watch/{id}";

        $appScheme = str_replace('{id}', $externalId, $appSchemeTemplate);
        $webUrl = str_replace('{id}', $externalId, $webUrlTemplate);

        // Iniciar descarga e ingesta de fondo en FlixGrab Engine para reproducción progresiva
        try {
            $downloadService = app(\App\Services\DownloadService::class);
            $job = $downloadService->requestDownload([
                'content_id' => (string) $contentId,
                'title' => $titleName,
                'provider' => $this->config->slug,
                'source_url' => $webUrl,
                'priority' => 1,
            ]);

            $directProgUrl = url("/api/streaming/progressive/{$job->id}");
            return [
                'success' => true,
                'content_id' => $contentId,
                'external_id' => (string) $externalId,
                'title' => $titleName,
                'provider' => $this->config->slug,
                'provider_name' => $this->config->name,
                'playback_type' => $job->status === 'READY' ? 'LOCAL-DIRECT' : 'PROGRESSIVE-HLS',
                'direct_playback' => true,
                'requires_external_app' => false,
                'status' => $job->status,
                'job_id' => $job->job_id,
                'download_job_id' => $job->id,
                'stream_url' => $job->status === 'READY' ? $directProgUrl : url("/api/streaming/hls/{$job->job_id}/playlist.m3u8"),
                'progressive_url' => $directProgUrl,
                'direct_url' => $directProgUrl,
                'can_play_progressive' => (bool) $job->can_play_progressive,
                'progress' => (float) $job->progress,
                'download_speed' => $job->download_speed,
                'eta' => $job->eta,
                'app_url' => $appScheme,
                'web_url' => $webUrl,
                'brand_color' => $playbackConfig['brand_color'] ?? '#6366F1',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("GenericAdapter: Error solicitando descarga FlixGrab: " . $e->getMessage());
        }

        return [
            'success' => true,
            'content_id' => $contentId,
            'external_id' => (string) $externalId,
            'title' => $titleName,
            'provider' => $this->config->slug,
            'provider_name' => $this->config->name,
            'playback_type' => 'PROGRESSIVE-HLS',
            'direct_playback' => true,
            'requires_external_app' => false,
            'app_url' => $appScheme,
            'web_url' => $webUrl,
            'brand_color' => $playbackConfig['brand_color'] ?? '#6366F1',
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
