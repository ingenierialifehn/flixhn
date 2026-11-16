<?php

namespace App\Services\Ott\Adapters;

use App\Models\OttProviderConfig;
use App\Models\TvChannel;
use App\Services\Ott\Contracts\OttProviderInterface;
use App\Services\Ott\Security\StreamTokenSigner;
use App\Services\Ott\Security\SsrfValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthorizedHlsAdapter implements OttProviderInterface
{
    protected ?OttProviderConfig $config;

    public function __construct(?OttProviderConfig $config = null)
    {
        $this->config = $config;
    }

    public function getSlug(): string
    {
        return 'partner_hls';
    }

    public function getName(): string
    {
        return $this->config?->name ?? 'Transmisión HLS Autorizada ISP';
    }

    public function getIntegrationType(): string
    {
        return 'authorized_hls';
    }

    /**
     * Búsqueda en fuentes de streaming HLS autorizadas y canales de red.
     */
    public function search(string $query, array $options = []): array
    {
        $cleanQuery = trim($query);
        if (mb_strlen($cleanQuery) < 1) {
            return [];
        }

        $limit = min(30, max(1, (int)($options['limit'] ?? 15)));

        $channels = TvChannel::query()
            ->where('is_active', true)
            ->where(function ($w) use ($cleanQuery) {
                $w->where('name', 'LIKE', "%{$cleanQuery}%")
                  ->orWhere('category', 'LIKE', "%{$cleanQuery}%");
            })
            ->take($limit)
            ->get();

        $results = [];
        foreach ($channels as $ch) {
            $results[] = [
                'id' => 'hls_' . $ch->id,
                'provider' => 'partner_hls',
                'provider_name' => $this->getName(),
                'title' => $ch->name,
                'original_title' => $ch->name,
                'type' => 'live',
                'release_year' => (int) date('Y'),
                'genre' => $ch->category ?: 'Canal en Vivo / Streaming',
                'overview' => "Señal de transmisión HLS autorizada de alta disponibilidad On-Net ({$ch->name}).",
                'poster_url' => $ch->logo_url ?: "/api/tv-channels/{$ch->id}/logo",
                'backdrop_url' => $ch->logo_url ?: "/api/media/backdrop?title=" . urlencode($ch->name),
                'duration_seconds' => 0,
                'playback_type' => 'OTT-HLS-AUTHORIZED',
                'requires_external_app' => false,
                'is_available' => true,
                'stream_url' => "/api/livetv/proxy?channel_id={$ch->id}",
                'quality' => '1080p 60fps',
            ];
        }

        return $results;
    }

    /**
     * Resuelve y firma la URL de reproducción para la fuente HLS autorizada.
     */
    public function getStreamLink(string $contentId, array $context = []): array
    {
        $channelId = (int) str_replace('hls_', '', $contentId);
        $channel = TvChannel::find($channelId);

        $clientIp = $context['client_ip'] ?? request()->ip();
        $ttl = (int) ($this->config?->playback_config['token_expiry_seconds'] ?? 7200);

        if (!$channel) {
            // Verificar si el contentId es un stream URL directo autorizado
            if (filter_var($contentId, FILTER_VALIDATE_URL)) {
                $ssrf = SsrfValidator::validate($contentId);
                if (!$ssrf['safe']) {
                    return [
                        'success' => false,
                        'content_id' => $contentId,
                        'provider' => 'partner_hls',
                        'playback_type' => 'OTT-UNAVAILABLE',
                        'action' => 'unavailable',
                        'message' => 'Fuente rechazada por política de seguridad: ' . ($ssrf['reason'] ?? 'SSRF'),
                    ];
                }

                $signedUrl = StreamTokenSigner::signUrl($contentId, md5($contentId), $ttl, $clientIp);
                return [
                    'success' => true,
                    'content_id' => $contentId,
                    'title' => 'Fuente HLS Autorizada',
                    'provider' => 'partner_hls',
                    'provider_name' => $this->getName(),
                    'playback_type' => 'OTT-HLS-AUTHORIZED',
                    'stream_url' => $signedUrl,
                    'mime_type' => 'application/x-mpegURL',
                    'action' => 'play',
                    'direct_playback' => true,
                    'requires_external_app' => false,
                    'disclaimer' => 'Flujo de red verificado y firmado mediante token criptográfico.',
                ];
            }

            return [
                'success' => false,
                'content_id' => $contentId,
                'provider' => 'partner_hls',
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => 'La fuente HLS solicitada no fue localizada o no está activa.',
            ];
        }

        $rawUrl = $channel->stream_url ?: "/api/livetv/proxy?channel_id={$channel->id}";
        $signedUrl = StreamTokenSigner::signUrl($rawUrl, (string)$channel->id, $ttl, $clientIp);

        return [
            'success' => true,
            'content_id' => 'hls_' . $channel->id,
            'title' => $channel->name,
            'provider' => 'partner_hls',
            'provider_name' => $this->getName(),
            'playback_type' => 'OTT-HLS-AUTHORIZED',
            'stream_url' => $signedUrl,
            'mime_type' => 'application/x-mpegURL',
            'action' => 'play',
            'direct_playback' => true,
            'requires_external_app' => false,
            'disclaimer' => 'Canal de transmisión HLS en vivo firmado con token de autorización.',
        ];
    }

    public function checkHealth(): array
    {
        $start = microtime(true);
        try {
            $activeCount = TvChannel::where('is_active', true)->count();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => true,
                'status' => 'connected',
                'latency_ms' => $latency,
                'message' => "Distribuidor HLS operativo con {$activeCount} canales activos.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'message' => 'Error consultando fuentes HLS: ' . $e->getMessage(),
            ];
        }
    }
}
