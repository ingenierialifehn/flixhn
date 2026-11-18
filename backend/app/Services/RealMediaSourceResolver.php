<?php

namespace App\Services;

use App\Models\DownloadJob;
use App\Models\Episode;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealMediaSourceResolver
{
    public const UA_VISIONOS = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_7_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';
    public const UA_WEB = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /**
     * Tamaños en bytes de archivos de prueba antiguos o películas vinculadas por error anterior.
     */
    protected array $wrongFileRules = [
        249224577  => 'bunny',    // W3C Big Buck Bunny 853x480
        22718509   => 'bunny',    // Bower Big Buck Bunny
        1483014457 => 'boda',     // Hasta que la boda nos separe (vinculada antes por error a 'plata')
        1039747198 => 'ignacio',  // Ignacio de Loyola
        922792072  => 'jurassic', // Jurassic World
        802892266  => 'iboy',     // iBoy
        158268029  => 'stranger', // Stranger Things st1x2
    ];

    /**
     * Detecta si un archivo en storage/media pertenece a un reemplazo incorrecto o muestra legacy.
     */
    public function isWrongOrLegacyFile(?string $filePath, ?string $titleName = null): bool
    {
        if (empty($filePath) || !file_exists($filePath) || is_dir($filePath)) {
            return false;
        }

        $lowerTitle = $this->stripAccents(mb_strtolower((string) ($titleName ?: basename($filePath))));

        // Si es un symlink dentro de storage/media, verificar que apunte al mismo título real
        if (is_link($filePath)) {
            $target = @readlink($filePath);
            if ($target && !$this->titleMatchesQuery(basename($target), (string) $titleName)) {
                return true;
            }
        }

        $size = (int) @filesize($filePath);
        if (isset($this->wrongFileRules[$size])) {
            $requiredKeyword = $this->wrongFileRules[$size];
            if (!str_contains($lowerTitle, $requiredKeyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina de forma segura un archivo incorrecto en storage/media (nunca toca /mnt/media_library)
     * y reinicia el estado del DownloadJob para que descargue y reproduzca el contenido real.
     */
    public function purgeIfWrongFile(?string $filePath, ?string $titleName = null, ?DownloadJob $job = null): bool
    {
        if (!$this->isWrongOrLegacyFile($filePath, $titleName)) {
            return false;
        }

        $normPath = str_replace('\\', '/', (string) $filePath);
        $isSafeToDelete = str_contains($normPath, '/storage/media/') || is_link($filePath);

        if ($isSafeToDelete && file_exists($filePath)) {
            Log::info("RealMediaSourceResolver: Purgando archivo incorrecto/legacy [{$filePath}] para título [{$titleName}]");
            @unlink($filePath);
        }

        if ($job) {
            try {
                $job->update([
                    'status' => 'DOWNLOADING',
                    'progress' => 0,
                    'downloaded_bytes' => 0,
                    'download_speed' => 'Iniciando...',
                    'eta' => 'Calculando...',
                    'can_play_progressive' => true,
                ]);
            } catch (\Throwable $e) {}
        }

        // Limpiar referencia en Title y Episodes si apuntaban al archivo incorrecto
        if (!empty($titleName)) {
            try {
                $titles = Title::where('name', $titleName)
                    ->orWhere('file_path', $filePath)
                    ->get();
                foreach ($titles as $t) {
                    if ($t->file_path === $filePath || $this->isWrongOrLegacyFile($t->file_path, $t->name)) {
                        Episode::where('title_id', $t->id)->where('file_path', $filePath)->delete();
                        $t->update([
                            'file_path' => null,
                            'source_path' => null,
                        ]);
                    }
                }
            } catch (\Throwable $e) {}
        }

        return true;
    }

    /**
     * Normalización alfanumérica exacta para comparar nombres de títulos sin falsos positivos.
     */
    public function normalizeExact(string $text): string
    {
        $clean = preg_replace('/\b(19|20)\d{2}\b|\(.*?\)|\[.*?\]/', '', $text);
        $clean = $this->stripAccents(mb_strtolower((string) $clean));
        return preg_replace('/[^a-z0-9]+/', '', $clean) ?: '';
    }

    public function stripAccents(string $str): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'Ñ' => 'n', 'Ç' => 'c',
        ];
        return strtr($str, $map);
    }

    /**
     * Extrae las palabras clave significativas del título (excluyendo artículos/preposiciones).
     */
    public function getSignificantWords(string $title): array
    {
        $stopWords = [
            'el', 'la', 'los', 'las', 'de', 'del', 'en', 'y', 'a', 'que', 'nos',
            'un', 'una', 'unos', 'unas', 'por', 'con', 'para', 'se', 'al', 'lo',
            'como', 'mas', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'si', 'porque',
            'esta', 'entre', 'cuando', 'muy', 'sin', 'sobre', 'tambien', 'me',
            'hasta', 'hay', 'donde', 'quien', 'desde', 'todo', 'the', 'of', 'and',
        ];

        $norm = $this->stripAccents(mb_strtolower($title));
        preg_match_all('/[a-z0-9]+/', $norm, $matches);
        $words = $matches[0] ?? [];

        $sig = array_values(array_filter($words, fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords, true)));
        return !empty($sig) ? $sig : $words;
    }

    /**
     * Verifica que el título candidato contenga las palabras clave reales del título buscado
     * (por ejemplo, 'Hasta que la plata nos separe' exige tanto 'plata' como 'separe').
     */
    public function titleMatchesQuery(string $candidateTitle, string $targetTitle): bool
    {
        $sig = $this->getSignificantWords($targetTitle);
        if (empty($sig)) {
            return true;
        }

        $candNorm = $this->stripAccents(mb_strtolower($candidateTitle));
        $matched = 0;
        foreach ($sig as $word) {
            if (str_contains($candNorm, $word)) {
                $matched++;
            }
        }

        $required = count($sig) <= 3 ? count($sig) : max(2, count($sig) - 1);
        return $matched >= $required;
    }

    /**
     * Resuelve la fuente de video REAL para cualquier título (Local Emby exacto -> YouTube VISIONOS HLS -> Dailymotion).
     */
    public function resolveRealSource(string $titleName, ?string $mediaType = null, ?string $sourceUrl = null): ?array
    {
        $titleName = trim($titleName);
        if (empty($titleName)) {
            return null;
        }

        // Determinar si es serie o película
        if (empty($mediaType)) {
            $dbType = Title::where('name', $titleName)->value('type');
            $mediaType = $dbType ?: OttSyncService::normalizeMediaType(null, $titleName);
        }
        $isSeries = ($mediaType === 'series');

        // 1. Verificar coincidencia EXACTA en biblioteca local / Emby
        $exactLocal = $this->findExactLocalOrEmbyMatch($titleName, $isSeries);
        if ($exactLocal) {
            return $exactLocal;
        }

        // 2. Resolver stream real externo (cacheado por 60 min para velocidad instantánea)
        $cacheKey = 'flixhn_real_src_v3_' . md5($this->normalizeExact($titleName) . '_' . ($isSeries ? 'series' : 'movie'));

        return Cache::remember($cacheKey, 3600, function () use ($titleName, $isSeries) {
            $ytResolved = $this->resolveFromYouTubeVisionOs($titleName, $isSeries);
            if ($ytResolved) {
                return $ytResolved;
            }

            $dmResolved = $this->resolveFromDailymotion($titleName, $isSeries);
            if ($dmResolved) {
                return $dmResolved;
            }

            return null;
        });
    }

    /**
     * Busca en Emby y discos locales exigiendo coincidencia EXACTA del nombre normalizado.
     */
    protected function findExactLocalOrEmbyMatch(string $titleName, bool $isSeries): ?array
    {
        $normTarget = $this->normalizeExact($titleName);
        if (empty($normTarget)) {
            return null;
        }

        $serverUrl = 'http://65.187.110.22:6789';
        try {
            $configured = \App\Models\ServerSetting::get('media_server_url') ?: \App\Models\ServerSetting::get('emby_server_url');
            if ($configured && !str_contains($configured, '45.4.87.126')) {
                $serverUrl = rtrim($configured, '/');
            }
        } catch (\Throwable $e) {}

        $apiKey = 'ecb7a1c7421f4932a01cb8ab7a1b834c';
        try {
            $configuredKey = \App\Models\ServerSetting::get('media_server_api_key') ?: \App\Models\ServerSetting::get('emby_api_key');
            if ($configuredKey) {
                $apiKey = $configuredKey;
            }
        } catch (\Throwable $e) {}

        $ctx = stream_context_create(['http' => ['timeout' => 4, 'User-Agent' => 'FlixGrab/3.0']]);
        $q = urlencode($titleName);
        $url = "{$serverUrl}/emby/Items?SearchTerm={$q}&IncludeItemTypes=Movie,Series&Recursive=true&Fields=MediaSources,Path&api_key={$apiKey}";

        $res = @file_get_contents($url, false, $ctx);
        if (!$res) {
            return null;
        }

        $data = json_decode($res, true);
        foreach ($data['Items'] ?? [] as $item) {
            $itemName = $item['Name'] ?? '';
            if ($this->normalizeExact($itemName) !== $normTarget) {
                continue; // Coincidencia estricta obligatoria
            }

            $itemId = $item['Id'] ?? null;
            $type = $item['Type'] ?? '';
            if (!$itemId) {
                continue;
            }

            if ($type === 'Series') {
                $epUrl = "{$serverUrl}/emby/Shows/{$itemId}/Episodes?Limit=1&Fields=MediaSources,Path&api_key={$apiKey}";
                $epRes = @file_get_contents($epUrl, false, $ctx);
                $eps = $epRes ? (json_decode($epRes, true)['Items'] ?? []) : [];
                if (!empty($eps)) {
                    $ep = $eps[0];
                    $epId = $ep['Id'] ?? null;
                    $rawPath = $ep['Path'] ?? ($ep['MediaSources'][0]['Path'] ?? '');
                    if ($rawPath) {
                        $mapped = str_replace('/home/server01/', '/mnt/media_library/', $rawPath);
                        if (file_exists($mapped) && filesize($mapped) > 5 * 1024 * 1024) {
                            return [
                                'mode' => 'local_file',
                                'file_path' => $mapped,
                                'total_bytes' => (int) filesize($mapped),
                                'matched_title' => $itemName . ' - ' . ($ep['Name'] ?? 'Capítulo 1'),
                            ];
                        }
                    }
                    if ($epId) {
                        return [
                            'mode' => 'single_url',
                            'video_url' => "{$serverUrl}/emby/Videos/{$epId}/stream.mp4?static=true&api_key={$apiKey}",
                            'audio_url' => null,
                            'total_bytes' => (int) ($ep['MediaSources'][0]['Size'] ?? 500 * 1024 * 1024),
                            'matched_title' => $itemName,
                            'user_agent' => 'FlixGrab/3.0',
                        ];
                    }
                }
            } else {
                $rawPath = $item['Path'] ?? ($item['MediaSources'][0]['Path'] ?? '');
                if ($rawPath) {
                    $mapped = str_replace('/home/server01/', '/mnt/media_library/', $rawPath);
                    if (file_exists($mapped) && filesize($mapped) > 5 * 1024 * 1024) {
                        return [
                            'mode' => 'local_file',
                            'file_path' => $mapped,
                            'total_bytes' => (int) filesize($mapped),
                            'matched_title' => $itemName,
                        ];
                    }
                }
                return [
                    'mode' => 'single_url',
                    'video_url' => "{$serverUrl}/emby/Videos/{$itemId}/stream.mp4?static=true&api_key={$apiKey}",
                    'audio_url' => null,
                    'total_bytes' => (int) ($item['MediaSources'][0]['Size'] ?? 1200 * 1024 * 1024),
                    'matched_title' => $itemName,
                    'user_agent' => 'FlixGrab/3.0',
                ];
            }
        }

        return null;
    }

    /**
     * Busca y extrae los streams HLS de alta velocidad (ratebypass=yes) para el título real usando el cliente VISIONOS.
     */
    protected function resolveFromYouTubeVisionOs(string $titleName, bool $isSeries): ?array
    {
        $queries = $isSeries
            ? [
                "{$titleName} capitulo 1 completo español",
                "{$titleName} episodio 1 completo español",
                "{$titleName} completo español",
            ]
            : [
                "{$titleName} pelicula completa español latino",
                "{$titleName} completo español",
                "{$titleName} español latino",
            ];

        $candidates = [];
        $seenIds = [];

        foreach ($queries as $q) {
            $searchUrl = 'https://www.youtube.com/results?search_query=' . urlencode($q);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: " . self::UA_WEB . "\r\nAccept-Language: es-419,es;q=0.9\r\n",
                    'timeout' => 8,
                ],
            ]);

            $html = @file_get_contents($searchUrl, false, $ctx);
            if (!$html || !preg_match('/var ytInitialData = (\{.*?\});<\/script>/s', $html, $m)) {
                continue;
            }

            $data = json_decode($m[1], true);
            $sections = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? [];

            foreach ($sections as $sec) {
                $items = $sec['itemSectionRenderer']['contents'] ?? [];
                foreach ($items as $item) {
                    $v = $item['videoRenderer'] ?? null;
                    if (!$v) {
                        continue;
                    }
                    $vid = $v['videoId'] ?? null;
                    if (!$vid || isset($seenIds[$vid])) {
                        continue;
                    }

                    $runs = $v['title']['runs'] ?? [];
                    $vTitle = '';
                    foreach ($runs as $r) {
                        $vTitle .= $r['text'] ?? '';
                    }

                    $durTxt = $v['lengthText']['simpleText'] ?? '';
                    $durSec = $this->parseDurationText($durTxt);

                    if ($durSec < 120) {
                        continue; // Ignorar cortos/shorts
                    }

                    if (!$this->titleMatchesQuery($vTitle, $titleName)) {
                        continue; // Exigir coincidencia real con las palabras del título
                    }

                    $seenIds[$vid] = true;
                    $score = 0;
                    if ($durSec >= 900) {
                        $score += 100; // Priorizar episodios o películas completas (>= 15 min)
                    } elseif ($durSec >= 300) {
                        $score += 40;
                    }

                    $lowerVTitle = $this->stripAccents(mb_strtolower($vTitle));
                    if (preg_match('/\b(capitulo\s*0?1|episodio\s*0?1|cap\s*0?1)\b/', $lowerVTitle)) {
                        $score += 50;
                    }
                    if (str_contains($lowerVTitle, 'completo') || str_contains($lowerVTitle, 'completa')) {
                        $score += 30;
                    }
                    if (str_contains($lowerVTitle, 'telemundo') || str_contains($lowerVTitle, 'netflix')) {
                        $score += 20;
                    }

                    $candidates[] = [
                        'video_id' => $vid,
                        'title' => $vTitle,
                        'duration' => $durSec,
                        'score' => $score,
                    ];
                }
            }

            if (count($candidates) >= 3) {
                break;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        foreach (array_slice($candidates, 0, 5) as $cand) {
            $extracted = $this->extractVisionOsHlsForVideoId($cand['video_id']);
            if ($extracted) {
                Log::info("RealMediaSourceResolver: Fuente real resuelta para [{$titleName}] -> [{$extracted['matched_title']}] (ID: {$cand['video_id']}, {$extracted['duration_seconds']}s)");
                return $extracted;
            }
        }

        return null;
    }

    /**
     * Extrae los manifiestos HLS VOD (con ratebypass/yes) de video H.264 y audio AAC Estéreo en español.
     */
    public function extractVisionOsHlsForVideoId(string $videoId): ?array
    {
        $embedCtx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . self::UA_VISIONOS . "\r\n",
                'timeout' => 6,
            ],
        ]);
        $embedHtml = @file_get_contents("https://www.youtube.com/embed/{$videoId}", false, $embedCtx);
        $visitorData = '';
        if ($embedHtml && preg_match('/"VISITOR_DATA":"([^"]+)"/', $embedHtml, $vm)) {
            $visitorData = $vm[1];
        }

        $payload = json_encode([
            'videoId' => $videoId,
            'context' => [
                'client' => [
                    'clientName' => 'VISIONOS',
                    'clientVersion' => '1.02',
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'RealityDevice17,1',
                    'userAgent' => self::UA_VISIONOS,
                    'osName' => 'visionOS',
                    'osVersion' => '26.5.23O471',
                    'visitorData' => $visitorData,
                    'hl' => 'es',
                    'gl' => 'HN',
                ],
            ],
            'contentCheckOk' => true,
            'racyCheckOk' => true,
        ]);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . self::UA_VISIONOS,
            'X-Youtube-Client-Name: 101',
            'X-Youtube-Client-Version: 1.02',
        ];
        if ($visitorData) {
            $headers[] = "X-Goog-Visitor-Id: {$visitorData}";
        }

        $playerCtx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => 8,
            ],
        ]);

        $respRaw = @file_get_contents('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', false, $playerCtx);
        if (!$respRaw) {
            return null;
        }

        $resp = json_decode($respRaw, true);
        if (($resp['playabilityStatus']['status'] ?? '') !== 'OK') {
            return null;
        }

        $sd = $resp['streamingData'] ?? [];
        $hlsManifestUrl = $sd['hlsManifestUrl'] ?? null;
        if (!$hlsManifestUrl) {
            return null;
        }

        $af = $sd['adaptiveFormats'] ?? [];
        $vLen = 0;
        $aLen = 0;
        foreach ($af as $f) {
            $itag = (int) ($f['itag'] ?? 0);
            $clen = (int) ($f['contentLength'] ?? 0);
            if (in_array($itag, [136, 137, 135], true) && $clen > 0 && $vLen === 0) {
                $vLen = $clen;
            }
            if ($itag === 140 && $clen > 0 && $aLen === 0) {
                $aLen = $clen;
            }
        }
        $totalBytes = ($vLen + $aLen) > 0 ? ($vLen + $aLen) : (140 * 1024 * 1024);

        $m3u8Raw = @file_get_contents($hlsManifestUrl, false, $embedCtx);
        if (!$m3u8Raw) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $m3u8Raw);
        $audioCandidates = [];
        $videoByItag = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXT-X-MEDIA:') && str_contains($line, 'TYPE=AUDIO')) {
                if (preg_match('/URI="([^"]+)"/', $line, $um)) {
                    $uri = $um[1];
                    $score = 0;
                    if (str_contains($line, 'GROUP-ID="234"')) $score += 10;
                    if (stripos($line, 'LANGUAGE="es') !== false || stripos($line, 'original') !== false) $score += 25;
                    if (stripos($line, 'dubbed-auto') !== false) $score -= 20;
                    if (str_contains($line, 'DEFAULT=YES')) $score += 5;
                    $audioCandidates[] = ['score' => $score, 'uri' => $uri];
                }
            } elseif (str_starts_with($line, 'http')) {
                if (preg_match('#/itag/(\d+)/#', $line, $im)) {
                    $videoByItag[(int) $im[1]] = $line;
                }
            }
        }

        usort($audioCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $audioHls = $audioCandidates[0]['uri'] ?? null;

        $videoHls = null;
        foreach ([232, 270, 231, 230, 229] as $prefItag) {
            if (!empty($videoByItag[$prefItag])) {
                $videoHls = $videoByItag[$prefItag];
                break;
            }
        }

        if (!$videoHls || !$audioHls) {
            return null;
        }

        $durSec = (int) ($resp['videoDetails']['lengthSeconds'] ?? 1500);
        $vTitle = $resp['videoDetails']['title'] ?? $videoId;

        return [
            'mode' => 'hls_dual',
            'video_id' => $videoId,
            'matched_title' => $vTitle,
            'duration_seconds' => $durSec,
            'total_bytes' => $totalBytes,
            'video_url' => $videoHls,
            'audio_url' => $audioHls,
            'user_agent' => self::UA_VISIONOS,
        ];
    }

    /**
     * Respaldo mediante API pública de Dailymotion.
     */
    protected function resolveFromDailymotion(string $titleName, bool $isSeries): ?array
    {
        $query = $isSeries ? "{$titleName} capitulo 1" : "{$titleName} español";
        $url = 'https://api.dailymotion.com/videos?search=' . urlencode($query) . '&fields=id,title,duration,url&limit=10';
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'User-Agent' => self::UA_WEB]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            return null;
        }

        $list = json_decode($raw, true)['list'] ?? [];
        foreach ($list as $item) {
            $candTitle = $item['title'] ?? '';
            if (!$this->titleMatchesQuery($candTitle, $titleName)) {
                continue;
            }
            $vid = $item['id'] ?? null;
            if (!$vid) {
                continue;
            }
            $metaRaw = @file_get_contents("https://www.dailymotion.com/player/metadata/video/{$vid}", false, $ctx);
            if (!$metaRaw) {
                continue;
            }
            $meta = json_decode($metaRaw, true);
            $autoUrls = $meta['qualities']['auto'] ?? [];
            foreach ($autoUrls as $au) {
                if (!empty($au['url'])) {
                    return [
                        'mode' => 'single_url',
                        'video_url' => $au['url'],
                        'audio_url' => null,
                        'duration_seconds' => (int) ($item['duration'] ?? 1800),
                        'total_bytes' => 150 * 1024 * 1024,
                        'matched_title' => $candTitle,
                        'user_agent' => self::UA_WEB,
                    ];
                }
            }
        }

        return null;
    }

    protected function parseDurationText(string $txt): int
    {
        $txt = trim($txt);
        if (empty($txt)) {
            return 0;
        }
        $parts = array_map('intval', explode(':', $txt));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        return $parts[0] ?? 0;
    }

    /**
     * Garantiza que el trabajo de descarga real esté ejecutándose en segundo plano hacia storage_path.
     */
    public function ensureBackgroundDownloadRunning(DownloadJob $job): void
    {
        $destFile = $job->storage_path;
        if (empty($destFile)) {
            return;
        }

        // Purgar si el archivo actual era uno de los reemplazos antiguos incorrectos
        $this->purgeIfWrongFile($destFile, $job->title, $job);

        if (file_exists($destFile) && !is_dir($destFile) && @filesize($destFile) > 2 * 1024 * 1024) {
            return; // Ya descargado y válido
        }

        $partFile = $destFile . '.part';
        if (file_exists($partFile) && (time() - (int) @filemtime($partFile)) < 8) {
            return; // Descarga activa escribiendo en .part en este instante
        }

        $lockFile = sys_get_temp_dir() . '/flixgrab_job_' . $job->id . '.lock';
        if (file_exists($lockFile) && (time() - (int) @filemtime($lockFile)) < 12) {
            return; // Proceso de descarga recién iniciado
        }

        @file_put_contents($lockFile, (string) getmypid());

        $runnerPath = base_path('download_job_runner.php');
        if (!file_exists($runnerPath)) {
            return;
        }

        $phpBin = 'php';
        if (file_exists('/usr/local/bin/php')) {
            $phpBin = '/usr/local/bin/php';
        } elseif (file_exists('/usr/bin/php')) {
            $phpBin = '/usr/bin/php';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B {$phpBin} " . escapeshellarg($runnerPath) . " " . (int) $job->id . " > NUL 2>&1";
            @pclose(@popen($cmd, 'r'));
        } else {
            $cmd = "nohup {$phpBin} " . escapeshellarg($runnerPath) . " " . (int) $job->id . " > /dev/null 2>&1 &";
            @exec($cmd);
        }
    }

    /**
     * Transmite en vivo hacia el navegador mediante FFmpeg (MP4 fragmentado H.264 + AAC Estéreo 2.0)
     * mientras el archivo se descarga en segundo plano.
     */
    public function streamLiveToBrowser(Request $request, array $resolved)
    {
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'none',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Origin, Accept, Content-Type, Authorization, X-Requested-With',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        $mode = $resolved['mode'] ?? 'single_url';
        $ua = $resolved['user_agent'] ?? self::UA_VISIONOS;

        if ($mode === 'hls_dual' && !empty($resolved['video_url']) && !empty($resolved['audio_url'])) {
            $vArg = escapeshellarg($resolved['video_url']);
            $aArg = escapeshellarg($resolved['audio_url']);
            $uaArg = escapeshellarg($ua);

            $cmd = "ffmpeg -hide_banner -loglevel error "
                . "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto -i {$vArg} "
                . "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto -i {$aArg} "
                . "-map 0:v:0 -map 1:a:0 -c:v copy -c:a copy -bsf:a aac_adtstoasc "
                . "-f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
        } else {
            $srcUrl = $resolved['video_url'] ?? $resolved['file_path'] ?? '';
            $srcArg = escapeshellarg($srcUrl);
            $uaArg = escapeshellarg($ua);

            $inputPrefix = str_starts_with($srcUrl, 'http')
                ? "-user_agent {$uaArg} -allowed_extensions ALL -protocol_whitelist file,http,https,tcp,tls,crypto "
                : "";

            $cmd = "ffmpeg -hide_banner -loglevel error {$inputPrefix}-i {$srcArg} "
                . "-vf \"scale=trunc(iw/2)*2:trunc(ih/2)*2\" -c:v libx264 -preset ultrafast -pix_fmt yuv420p "
                . "-c:a aac -ac 2 -ar 48000 -b:a 128k "
                . "-f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
        }

        return new StreamedResponse(function () use ($cmd) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                return;
            }

            @fclose($pipes[0]);
            @fclose($pipes[2]);

            $stdout = $pipes[1];
            $chunkSize = 64 * 1024;

            while (!feof($stdout) && !connection_aborted()) {
                $buffer = fread($stdout, $chunkSize);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                echo $buffer;
                flush();
            }

            @fclose($stdout);
            @proc_terminate($process);
            @proc_close($process);
        }, 200, $headers);
    }
}
