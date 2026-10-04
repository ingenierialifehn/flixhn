<?php

namespace App\Http\Controllers;

use App\Models\PlaybackProgress;
use App\Models\Title;
use App\Models\Episode;
use App\Services\RemoteMediaConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaybackController extends Controller
{
    /**
     * Obtener sesión de streaming y parámetros verificados de reproducción (PlaybackInfo).
     * Consulta al servidor Emby remoto para generar un PlaySessionId oficial y
     * resolver la mejor estrategia de reproducción (HLS transcodificado o directo).
     */
    public function getPlaybackInfo(Request $request): JsonResponse
    {
        $titleId = $request->query('title_id');
        $episodeId = $request->query('episode_id');
        $itemId = $request->query('item_id');

        $targetItemId = null;
        $title = null;
        $episode = null;

        if ($episodeId) {
            $episode = Episode::find($episodeId);
            if ($episode && !empty($episode->stream_path)) {
                if (preg_match('#/Videos/([a-zA-Z0-9_-]+)#i', $episode->stream_path, $m)) {
                    $targetItemId = $m[1];
                }
            }
        }

        if (!$targetItemId && $titleId) {
            $title = Title::find($titleId);
            if ($title) {
                if (!empty($title->source_path) && preg_match('#remote://([a-zA-Z0-9_-]+)#i', $title->source_path, $m)) {
                    $targetItemId = $m[1];
                } elseif (!empty($title->stream_path) && preg_match('#/Videos/([a-zA-Z0-9_-]+)#i', $title->stream_path, $m)) {
                    $targetItemId = $m[1];
                }
            }
        }

        if (!$targetItemId && $itemId) {
            $targetItemId = $itemId;
        }

        if (!$targetItemId) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo identificar el ítem de video para reproducir.'
            ], 404);
        }

        $connector = app(RemoteMediaConnectorService::class);
        $settings = $connector->getConnectionSettings();
        $serverUrl = rtrim($settings['server_url'], '/');
        $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $serverUrl);
        $apiKey = $settings['api_key'];

        try {
            $headers = [
                'X-Emby-Token' => $apiKey,
                'X-MediaBrowser-Token' => $apiKey,
                'Accept' => 'application/json',
            ];

            // 1. Obtener PlaybackInfo oficial desde Emby
            $res = Http::timeout(6)
                ->connectTimeout(3)
                ->withHeaders($headers)
                ->get("{$cleanBaseUrl}/emby/Items/{$targetItemId}/PlaybackInfo", [
                    'api_key' => $apiKey,
                ]);

            if ($res->status() === 404) {
                $res = Http::timeout(6)
                    ->connectTimeout(3)
                    ->withHeaders($headers)
                    ->get("{$cleanBaseUrl}/Items/{$targetItemId}/PlaybackInfo", [
                        'api_key' => $apiKey,
                    ]);
            }

            if ($res->successful()) {
                $data = $res->json();
                $playSessionId = $data['PlaySessionId'] ?? null;
                $mediaSource = $data['MediaSources'][0] ?? null;
                $mediaSourceId = $mediaSource['Id'] ?? $targetItemId;
                $container = strtolower($mediaSource['Container'] ?? '');

                // Construcción de URL HLS maestra con parámetros de transcodificación
                $hlsQuery = [
                    'MediaSourceId' => $mediaSourceId,
                    'DeviceId' => 'flixhn-web-client',
                    'VideoCodec' => 'h264',
                    'AudioCodec' => 'aac',
                    'TranscodingMaxAudioChannels' => 2,
                    'SegmentContainer' => 'ts',
                ];
                if ($playSessionId) {
                    $hlsQuery['PlaySessionId'] = $playSessionId;
                }
                if ($apiKey) {
                    $hlsQuery['api_key'] = $apiKey;
                }

                $hlsUrl = "{$cleanBaseUrl}/emby/Videos/{$targetItemId}/master.m3u8?" . http_build_query($hlsQuery);

                // Construcción de URL directa con token
                $directQuery = [
                    'static' => 'true',
                    'MediaSourceId' => $mediaSourceId,
                    'DeviceId' => 'flixhn-web-client',
                ];
                if ($playSessionId) {
                    $directQuery['PlaySessionId'] = $playSessionId;
                }
                if ($apiKey) {
                    $directQuery['api_key'] = $apiKey;
                }
                $directUrl = "{$cleanBaseUrl}/emby/Videos/{$targetItemId}/stream?" . http_build_query($directQuery);

                // Evaluar si es reproducible directamente en HTML5 (MP4/WebM con audio AAC/MP3)
                $isDirectPlayable = in_array($container, ['mp4', 'm4v', 'webm']);
                if ($mediaSource && !empty($mediaSource['MediaStreams'])) {
                    foreach ($mediaSource['MediaStreams'] as $st) {
                        if (($st['Type'] ?? '') === 'Audio') {
                            $audioCodec = strtolower($st['Codec'] ?? '');
                            if (in_array($audioCodec, ['ac3', 'eac3', 'dts', 'truehd', 'flac'])) {
                                $isDirectPlayable = false;
                                break;
                            }
                        }
                    }
                }

                // Verificar disponibilidad física del stream en el servidor remoto (detectar 404 por discos desconectados)
                $isAvailable = true;
                $contentType = '';
                try {
                    $probeRes = Http::timeout(2)
                        ->connectTimeout(1)
                        ->withHeaders($headers)
                        ->head("{$cleanBaseUrl}/emby/Videos/{$targetItemId}/stream?static=true&api_key={$apiKey}");

                    if ($probeRes->status() === 404) {
                        $isAvailable = false;
                    }
                    $contentType = strtolower($probeRes->header('Content-Type') ?? '');
                    if (str_contains($contentType, 'matroska') || str_contains($contentType, 'mpegts') || str_contains($contentType, 'x-msvideo')) {
                        $isDirectPlayable = false;
                    }
                } catch (\Throwable $pe) {}

                return response()->json([
                    'status' => 'success',
                    'item_id' => $targetItemId,
                    'server_url' => $cleanBaseUrl,
                    'api_key' => $apiKey,
                    'play_session_id' => $playSessionId,
                    'media_source_id' => $mediaSourceId,
                    'container' => $container,
                    'content_type' => $contentType,
                    'is_available' => $isAvailable,
                    'is_direct_playable' => $isDirectPlayable,
                    'hls_url' => $hlsUrl,
                    'direct_url' => $directUrl,
                    'media_source' => $mediaSource,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("PlaybackController@getPlaybackInfo: Error consultando Emby: " . $e->getMessage());
        }

        // Fallback básico si Emby no respondió a tiempo
        return response()->json([
            'status' => 'fallback',
            'item_id' => $targetItemId,
            'server_url' => $cleanBaseUrl,
            'api_key' => $apiKey,
            'media_source_id' => $targetItemId,
            'container' => 'unknown',
            'is_direct_playable' => false,
            'hls_url' => "{$cleanBaseUrl}/emby/Videos/{$targetItemId}/master.m3u8?MediaSourceId={$targetItemId}&DeviceId=flixhn-web-client&VideoCodec=h264&AudioCodec=aac&TranscodingMaxAudioChannels=2&SegmentContainer=ts&api_key={$apiKey}",
            'direct_url' => "{$cleanBaseUrl}/emby/Videos/{$targetItemId}/stream?static=true&MediaSourceId={$targetItemId}&DeviceId=flixhn-web-client&api_key={$apiKey}",
        ]);
    }
    /**
     * Sincronizar periódicamente el tiempo de reproducción actual (cada 5-10s).
     */
    public function updateProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => 'required|exists:profiles,id',
            'title_id' => 'required|exists:titles,id',
            'episode_id' => 'nullable|exists:episodes,id',
            'current_seconds' => 'required|integer|min:0',
            'duration_seconds' => 'required|integer|min:1',
        ]);

        $progress = PlaybackProgress::updateOrCreate(
            [
                'profile_id' => $validated['profile_id'],
                'title_id' => $validated['title_id'],
                'episode_id' => $validated['episode_id'] ?? null,
            ],
            [
                'current_seconds' => $validated['current_seconds'],
                'duration_seconds' => $validated['duration_seconds'],
                'last_watched_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'progress' => $progress,
        ]);
    }

    /**
     * Consultar progreso exacto guardado para reanudar el reproductor.
     */
    public function getProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => 'required|exists:profiles,id',
            'title_id' => 'required|exists:titles,id',
            'episode_id' => 'nullable|exists:episodes,id',
        ]);

        $progress = PlaybackProgress::where('profile_id', $validated['profile_id'])
            ->where('title_id', $validated['title_id'])
            ->where('episode_id', $validated['episode_id'] ?? null)
            ->first();

        return response()->json([
            'progress' => $progress,
            'resume_seconds' => $progress ? $progress->current_seconds : 0,
        ]);
    }

    /**
     * Eliminar el progreso de un título para el perfil del usuario (Quitar de Continuar Viendo).
     * DELETE /api/watch-history/{titleId}
     */
    public function destroy(Request $request, int $titleId): JsonResponse
    {
        $profileId = $request->query('profile_id') ?? $request->input('profile_id');
        $episodeId = $request->query('episode_id') ?? $request->input('episode_id');

        $query = PlaybackProgress::where('title_id', $titleId);

        if ($profileId) {
            $query->where('profile_id', $profileId);
        } else {
            $user = $request->user();
            if ($user) {
                $userProfileIds = $user->profiles()->pluck('id');
                $query->whereIn('profile_id', $userProfileIds);
            }
        }

        if ($episodeId) {
            $query->where('episode_id', $episodeId);
        }

        $deletedCount = $query->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Progreso eliminado de Continuar Viendo correctamente.',
            'deleted' => $deletedCount,
        ]);
    }
}

