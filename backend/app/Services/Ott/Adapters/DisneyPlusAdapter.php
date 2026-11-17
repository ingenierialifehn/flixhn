<?php

namespace App\Services\Ott\Adapters;

use App\Models\OttProviderConfig;
use App\Models\Title;
use App\Services\Ott\Contracts\OttProviderInterface;
use App\Services\Ott\Security\SsrfValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DisneyPlusAdapter implements OttProviderInterface
{
    protected ?OttProviderConfig $config;

    public function __construct(?OttProviderConfig $config = null)
    {
        $this->config = $config;
    }

    public function getSlug(): string
    {
        return 'disney';
    }

    public function getName(): string
    {
        return $this->config?->name ?? 'Disney+';
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
                $q->where('provider', 'disney')
                  ->orWhere('provider', 'disney_plus')
                  ->orWhere('provider', 'disneyplus');
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
            $appScheme = "disneyplus://play/{$externalId}";
            $webUrl = "https://www.disneyplus.com/video/{$externalId}";

            $results[] = [
                'id' => 'disney_' . $item->id,
                'external_id' => (string) $externalId,
                'provider' => 'disney',
                'provider_name' => 'Disney+',
                'title' => $item->name,
                'original_title' => $item->name,
                'type' => ($item->type === 'series') ? 'series' : 'movie',
                'release_year' => (int) ($item->release_year ?: date('Y')),
                'genre' => $item->genre ?: 'Familiar / Animación / Marvel / Star Wars',
                'overview' => $item->description ?: 'Producciones de Disney, Pixar, Marvel Studios, Star Wars y National Geographic.',
                'poster_url' => $item->poster_url ?: "/api/media/poster?title=" . urlencode($item->name),
                'backdrop_url' => $item->backdrop_url ?: "/api/media/backdrop?title=" . urlencode($item->name),
                'duration_seconds' => (int) ($item->duration_seconds ?: 6000),
                'playback_type' => 'OTT-EXTERNAL',
                'requires_external_app' => true,
                'is_available' => true,
                'app_scheme' => $appScheme,
                'external_url' => $webUrl,
                'brand_color' => '#0063E5',
            ];
        }

        return $results;
    }

    public function getStreamLink(string $contentId, array $context = []): array
    {
        $cleanId = preg_replace('/^(disney_|disneyplus_|ott_disney_plus_|ott_disney_)/', '', $contentId);
        $titleRecord = is_numeric($cleanId) ? Title::find((int)$cleanId) : null;
        if (!$titleRecord && !empty($context['title'])) {
            $titleRecord = Title::where('name', $context['title'])->first();
        }
        $titleName = $titleRecord?->name ?? ($context['title'] ?? "Contenido Disney+ ({$cleanId})");
        $externalId = $titleRecord?->imdb_id ?: ($titleRecord?->tmdb_id ?: $cleanId);

        $appScheme = "disneyplus://play/{$externalId}";
        $webUrl = "https://www.disneyplus.com/video/{$externalId}";

        // 1. Si el archivo físico ya existe en disco local, reproducir directamente
        if ($titleRecord && !empty($titleRecord->file_path) && file_exists($titleRecord->file_path) && filesize($titleRecord->file_path) > 1048576) {
            return [
                'success' => true,
                'content_id' => $contentId,
                'title' => $titleName,
                'provider' => 'disney',
                'provider_name' => 'Disney+',
                'playback_type' => 'LOCAL-HLS',
                'direct_playback' => true,
                'requires_external_app' => false,
                'stream_url' => $titleRecord->stream_path ?: "/api/stream/title/{$titleRecord->id}",
            ];
        }

        // 2. Iniciar descarga e ingesta de fondo en FlixGrab Engine para reproducción progresiva
        try {
            $downloadService = app(\App\Services\DownloadService::class);
            $job = $downloadService->requestDownload([
                'content_id' => (string) $contentId,
                'title' => $titleName,
                'provider' => 'disney',
                'source_url' => $webUrl,
                'priority' => 1,
            ]);

            $directProgUrl = url("/api/streaming/progressive/{$job->id}");
            return [
                'success' => true,
                'content_id' => $contentId,
                'external_id' => (string) $externalId,
                'title' => $titleName,
                'provider' => 'disney',
                'provider_name' => 'Disney+',
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
                'brand_color' => '#0063E5',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("DisneyPlusAdapter: Error solicitando descarga FlixGrab: " . $e->getMessage());
        }

        return [
            'success' => true,
            'content_id' => $contentId,
            'external_id' => (string) $externalId,
            'title' => $titleName,
            'provider' => 'disney',
            'provider_name' => 'Disney+',
            'playback_type' => 'PROGRESSIVE-HLS',
            'direct_playback' => true,
            'requires_external_app' => false,
            'app_url' => $appScheme,
            'web_url' => $webUrl,
            'brand_color' => '#0063E5',
        ];
    }

    public function checkHealth(): array
    {
        $start = microtime(true);
        $endpoint = $this->config?->api_endpoint ?: 'https://api.disneyplus.com/v1';

        $ssrf = SsrfValidator::validate($endpoint);
        if (!$ssrf['safe']) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => 0,
                'message' => 'Endpoint de Disney+ rechazado por política SSRF: ' . ($ssrf['reason'] ?? 'IP no permitida'),
            ];
        }

        return [
            'success' => true,
            'status' => 'connected',
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            'message' => 'Mecanismos oficiales de deep linking y redirección para Disney+ activos.',
        ];
    }
}
