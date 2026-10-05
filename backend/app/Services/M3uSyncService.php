<?php

namespace App\Services;

use App\Models\TvSource;
use App\Models\TvChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class M3uSyncService
{
    /**
     * Sincroniza los canales de la fuente M3U activa.
     */
    public function syncActiveSource(?string $customUrl = null): array
    {
        $source = TvSource::where('is_active', true)->first();
        if (!$source) {
            $source = TvSource::first();
        }

        if (!$source && empty($customUrl)) {
            return [
                'success' => false,
                'message' => 'No hay fuentes de TV configuradas.',
                'count' => 0,
            ];
        }

        return $this->syncSource($source, $customUrl);
    }

    /**
     * Descarga y parsea la lista M3U de una fuente e inserta masivamente en tv_channels.
     */
    public function syncSource(?TvSource $source, ?string $customUrl = null): array
    {
        $url = $customUrl ?: ($source ? trim($source->url) : '');
        if (empty($url)) {
            return [
                'success' => false,
                'message' => 'La fuente no tiene una URL configurada.',
                'count' => 0,
            ];
        }

        $content = '';
        $effectiveUrl = $url;

        // 1. Descarga con timeout de 60 segundos y User-Agent VLC
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'VLC/3.0.18 LibVLC/3.0.18'
                ])
                ->get($url);

            if ($response->successful()) {
                $content = $response->body();
            }
        } catch (\Throwable $e) {
            Log::warning("M3uSyncService: Error descargando M3U desde {$url}: " . $e->getMessage());
        }

        // 2. Si la URL contiene 65.187.110.22 y no respondió (timeout o caída), intentar con IP activa 45.4.87.126
        if ((empty($content) || !str_contains($content, '#EXT')) && str_contains($url, '65.187.110.22')) {
            $fallbackUrl = str_replace('65.187.110.22', '45.4.87.126', $url);
            try {
                Log::info("M3uSyncService: Probando IP activa del servidor: {$fallbackUrl}");
                $response = Http::timeout(60)
                    ->withHeaders([
                        'User-Agent' => 'VLC/3.0.18 LibVLC/3.0.18'
                    ])
                    ->get($fallbackUrl);

                if ($response->successful()) {
                    $content = $response->body();
                    $effectiveUrl = $fallbackUrl;
                    if ($source) {
                        $source->update(['url' => $fallbackUrl]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("M3uSyncService: Error en conexión a {$fallbackUrl}: " . $e->getMessage());
            }
        }

        if (empty($content) || (!str_contains($content, '#EXTM3U') && !str_contains($content, '#EXTINF'))) {
            return [
                'success' => false,
                'message' => 'No se pudo descargar la lista M3U o no contiene un formato M3U válido.',
                'count' => 0,
            ];
        }

        $parsedChannels = $this->parseContent($content, $source ? $source->id : 1);

        if (empty($parsedChannels)) {
            return [
                'success' => false,
                'message' => 'El archivo M3U no contenía canales válidos.',
                'count' => 0,
            ];
        }

        // Limpiar tabla tv_channels e insertar masivamente en lotes de 200
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        TvChannel::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        foreach (array_chunk($parsedChannels, 200) as $chunk) {
            TvChannel::insert($chunk);
        }

        $totalCount = count($parsedChannels);

        if ($source) {
            $source->update([
                'channels_count' => $totalCount,
                'last_synced_at' => now(),
            ]);
        }

        return [
            'success' => true,
            'message' => "Se sincronizaron exitosamente {$totalCount} canales reales.",
            'count' => $totalCount,
            'total_synced' => $totalCount,
            'source_url' => $effectiveUrl,
        ];
    }

    /**
     * Parsea línea por línea el contenido M3U.
     */
    public function parseContent(string $content, int $sourceId = 1): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $channels = [];
        $currentChannel = null;
        $channelCounter = 1;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (str_starts_with($line, '#EXTINF:')) {
                // Nombre del canal tras la última coma
                $commaPos = strrpos($line, ',');
                $name = ($commaPos !== false) ? trim(substr($line, $commaPos + 1)) : '';

                // Extraer tvg-logo="..." o tvg-logo='...'
                $logoUrl = null;
                if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) {
                    $logoUrl = trim($m[1]);
                } elseif (preg_match('/tvg-logo=\'([^\']*)\'/i', $line, $m)) {
                    $logoUrl = trim($m[1]);
                }

                // Extraer group-title="..." o group-title='...'
                $groupTitle = 'General';
                if (preg_match('/group-title="([^"]*)"/i', $line, $m)) {
                    $groupTitle = trim($m[1]);
                } elseif (preg_match('/group-title=\'([^\']*)\'/i', $line, $m)) {
                    $groupTitle = trim($m[1]);
                }

                // Extraer tvg-id, tvg-name, tvg-chno si están presentes
                $tvgId = null;
                if (preg_match('/tvg-id="([^"]*)"/i', $line, $m)) {
                    $tvgId = trim($m[1]);
                }
                $tvgName = null;
                if (preg_match('/tvg-name="([^"]*)"/i', $line, $m)) {
                    $tvgName = trim($m[1]);
                }
                $chno = (string)$channelCounter;
                if (preg_match('/tvg-chno="([^"]*)"/i', $line, $m)) {
                    $chno = trim($m[1]);
                }

                if (empty($name)) {
                    $name = $tvgName ?: ($tvgId ?: "Canal {$channelCounter}");
                }

                // Categorización según nombre si no tiene grupo definido
                if ($groupTitle === 'General' || empty($groupTitle)) {
                    $groupTitle = $this->guessCategory($name);
                }

                // Si no trae logo_url desde el M3U, resolver logotipo oficial basado en su nombre
                if (empty($logoUrl)) {
                    $logoUrl = $this->resolveFallbackLogo($name);
                }

                $currentChannel = [
                    'tv_source_id' => $sourceId,
                    'name' => $name,
                    'logo_url' => $logoUrl,
                    'group_title' => $groupTitle ?: 'General',
                    'channel_number' => $chno,
                    'tvg_id' => $tvgId,
                    'tvg_name' => $tvgName,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($currentChannel && !str_starts_with($line, '#')) {
                // Línea que no empieza por '#' es la stream_url
                $currentChannel['stream_url'] = $line;
                $channels[] = $currentChannel;
                $channelCounter++;
                $currentChannel = null;
            }
        }

        return $channels;
    }

    /**
     * Categorización según el nombre del canal para organizar las categorías.
     */
    protected function guessCategory(string $name): string
    {
        $n = mb_strtolower($name);
        if (preg_match('/(espn|fox\s*sport|deporte|tyc|tudn|gol|golf|liga|stadium|win\s*sport|directv\s*sport|bein|f1|nba)/i', $n)) {
            return 'Deportes';
        }
        if (preg_match('/(hbo|cinemax|cinecanal|cinema|cine|movie|star\s*channel|paramount|universal|warner|tnt|space|fx|sony|dhe|golden|film|axn)/i', $n)) {
            return 'Cine y Series';
        }
        if (preg_match('/(cartoon|disney|nick|discovery\s*kids|infantil|baby|boomerang|tooncast|toons)/i', $n)) {
            return 'Infantiles';
        }
        if (preg_match('/(noticia|cnn|telesur|rt\b|dw\b|hch|canal\s*5|canal\s*3|telesistemas|24\s*hora|milenio|globo|cve)/i', $n)) {
            return 'Noticias';
        }
        if (preg_match('/(discovery|history|nat\s*geo|national\s*geographic|animal\s*planet|tlc|investigation|mundo|ciencia)/i', $n)) {
            return 'Documentales';
        }
        if (preg_match('/(mtv|htv|telehit|musica|music|concert|gourmet|glitz|e!|moda)/i', $n)) {
            return 'Música y Entretenimiento';
        }
        return 'General';
    }

    /**
     * Resuelve un logotipo oficial o dinámico para el canal basado en su nombre limpio.
     */
    public function resolveFallbackLogo(string $name): ?string
    {
        $clean = preg_replace('/\[[^\]]*\]|\([^\)]*\)/', ' ', $name);
        $clean = preg_replace('/\b(hd|fhd|4k|sd|1080p|720p|hevc|h264|h265|latino|lat|esp|es|honduras|hon|hnd|mx|rd|gua)\b/i', ' ', $clean);
        $clean = preg_replace('/[^a-zA-Z0-9\s+]/', ' ', $clean);
        $clean = strtolower(trim(preg_replace('/-+/', '-', preg_replace('/\s+/', '-', trim($clean))), '-'));

        if (empty($clean)) {
            return null;
        }

        return "https://tvlogos.b-cdn.net/{$clean}.png";
    }
}
