<?php

namespace App\Services\Ott\Adapters;

use App\Models\Title;
use App\Models\MediaItem;
use App\Models\Episode;
use App\Models\OttProviderConfig;
use App\Services\Ott\Contracts\OttProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocalVodAdapter implements OttProviderInterface
{
    protected ?OttProviderConfig $config;

    public function __construct(?OttProviderConfig $config = null)
    {
        $this->config = $config;
    }

    public function getSlug(): string
    {
        return 'local';
    }

    public function getName(): string
    {
        return $this->config?->name ?? 'FlixHN Catálogo Local';
    }

    public function getIntegrationType(): string
    {
        return 'internal_vod';
    }

    /**
     * Búsqueda en el catálogo local On-Net de FlixHN.
     */
    public function search(string $query, array $options = []): array
    {
        $cleanQuery = trim($query);
        if (mb_strlen($cleanQuery) < 1) {
            return [];
        }

        $limit = min(50, max(1, (int)($options['limit'] ?? 25)));
        $typeFilter = $options['type'] ?? null;

        $queryBuilder = Title::query()
            ->select([
                'id', 'name', 'slug', 'type', 'description',
                'poster_url', 'backdrop_url', 'release_year', 'genre',
                'duration_seconds', 'stream_path', 'file_path', 'source_type', 'created_at'
            ])
            ->where(function ($w) use ($cleanQuery) {
                $w->where('name', 'LIKE', "%{$cleanQuery}%")
                  ->orWhere('description', 'LIKE', "%{$cleanQuery}%")
                  ->orWhere('genre', 'LIKE', "%{$cleanQuery}%");
            });

        if ($typeFilter && in_array($typeFilter, ['movie', 'series'])) {
            $queryBuilder->where('type', $typeFilter);
        }

        $titles = $queryBuilder->orderBy('created_at', 'desc')->take($limit)->get();

        $results = [];
        foreach ($titles as $t) {
            $mediaType = ($t->type === 'series') ? 'series' : 'movie';
            $streamPath = $t->stream_path ?: "/api/stream/title/{$t->id}";

            $results[] = [
                'id' => (string) $t->id,
                'provider' => 'local',
                'provider_name' => $this->getName(),
                'title' => $t->name,
                'original_title' => $t->name,
                'type' => $mediaType,
                'release_year' => (int) ($t->release_year ?: date('Y')),
                'genre' => $t->genre ?: 'Acción / Aventura',
                'overview' => $t->description ?: 'Contenido exclusivo del catálogo local On-Net de FlixHN.',
                'poster_url' => $t->poster_url ?: "/api/media/poster?title=" . urlencode($t->name),
                'backdrop_url' => $t->backdrop_url ?: "/api/media/backdrop?title=" . urlencode($t->name),
                'duration_seconds' => (int) ($t->duration_seconds ?: 5400),
                'playback_type' => 'LOCAL-HLS',
                'requires_external_app' => false,
                'is_available' => true,
                'stream_url' => $streamPath,
                'quality' => '1080p FHD',
            ];
        }

        return $results;
    }

    /**
     * Resuelve el enlace de reproducción local para un título o episodio.
     */
    public function getStreamLink(string $contentId, array $context = []): array
    {
        $id = (int) preg_replace('/[^0-9]/', '', $contentId);
        $isEpisode = !empty($context['type']) && $context['type'] === 'episode';

        if ($isEpisode) {
            $episode = Episode::find($id);
            if (!$episode) {
                return [
                    'success' => false,
                    'content_id' => $contentId,
                    'provider' => 'local',
                    'playback_type' => 'OTT-UNAVAILABLE',
                    'action' => 'unavailable',
                    'message' => 'El episodio local solicitado no fue encontrado en el servidor.',
                ];
            }

            $streamUrl = "/api/stream/episode/{$episode->id}";
            return [
                'success' => true,
                'content_id' => (string) $episode->id,
                'title' => $episode->name ?: "Episodio {$episode->episode_number}",
                'provider' => 'local',
                'provider_name' => $this->getName(),
                'playback_type' => 'LOCAL-HLS',
                'stream_url' => $streamUrl,
                'mime_type' => 'application/x-mpegURL',
                'action' => 'play',
                'direct_playback' => true,
                'requires_external_app' => false,
                'disclaimer' => 'Transmisión directa optimizada desde el almacenamiento local del ISP.',
            ];
        }

        $title = Title::find($id);
        if (!$title) {
            return [
                'success' => false,
                'content_id' => $contentId,
                'provider' => 'local',
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => 'El título local solicitado no existe en la base de datos.',
            ];
        }

        $streamUrl = $title->stream_path ?: "/api/stream/title/{$title->id}";

        return [
            'success' => true,
            'content_id' => (string) $title->id,
            'title' => $title->name,
            'provider' => 'local',
            'provider_name' => $this->getName(),
            'playback_type' => 'LOCAL-HLS',
            'stream_url' => $streamUrl,
            'mime_type' => 'application/x-mpegURL',
            'action' => 'play',
            'direct_playback' => true,
            'requires_external_app' => false,
            'disclaimer' => 'Transmisión directa On-Net desde el almacenamiento local de FlixHN.',
        ];
    }

    public function checkHealth(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            $count = Title::count();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => true,
                'status' => 'connected',
                'latency_ms' => $latency,
                'message' => "Base de datos local operativa. {$count} títulos indexados.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'message' => 'Error de conexión con la base de datos local: ' . $e->getMessage(),
            ];
        }
    }
}
