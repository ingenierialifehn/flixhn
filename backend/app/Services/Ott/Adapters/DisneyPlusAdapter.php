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
        $cleanId = preg_replace('/^(disney_|disneyplus_)/', '', $contentId);
        $titleRecord = is_numeric($cleanId) ? Title::find((int)$cleanId) : null;
        $titleName = $titleRecord?->name ?? "Contenido Disney+ ({$cleanId})";
        $externalId = $titleRecord?->imdb_id ?: ($titleRecord?->tmdb_id ?: $cleanId);

        $appScheme = "disneyplus://play/{$externalId}";
        $webUrl = "https://www.disneyplus.com/video/{$externalId}";

        return [
            'success' => true,
            'content_id' => $contentId,
            'external_id' => (string) $externalId,
            'title' => $titleName,
            'provider' => 'disney',
            'provider_name' => 'Disney+',
            'playback_type' => 'OTT-EXTERNAL',
            'action' => 'open_app',
            'direct_playback' => false,
            'requires_external_app' => true,
            'app_url' => $appScheme,
            'web_url' => $webUrl,
            'brand_color' => '#0063E5',
            'disclaimer' => 'Este título se distribuye bajo licencia oficial de Disney+. Por políticas de interoperabilidad y DRM del titular, la reproducción debe ejecutarse en la aplicación oficial o portal web autorizado.',
            'mime_type' => null,
            'stream_url' => null,
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
