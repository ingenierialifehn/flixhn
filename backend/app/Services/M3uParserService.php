<?php

namespace App\Services;

use App\Models\TvSource;
use App\Models\TvChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class M3uParserService
{
    /**
     * Parsea canales directamente desde una URL M3U sin requerir persistencia previa.
     * Soporta caché de 5 minutos para alta concurrencia.
     *
     * @param string $url
     * @param TvSource|null $source
     * @return array
     */
    public function parseFromUrl(string $url, ?TvSource $source = null): array
    {
        $url = trim($url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return [];
        }

        $cacheKey = 'm3u_parsed_channels_' . md5($url);

        return Cache::remember($cacheKey, 300, function () use ($url, $source) {
            try {
                $headers = [];
                if (!empty($source?->user_agent)) {
                    $headers['User-Agent'] = $source->user_agent;
                } else {
                    $headers['User-Agent'] = 'FlixHN/2.0 (IPTV Core; HLS Client)';
                }

                if (!empty($source?->referrer_header)) {
                    if (in_array($source->referer_mode, ['Referer', 'Ambos'])) {
                        $headers['Referer'] = $source->referrer_header;
                    }
                    if (in_array($source->referer_mode, ['Referrer', 'Ambos'])) {
                        $headers['Referrer'] = $source->referrer_header;
                    }
                }

                $response = Http::withHeaders($headers)
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning("M3uParserService::parseFromUrl - Falló respuesta IPTV ({$response->status()}) de {$url}");
                    return [];
                }

                $content = $response->body();
                if (empty($content) || (!str_contains($content, '#EXTM3U') && !str_contains($content, '#EXTINF'))) {
                    return [];
                }

                $dummySource = $source ?: new TvSource([
                    'id' => 0,
                    'name' => 'M3U Stream',
                    'url' => $url,
                ]);

                return $this->parseM3uContent($content, $dummySource);
            } catch (\Exception $e) {
                Log::warning("M3uParserService::parseFromUrl - Error conectando a {$url}: {$e->getMessage()}");
                return [];
            }
        });
    }
    /**
     * Sincroniza y parsea la lista M3U de una fuente de TV.
     *
     * @param TvSource $source
     * @param string|null $overrideContent Contenido M3U directo en texto si se proporciona
     * @return array
     */
    public function syncSource(TvSource $source, ?string $overrideContent = null): array
    {
        $startTime = microtime(true);
        $content = $overrideContent;

        if (!$content) {
            $url = trim($source->url);
            if (empty($url)) {
                return [
                    'success' => false,
                    'message' => 'La fuente no tiene una URL configurada.',
                    'count' => 0,
                ];
            }

            try {
                $headers = [];
                if (!empty($source->user_agent)) {
                    $headers['User-Agent'] = $source->user_agent;
                } else {
                    $headers['User-Agent'] = 'FlixHN/2.0 (IPTV Core; HLS Client)';
                }

                if (!empty($source->referrer_header)) {
                    if (in_array($source->referer_mode, ['Referer', 'Ambos'])) {
                        $headers['Referer'] = $source->referrer_header;
                    }
                    if (in_array($source->referer_mode, ['Referrer', 'Ambos'])) {
                        $headers['Referrer'] = $source->referrer_header;
                    }
                }

                // Descarga con timeout estricto de 15 segundos
                $response = Http::withHeaders($headers)
                    ->timeout(15)
                    ->connectTimeout(10)
                    ->get($url);

                if (!$response->successful()) {
                    return [
                        'success' => false,
                        'message' => "El proveedor IPTV respondió con código HTTP {$response->status()}.",
                        'count' => 0,
                    ];
                }

                $content = $response->body();
            } catch (\Exception $e) {
                Log::warning("Error descargando M3U de {$source->url}: {$e->getMessage()}");
                return [
                    'success' => false,
                    'message' => "No se pudo conectar a la URL del proveedor IPTV en 15 segundos: {$e->getMessage()}",
                    'count' => 0,
                ];
            }
        }

        if (empty($content) || !str_contains($content, '#EXTM3U') && !str_contains($content, '#EXTINF')) {
            return [
                'success' => false,
                'message' => 'El archivo descargado no parece tener un formato M3U/M3U8 válido.',
                'count' => 0,
            ];
        }

        $channels = $this->parseM3uContent($content, $source);

        // Transacción para guardar los canales limpiamente
        DB::beginTransaction();
        try {
            // Eliminar canales anteriores de esta fuente
            TvChannel::where('tv_source_id', $source->id)->delete();

            $totalCount = count($channels);
            if ($totalCount > 0) {
                // Insertar en lotes de 200 para alto rendimiento
                foreach (array_chunk($channels, 200) as $chunk) {
                    TvChannel::insert($chunk);
                }
            }

            // Actualizar la fuente
            $source->update([
                'channels_count' => $totalCount,
                'last_synced_at' => now(),
            ]);

            DB::commit();

            $elapsed = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'message' => "Sincronización completada exitosamente. {$totalCount} canales importados en {$elapsed}s.",
                'count' => $totalCount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error guardando canales M3U en base de datos: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => "Error al guardar canales en base de datos: {$e->getMessage()}",
                'count' => 0,
            ];
        }
    }

    /**
     * Parsea el contenido en texto M3U y extrae canales.
     */
    public function parseM3uContent(string $content, TvSource $source): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $channels = [];
        $currentChannel = null;
        $channelCounter = 1;

        // Filtro de grupos si está definido
        $groupFilters = [];
        if (!empty($source->group_filter)) {
            $groupFilters = array_filter(array_map('trim', explode(';', $source->group_filter)));
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (str_starts_with($line, '#EXTINF:')) {
                // Parsear directiva #EXTINF
                $tvgId = $this->extractAttribute($line, 'tvg-id');
                $tvgName = $this->extractAttribute($line, 'tvg-name');
                $tvgLogo = $this->extractAttribute($line, 'tvg-logo');
                $groupTitle = $this->extractAttribute($line, 'group-title');
                $tvgChno = $this->extractAttribute($line, 'tvg-chno') ?: $this->extractAttribute($line, 'channel-id');

                // Nombre del canal tras la última coma
                $name = '';
                $lastCommaPos = strrpos($line, ',');
                if ($lastCommaPos !== false) {
                    $name = trim(substr($line, $lastCommaPos + 1));
                }
                if (empty($name)) {
                    $name = $tvgName ?: $tvgId ?: "Canal {$channelCounter}";
                }

                $currentChannel = [
                    'tv_source_id' => $source->id,
                    'name' => $name,
                    'logo_url' => $tvgLogo ?: null,
                    'group_title' => $groupTitle ?: 'General',
                    'channel_number' => $tvgChno ?: (string)$channelCounter,
                    'tvg_id' => $tvgId ?: null,
                    'tvg_name' => $tvgName ?: null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif (str_starts_with($line, '#EXTGRP:') && $currentChannel) {
                $extGrp = trim(substr($line, 8));
                if (!empty($extGrp)) {
                    $currentChannel['group_title'] = $extGrp;
                }
            } elseif (!str_starts_with($line, '#') && $currentChannel) {
                // Esta es la URL del stream
                $streamUrl = $line;
                $currentChannel['stream_url'] = $streamUrl;

                // Aplicar group_filter si fue especificado
                $keep = true;
                if (!empty($groupFilters)) {
                    $keep = false;
                    foreach ($groupFilters as $gf) {
                        if (stripos($currentChannel['group_title'], $gf) !== false) {
                            $keep = true;
                            break;
                        }
                    }
                }

                if ($keep) {
                    $channels[] = $currentChannel;
                    $channelCounter++;
                }

                $currentChannel = null;
            }
        }

        return $channels;
    }

    /**
     * Extrae un atributo de la línea EXTINF (soporta comillas dobles, simples o sin comillas).
     */
    protected function extractAttribute(string $line, string $attribute): ?string
    {
        $pattern = '/' . preg_quote($attribute, '/') . '=["\']([^"\']*)["\']/i';
        if (preg_match($pattern, $line, $matches)) {
            return trim($matches[1]);
        }

        // Sin comillas
        $patternNoQuotes = '/' . preg_quote($attribute, '/') . '=([^,\s]+)/i';
        if (preg_match($patternNoQuotes, $line, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Retorna contenido M3U vacío si se solicita demo (deshabilitado).
     */
    public static function getDemoM3uContent(): string
    {
        return "";
    }
}
