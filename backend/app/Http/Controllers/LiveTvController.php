<?php

namespace App\Http\Controllers;

use App\Models\TvChannel;
use App\Models\TvSource;
use App\Models\ServerSetting;
use App\Services\RemoteMediaConnectorService;
use App\Services\MediaIntegrityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveTvController extends Controller
{
    /**
     * Lista canales para la vista de TV en Vivo (/livetv).
     * Devuelve únicamente los canales persistidos en la tabla tv_channels,
     * enriquecidos con el ID de canal de Emby si está disponible.
     */
    public function getChannels(Request $request): JsonResponse
    {
        // Sanitizar permanentemente en base de datos si existen registros con mojibake UTF-8 (ej. M├║sica -> Música)
        try {
            DB::table('tv_channels')
                ->where('group_title', 'LIKE', '%M├║sica%')
                ->orWhere('group_title', 'LIKE', '%M├ºsica%')
                ->orWhere('group_title', 'LIKE', '%MÃºsica%')
                ->update(['group_title' => 'Música']);

            TvChannel::where('group_title', 'LIKE', '%├%')
                ->orWhere('group_title', 'LIKE', '%Ã%')
                ->orWhere('name', 'LIKE', '%├%')
                ->orWhere('name', 'LIKE', '%Ã%')
                ->each(function ($channel) {
                    $fixedGroup = TvChannel::fixMojibake($channel->group_title);
                    $fixedName = TvChannel::fixMojibake($channel->name);
                    if ($fixedGroup !== $channel->group_title || $fixedName !== $channel->name) {
                        $channel->group_title = $fixedGroup;
                        $channel->name = $fixedName;
                        $channel->saveQuietly();
                    }
                });
        } catch (\Throwable $e) {
            Log::debug("LiveTvController: No se pudo auto-sanitizar DB: " . $e->getMessage());
        }

        $channels = TvChannel::where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        foreach ($channels as $channel) {
            $channel->group_title = TvChannel::fixMojibake($channel->group_title);
            $channel->name = TvChannel::fixMojibake($channel->name);
        }

        // Enriquecer con mapa de canales de Emby para facilitar negociación HLS
        try {
            $embyMap = $this->getEmbyChannelsMap();
            if (!empty($embyMap['by_id']) || !empty($embyMap['by_number']) || !empty($embyMap['by_name'])) {
                foreach ($channels as $channel) {
                    $channel->emby_channel_id = $this->resolveEmbyChannelId($channel, $embyMap);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("LiveTvController@getChannels: Fallback sin mapeo Emby: " . $e->getMessage());
        }

        return response()->json($channels);
    }

    /**
     * Alias para retrocompatibilidad
     */
    public function channels(Request $request): JsonResponse
    {
        return $this->getChannels($request);
    }

    /**
     * Consulta individual de un canal de televisión por ID (/api/channels/{id}).
     * Si no existe o fue desactivado/eliminado, purga y devuelve HTTP 410 Gone.
     */
    public function showChannel(Request $request, $id, MediaIntegrityService $integrityService): JsonResponse
    {
        $channel = null;
        if (is_numeric($id)) {
            $channel = TvChannel::find($id);
        }
        if (!$channel) {
            $channel = TvChannel::where('tvg_id', $id)->orWhere('channel_number', $id)->first();
        }

        if (!$channel || !$channel->is_active || $channel->status === 'deleted' || $channel->status === 'offline_deleted') {
            if ($channel) {
                $integrityService->purgeChannel($channel);
            }
            return $integrityService->goneResponse(is_numeric($id) ? (int)$id : null, 'channel');
        }

        return response()->json($channel);
    }

    /**
     * Endpoint para servir el logo de un canal de televisión en vivo.
     * Si el archivo local en storage no existe físicamente, busca en iptv_logos_index.json
     * o genera dinámicamente un SVG vectorizado con el nombre/siglas del canal y su paleta de color.
     * GET /api/tv-channels/{id}/logo
     * GET /livetv/channels/{id}/logo
     */
    public function getChannelLogo(Request $request, $id)
    {
        $channel = is_numeric($id) ? TvChannel::find($id) : TvChannel::where('tvg_id', $id)->first();
        if (!$channel) {
            $placeholder = public_path('img/placeholder-poster.png');
            if (file_exists($placeholder)) {
                return response()->file($placeholder, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
            return response('', 404);
        }

        $rawLogo = $channel->getRawOriginal('logo_url');
        if (!empty($rawLogo) && str_starts_with($rawLogo, '/storage/')) {
            $rel = ltrim(preg_replace('#^/storage/#', '', $rawLogo), '/');
            $fullPath = storage_path("app/public/{$rel}");
            if (file_exists($fullPath) && !is_dir($fullPath) && @filesize($fullPath) > 50) {
                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'svg' => 'image/svg+xml',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                return response()->file($fullPath, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'public, max-age=86400',
                    'Access-Control-Allow-Origin' => '*',
                ]);
            }
        }

        // Si no existe físicamente, procesar mediante TvLogoService (iptv_logos_index.json o generación SVG)
        try {
            $logoService = app(\App\Services\TvLogoService::class);
            $logoPath = $logoService->processChannelLogo($channel, null, false);
            if ($logoPath && str_starts_with($logoPath, '/storage/')) {
                $rel = ltrim(preg_replace('#^/storage/#', '', $logoPath), '/');
                $fullPath = storage_path("app/public/{$rel}");
                if (file_exists($fullPath) && !is_dir($fullPath)) {
                    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'svg' => 'image/svg+xml',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        default => 'image/jpeg',
                    };
                    return response()->file($fullPath, [
                        'Content-Type' => $mime,
                        'Cache-Control' => 'public, max-age=86400',
                        'Access-Control-Allow-Origin' => '*',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("LiveTvController@getChannelLogo error: " . $e->getMessage());
        }

        // Fallback SVG dinámico garantizado con siglas y color del canal
        $words = explode(' ', trim(preg_replace('/\b(hd|fhd|sd|4k|latino|esp|hon)\b/i', '', $channel->name)));
        $short = count($words) >= 2
            ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
            : mb_substr($words[0] ?? 'TV', 0, 3);
        $short = mb_strtoupper($short);
        $hue = abs(crc32($channel->name) % 360);
        $escapedName = htmlspecialchars(mb_substr($channel->name, 0, 20), ENT_XML1);
        $escapedShort = htmlspecialchars($short, ENT_XML1);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180" width="100%" height="100%">
  <defs>
    <linearGradient id="grad_{$channel->id}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="hsl({$hue}, 65%, 25%)" />
      <stop offset="100%" stop-color="hsl({$hue}, 80%, 12%)" />
    </linearGradient>
  </defs>
  <rect width="320" height="180" rx="16" fill="url(#grad_{$channel->id})" stroke="hsl({$hue}, 60%, 45%)" stroke-width="2" stroke-opacity="0.4" />
  <rect x="16" y="16" width="288" height="148" rx="10" fill="#000000" fill-opacity="0.25" />
  <text x="160" y="88" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="38" font-weight="900" fill="#ffffff" text-anchor="middle" dominant-baseline="middle" letter-spacing="2">
    {$escapedShort}
  </text>
  <text x="160" y="132" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="13" font-weight="600" fill="#e4e4e7" text-anchor="middle" opacity="0.9">
    {$escapedName}
  </text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Negociación de streaming en vivo con Emby (PlaybackInfo).
     * Consulta POST /emby/Items/{channelId}/PlaybackInfo con DeviceProfile exclusivo
     * h264 + aac,mp3 (estéreo) para asegurar DirectStream o transcodificación de audio
     * automática (AC3/EAC3/MP2 a AAC), devolviendo la URL HLS compatibilizada.
     */
    public function getPlaybackInfo(Request $request): JsonResponse
    {
        $integrityService = app(MediaIntegrityService::class);
        $channelId = $request->input('channel_id') ?? $request->query('channel_id');
        $deviceId = $request->input('device_id') ?? $request->query('device_id') ?? 'flixhn-web-client';
        $providedStreamUrl = $request->input('stream_url') ?? $request->query('stream_url');

        if (empty($channelId) && empty($providedStreamUrl)) {
            return response()->json([
                'status' => 'error',
                'message' => 'channel_id o stream_url es requerido.',
            ], 400);
        }

        // Buscar registro del canal local
        $channel = null;
        if (!empty($channelId)) {
            if (is_numeric($channelId)) {
                $channel = TvChannel::find($channelId);
            }
            if (!$channel) {
                $channel = TvChannel::where('tvg_id', $channelId)
                    ->orWhere('channel_number', $channelId)
                    ->first();
            }
            // Auto-verificación de canal eliminado/desactivado
            if ($channel && (!$channel->is_active || $channel->status === 'deleted' || $channel->status === 'offline_deleted')) {
                $integrityService->purgeChannel($channel);
                return $integrityService->goneResponse($channel->id, 'channel');
            }
        }

        // Obtener configuración de conexión a Emby
        $connector = app(RemoteMediaConnectorService::class);
        $settings = $connector->getConnectionSettings();
        $defaultFallbackServer = ServerSetting::get('media_server_url')
            ?: ServerSetting::get('emby_server_url')
            ?: config('services.emby.server_url')
            ?: 'http://45.4.87.126:6789';
        $serverUrl = rtrim($settings['server_url'] ?: $defaultFallbackServer, '/');
        $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $serverUrl);
        $apiKey = $settings['api_key']
            ?: ServerSetting::get('media_server_api_key')
            ?: ServerSetting::get('emby_api_key')
            ?: config('services.emby.api_key', '0fe0eb20e78f431d9fa474e05ab2d82f');

        // Resolver el channelId objetivo para Emby
        $embyMap = $this->getEmbyChannelsMap($cleanBaseUrl, $apiKey);
        $targetEmbyChannelId = null;

        if ($channel) {
            $targetEmbyChannelId = $this->resolveEmbyChannelId($channel, $embyMap);
        }

        if (!$targetEmbyChannelId) {
            // Evaluar si stream_url contiene el ID de Emby (/Videos/{id} o /Channels/{id} o /Items/{id})
            $effectiveUrl = $channel?->stream_url ?: $providedStreamUrl;
            if ($effectiveUrl && preg_match('#/(?:Videos|Channels|Items)/([a-zA-Z0-9_-]+)#i', $effectiveUrl, $m)) {
                $targetEmbyChannelId = $m[1];
            } elseif ($channelId && (is_string($channelId) || is_numeric($channelId))) {
                $targetEmbyChannelId = $channelId;
            }
        }

        // Construir DeviceProfile estricto para navegador web
        $deviceProfile = [
            'Name' => 'FlixHN Live Player',
            'MaxStreamingBitrate' => 20000000,
            'MaxStaticMusicBitrate' => 320000,
            'DirectPlayProfiles' => [
                [
                    'Container' => 'ts,m3u8,mp4',
                    'Type' => 'Video',
                    'VideoCodec' => 'h264',
                    'AudioCodec' => 'aac,mp3',
                ],
            ],
            'TranscodingProfiles' => [
                [
                    'Container' => 'ts',
                    'Type' => 'Video',
                    'VideoCodec' => 'h264',
                    'AudioCodec' => 'aac',
                    'AudioBitrate' => 192000,
                    'MaxAudioChannels' => '2',
                    'Protocol' => 'hls',
                    'Context' => 'Streaming',
                    'BreakOnNonKeyFrames' => true,
                ],
            ],
            'ContainerProfiles' => [],
            'CodecProfiles' => [
                [
                    'Type' => 'Video',
                    'Codec' => 'h264',
                ],
                [
                    'Type' => 'Audio',
                    'Codec' => 'aac',
                    'Conditions' => [
                        [
                            'Condition' => 'LessThanEqual',
                            'Property' => 'AudioChannels',
                            'Value' => '2',
                        ],
                    ],
                ],
            ],
            'ResponseProfiles' => [],
        ];

        // 1. Consultar POST /emby/Items/{channelId}/PlaybackInfo?api_key={apiKey}
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Emby-Token' => $apiKey,
                'X-MediaBrowser-Token' => $apiKey,
            ];

            $playbackPayload = [
                'DeviceProfile' => $deviceProfile,
                'DeviceId' => $deviceId,
                'AutoOpenLiveStream' => true,
                'EnableDirectPlay' => false,
                'EnableDirectStream' => false,
                'AllowVideoStreamCopy' => false,
                'AllowAudioStreamCopy' => false,
            ];

            $playbackUrl = "{$cleanBaseUrl}/emby/Items/{$targetEmbyChannelId}/PlaybackInfo?api_key={$apiKey}";
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders($headers)
                ->post($playbackUrl, $playbackPayload);

            if ($response->status() === 404) {
                $altUrl = "{$cleanBaseUrl}/Items/{$targetEmbyChannelId}/PlaybackInfo?api_key={$apiKey}";
                $response = Http::timeout(8)
                    ->connectTimeout(3)
                    ->withHeaders($headers)
                    ->post($altUrl, $playbackPayload);
            }

            if ($response->successful()) {
                $data = $response->json();
                $playSessionId = $data['PlaySessionId'] ?? null;
                $mediaSources = $data['MediaSources'] ?? [];
                $mediaSource = $mediaSources[0] ?? null;

                $mediaSourceId = $mediaSource['Id'] ?? $targetEmbyChannelId;
                $liveStreamId = $mediaSource['LiveStreamId'] ?? null;
                $transcodingUrl = $mediaSource['TranscodingUrl'] ?? null;

                // Construcción garantizada de la URL HLS con todos los parámetros obligatorios
                $requiredParams = [
                    'MediaSourceId' => $mediaSourceId,
                    'PlaySessionId' => $playSessionId ?: ('flixhn_' . uniqid()),
                    'VideoCodec' => 'h264',
                    'AudioCodec' => 'aac',
                    'AudioBitrate' => '192000',
                    'MaxAudioChannels' => '2',
                    'TranscodingMaxAudioChannels' => '2',
                    'VideoBitrate' => '8000000',
                    'SegmentContainer' => 'ts',
                    'MinSegments' => '1',
                    'BreakOnNonKeyFrames' => 'True',
                    'EnableAutoStreamCopy' => 'false',
                    'AllowVideoStreamCopy' => 'false',
                    'AllowAudioStreamCopy' => 'false',
                    'DeviceId' => $deviceId,
                    'api_key' => $apiKey,
                ];

                if (!empty($liveStreamId)) {
                    $requiredParams['LiveStreamId'] = $liveStreamId;
                }

                if (!empty($transcodingUrl)) {
                    $absoluteTranscoding = str_starts_with($transcodingUrl, 'http')
                        ? $transcodingUrl
                        : ($cleanBaseUrl . (str_starts_with($transcodingUrl, '/') ? '' : '/') . $transcodingUrl);

                    $parsed = parse_url($absoluteTranscoding);
                    parse_str($parsed['query'] ?? '', $existingParams);
                    $mergedParams = array_merge($existingParams, $requiredParams);

                    $hlsUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost')
                        . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
                        . ($parsed['path'] ?? '')
                        . '?' . http_build_query($mergedParams);
                } else {
                    $hlsUrl = "{$cleanBaseUrl}/emby/Videos/{$targetEmbyChannelId}/master.m3u8?" . http_build_query($requiredParams);
                }

                return response()->json([
                    'status' => 'success',
                    'channel_id' => $targetEmbyChannelId,
                    'media_source_id' => $mediaSourceId,
                    'play_session_id' => $requiredParams['PlaySessionId'],
                    'live_stream_id' => $liveStreamId,
                    'hls_url' => $hlsUrl,
                    'server_url' => $cleanBaseUrl,
                    'api_key' => $apiKey,
                    'device_id' => $deviceId,
                    'media_source' => $mediaSource,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("LiveTvController@getPlaybackInfo: Error consultando PlaybackInfo en Emby: " . $e->getMessage());
        }

        // Fallback: si Emby no respondió o el canal tiene stream_url directo
        $fallbackStreamUrl = $channel?->stream_url ?: $providedStreamUrl;
        $fallbackSession = 'flixhn_' . uniqid();

        if ($fallbackStreamUrl) {
            return response()->json([
                'status' => 'fallback',
                'channel_id' => $targetEmbyChannelId ?: $channelId,
                'media_source_id' => $targetEmbyChannelId ?: $channelId,
                'play_session_id' => $fallbackSession,
                'live_stream_id' => null,
                'hls_url' => $fallbackStreamUrl,
                'direct_url' => $fallbackStreamUrl,
                'server_url' => $cleanBaseUrl,
                'api_key' => $apiKey,
                'device_id' => $deviceId,
            ]);
        }

        // Fallback construyendo master.m3u8 directo a Emby
        $fallbackParams = [
            'MediaSourceId' => $targetEmbyChannelId ?: $channelId,
            'PlaySessionId' => $fallbackSession,
            'VideoCodec' => 'h264',
            'AudioCodec' => 'aac',
            'AudioBitrate' => '192000',
            'MaxAudioChannels' => '2',
            'TranscodingMaxAudioChannels' => '2',
            'VideoBitrate' => '8000000',
            'SegmentContainer' => 'ts',
            'MinSegments' => '1',
            'BreakOnNonKeyFrames' => 'True',
            'EnableAutoStreamCopy' => 'false',
            'AllowVideoStreamCopy' => 'false',
            'AllowAudioStreamCopy' => 'false',
            'DeviceId' => $deviceId,
            'api_key' => $apiKey,
        ];

        return response()->json([
            'status' => 'fallback',
            'channel_id' => $targetEmbyChannelId ?: $channelId,
            'media_source_id' => $targetEmbyChannelId ?: $channelId,
            'play_session_id' => $fallbackSession,
            'live_stream_id' => null,
            'hls_url' => "{$cleanBaseUrl}/emby/Videos/{$targetEmbyChannelId}/master.m3u8?" . http_build_query($fallbackParams),
            'server_url' => $cleanBaseUrl,
            'api_key' => $apiKey,
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Notifica a Emby para liberar el sintonizador y codificación activa.
     * DELETE /emby/Videos/ActiveEncodings?DeviceId=...&PlaySessionId=...
     */
    public function stopActiveEncoding(Request $request): JsonResponse
    {
        $deviceId = $request->input('DeviceId') ?? $request->query('DeviceId') ?? 'flixhn-web-client';
        $playSessionId = $request->input('PlaySessionId') ?? $request->query('PlaySessionId');

        if (!$playSessionId) {
            return response()->json(['status' => 'ignored', 'message' => 'No PlaySessionId provided']);
        }

        $connector = app(RemoteMediaConnectorService::class);
        $settings = $connector->getConnectionSettings();
        $defaultFallbackServer = ServerSetting::get('media_server_url')
            ?: ServerSetting::get('emby_server_url')
            ?: config('services.emby.server_url')
            ?: 'http://45.4.87.126:6789';
        $serverUrl = rtrim($settings['server_url'] ?: $defaultFallbackServer, '/');
        $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $serverUrl);
        $apiKey = $settings['api_key']
            ?: ServerSetting::get('media_server_api_key')
            ?: ServerSetting::get('emby_api_key')
            ?: config('services.emby.api_key', '0fe0eb20e78f431d9fa474e05ab2d82f');

        try {
            $deleteUrl = "{$cleanBaseUrl}/emby/Videos/ActiveEncodings";
            Http::timeout(3)
                ->connectTimeout(1)
                ->withHeaders([
                    'X-Emby-Token' => $apiKey,
                ])
                ->delete($deleteUrl, [
                    'DeviceId' => $deviceId,
                    'PlaySessionId' => $playSessionId,
                    'api_key' => $apiKey,
                ]);
        } catch (\Throwable $e) {
            Log::debug("LiveTvController@stopActiveEncoding: " . $e->getMessage());
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Obtiene y cachea el mapa de canales de Live TV desde el servidor Emby.
     */
    protected function getEmbyChannelsMap(?string $serverUrl = null, ?string $apiKey = null): array
    {
        return Cache::remember('emby_livetv_channels_map', 300, function () use ($serverUrl, $apiKey) {
            $connector = app(RemoteMediaConnectorService::class);
            $settings = $connector->getConnectionSettings();
            $defaultFallbackServer = ServerSetting::get('media_server_url')
                ?: ServerSetting::get('emby_server_url')
                ?: config('services.emby.server_url')
                ?: 'http://45.4.87.126:6789';
            $targetUrl = rtrim($serverUrl ?: ($settings['server_url'] ?: $defaultFallbackServer), '/');
            $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $targetUrl);
            $key = $apiKey
                ?: ($settings['api_key']
                    ?: ServerSetting::get('media_server_api_key')
                    ?: ServerSetting::get('emby_api_key')
                    ?: config('services.emby.api_key', '0fe0eb20e78f431d9fa474e05ab2d82f'));

            $map = [
                'by_id' => [],
                'by_number' => [],
                'by_name' => [],
                'items' => [],
            ];

            try {
                $headers = [
                    'Accept' => 'application/json',
                    'X-Emby-Token' => $key,
                    'X-MediaBrowser-Token' => $key,
                ];

                $res = Http::timeout(6)
                    ->connectTimeout(2)
                    ->withHeaders($headers)
                    ->get("{$cleanBaseUrl}/emby/LiveTv/Channels", [
                        'api_key' => $key,
                    ]);

                if ($res->status() === 404) {
                    $res = Http::timeout(6)
                        ->connectTimeout(2)
                        ->withHeaders($headers)
                        ->get("{$cleanBaseUrl}/LiveTv/Channels", [
                            'api_key' => $key,
                        ]);
                }

                if ($res->successful()) {
                    $items = $res->json()['Items'] ?? [];
                    foreach ($items as $item) {
                        $id = (string)($item['Id'] ?? '');
                        if (empty($id)) continue;

                        $map['by_id'][$id] = $item;
                        $map['items'][] = $item;

                        $num = (string)($item['ChannelNumber'] ?? $item['Number'] ?? '');
                        if (!empty($num)) {
                            $map['by_number'][$num] = $id;
                        }

                        $name = trim(mb_strtolower($item['Name'] ?? ''));
                        if (!empty($name)) {
                            $map['by_name'][$name] = $id;
                            $norm = preg_replace('/[^a-z0-9]/', '', $name);
                            if (!empty($norm)) {
                                $map['by_name'][$norm] = $id;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("LiveTvController@getEmbyChannelsMap: " . $e->getMessage());
            }

            return $map;
        });
    }

    /**
     * Resuelve el ID oficial de Emby para un canal de FlixHN.
     */
    protected function resolveEmbyChannelId(TvChannel $channel, array $embyMap): ?string
    {
        // 1. Si stream_url ya tiene el ID de Emby (/Videos/{id} o /Channels/{id} o /Items/{id})
        if (!empty($channel->stream_url) && preg_match('#/(?:Videos|Channels|Items)/([a-zA-Z0-9_-]+)#i', $channel->stream_url, $m)) {
            return $m[1];
        }

        // 2. Si el tvg_id ya coincide con un Id de Emby
        if (!empty($channel->tvg_id) && isset($embyMap['by_id'][(string)$channel->tvg_id])) {
            return (string)$channel->tvg_id;
        }

        // 3. Coincidencia por channel_number
        if (!empty($channel->channel_number) && isset($embyMap['by_number'][(string)$channel->channel_number])) {
            return $embyMap['by_number'][(string)$channel->channel_number];
        }

        // 4. Coincidencia por nombre exacto o normalizado
        if (!empty($channel->name)) {
            $name = trim(mb_strtolower($channel->name));
            if (isset($embyMap['by_name'][$name])) {
                return $embyMap['by_name'][$name];
            }
            $norm = preg_replace('/[^a-z0-9]/', '', $name);
            if (isset($embyMap['by_name'][$norm])) {
                return $embyMap['by_name'][$norm];
            }
        }

        // 5. Coincidencia por tvg_name
        if (!empty($channel->tvg_name)) {
            $tname = trim(mb_strtolower($channel->tvg_name));
            if (isset($embyMap['by_name'][$tname])) {
                return $embyMap['by_name'][$tname];
            }
        }

        return null;
    }

    /**
     * Proxy de streaming HLS/TS para evitar problemas de CORS o contenido mixto en el navegador.
     */
    public function proxy(Request $request)
    {
        $targetUrl = $request->query('url');
        if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'URL no válida o vacía'], 400);
        }

        try {
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => '*/*',
            ];

            // Realizar solicitud con timeout
            $client = Http::withHeaders($headers)->timeout(15);
            $response = $client->get($targetUrl);

            $contentType = $response->header('Content-Type') ?: 'application/vnd.apple.mpegurl';
            $body = $response->body();

            // Si es un playlist M3U8, reescribir URLs relativas a absolutas si es necesario
            if (str_contains($contentType, 'mpegurl') || str_ends_with($targetUrl, '.m3u8')) {
                $baseUrl = substr($targetUrl, 0, strrpos($targetUrl, '/') + 1);
                $lines = explode("\n", $body);
                $rewritten = [];
                foreach ($lines as $line) {
                    $trim = trim($line);
                    if (!empty($trim) && !str_starts_with($trim, '#')) {
                        if (!filter_var($trim, FILTER_VALIDATE_URL)) {
                            $trim = $baseUrl . $trim;
                        }
                    }
                    $rewritten[] = $trim;
                }
                $body = implode("\n", $rewritten);
            }

            return response($body, $response->status(), [
                'Content-Type' => $contentType,
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => '*',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al conectar con la fuente de transmisión',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}
