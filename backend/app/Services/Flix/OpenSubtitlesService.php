<?php

namespace AppServicesFlix;

use AppModelsPlugin;
use IlluminateSupportFacadesCache;
use IlluminateSupportFacadesFile;
use IlluminateSupportFacadesHttp;
use IlluminateSupportFacadesLog;

/**
 * Cliente OpenSubtitles REST API v1 + Hasher de 64 bits portado desde plugin.video.flix
 * (lib/opensubtitles/rest.py, lib/opensubtitles/utils.py y lib/subtitles.py).
 */
class OpenSubtitlesService
{
    protected string $baseUrl;
    protected string $userAgent;
    protected int $cacheTtlMinutes;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('flix.opensubtitles.base_url', 'https://api.opensubtitles.com/api/v1'), '/');
        $this->userAgent = (string) config('flix.opensubtitles.app_user_agent', 'FlixHN-FlixEngine v1.0.0');
        $this->cacheTtlMinutes = (int) config('flix.opensubtitles.cache_ttl_minutes', 30);
    }

    public function getSettings(): array
    {
        $defaults = [
            'api_key' => (string) config('flix.opensubtitles.api_key', ''),
            'username' => (string) config('flix.opensubtitles.username', ''),
            'password' => (string) config('flix.opensubtitles.password', ''),
            'languages' => config('flix.opensubtitles.default_languages', ['es', 'en']),
            'storage_dir' => (string) config('flix.opensubtitles.storage_dir', storage_path('app/subtitles')),
        ];

        try {
            $plugin = Plugin::where('slug', 'opensubtitles')->where('is_installed', true)->first();
            if ($plugin && is_array($plugin->settings)) {
                $pluginKey = trim((string) ($plugin->settings['api_key'] ?? ''));
                if ($pluginKey !== '' && !str_starts_with($pluginKey, 'op_live_sec_key_')) {
                    $defaults['api_key'] = $pluginKey;
                }
                if (!empty($plugin->settings['username']) && $plugin->settings['username'] !== 'flixhn_isp') {
                    $defaults['username'] = (string) $plugin->settings['username'];
                }
                if (!empty($plugin->settings['password'])) {
                    $defaults['password'] = (string) $plugin->settings['password'];
                }
                if (!empty($plugin->settings['download_languages']) && is_array($plugin->settings['download_languages'])) {
                    $defaults['languages'] = $plugin->settings['download_languages'];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('OpenSubtitlesService::getSettings fallback: ' . $e->getMessage());
        }

        return $defaults;
    }

    public function hasValidApiKey(): bool
    {
        return trim($this->getSettings()['api_key']) !== '';
    }

    public function normalizeLanguageCode(string $language): string
    {
        $lower = strtolower(trim($language));
        $map = [
            'english' => 'en',
            'spanish' => 'es',
            'español' => 'es',
            'french' => 'fr',
            'chinese' => 'zh-cn',
            'chinese (simple)' => 'zh-cn',
            'chinese (traditional)' => 'zh-tw',
            'portuguese' => 'pt-pt',
            'portuguese (brazil)' => 'pt-br',
        ];
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        if (str_starts_with($lower, 'es')) {
            return 'es';
        }
        if (str_starts_with($lower, 'en')) {
            return 'en';
        }
        if (str_starts_with($lower, 'fr')) {
            return 'fr';
        }
        return $lower;
    }

    public function calculateHash(string $path, int $chunkSize = 65536): ?string
    {
        $length = 0;
        $data = '';

        if (preg_match('#^https?://#i', $path)) {
            try {
                $head = Http::timeout(8)->head($path);
                $length = (int) $head->header('Content-Length');
                $acceptRanges = strtolower((string) $head->header('Accept-Ranges'));
                if ($acceptRanges !== 'bytes' || $length < $chunkSize) {
                    return null;
                }
                $first = Http::timeout(10)->withHeaders(['Range' => 'bytes=0-' . ($chunkSize - 1)])->get($path);
                $last = Http::timeout(10)->withHeaders(['Range' => 'bytes=' . ($length - $chunkSize) . '-' . ($length - 1)])->get($path);
                if (!$first->successful() || !$last->successful()) {
                    return null;
                }
                $data = $first->body() . $last->body();
            } catch (\Throwable $e) {
                Log::debug("OpenSubtitlesService::calculateHash HTTP error: {$e->getMessage()}");
                return null;
            }
        } elseif (is_file($path) && is_readable($path)) {
            $length = (int) filesize($path);
            if ($length < $chunkSize) {
                return null;
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            $first = fread($handle, $chunkSize);
            fseek($handle, $length - $chunkSize);
            $last = fread($handle, $chunkSize);
            fclose($handle);
            $data = (string) $first . (string) $last;
        } else {
            return null;
        }

        if (strlen($data) < $chunkSize * 2) {
            return null;
        }

        $low = $length & 0xFFFFFFFF;
        $high = ($length >> 32) & 0xFFFFFFFF;

        $words = unpack('V*', $data);
        $count = count($words);
        for ($i = 1; $i <= $count; $i += 2) {
            $wLow = $words[$i] ?? 0;
            $wHigh = $words[$i + 1] ?? 0;

            $sumLow = $low + $wLow;
            $carry = ($sumLow > 0xFFFFFFFF) ? 1 : 0;
            $low = $sumLow & 0xFFFFFFFF;
            $high = ($high + $wHigh + $carry) & 0xFFFFFFFF;
        }

        return sprintf('%08x%08x', $high, $low);
    }

    public function searchSubtitles(array $params): array
    {
        $settings = $this->getSettings();
        $apiKey = trim($settings['api_key']);
        if ($apiKey === '') {
            throw new \RuntimeException(
                'API Key de OpenSubtitles no configurada. Configúrala en Plugins -> OpenSubtitles o OPENSUBTITLES_API_KEY en .env.'
            );
        }

        $query = [];

        if (!empty($params['file_path']) && empty($params['moviehash'])) {
            $hash = $this->calculateHash((string) $params['file_path']);
            if ($hash !== null) {
                $query['moviehash'] = $hash;
            }
        } elseif (!empty($params['moviehash'])) {
            $query['moviehash'] = (string) $params['moviehash'];
        }

        if (!empty($params['imdb_id'])) {
            $numericImdb = preg_replace('/^tt/i', '', (string) $params['imdb_id']);
            if (is_numeric($numericImdb)) {
                $query['imdb_id'] = (int) $numericImdb;
            }
        }

        if (!empty($params['tmdb_id']) && is_numeric($params['tmdb_id'])) {
            $query['tmdb_id'] = (int) $params['tmdb_id'];
        }
        if (!empty($params['query'])) {
            $query['query'] = trim((string) $params['query']);
        }
        if (!empty($params['year']) && is_numeric($params['year'])) {
            $query['year'] = (int) $params['year'];
        }
        if (!empty($params['season_number']) && is_numeric($params['season_number'])) {
            $query['season_number'] = (int) $params['season_number'];
        }
        if (!empty($params['episode_number']) && is_numeric($params['episode_number'])) {
            $query['episode_number'] = (int) $params['episode_number'];
        }
        if (!empty($params['type']) && in_array($params['type'], ['movie', 'episode', 'all'], true)) {
            $query['type'] = $params['type'];
        }

        $langs = $params['languages'] ?? $settings['languages'];
        if (is_string($langs)) {
            $langs = explode(',', $langs);
        }
        $normalizedLangs = array_values(array_unique(array_filter(array_map(
            fn ($l) => $this->normalizeLanguageCode((string) $l),
            is_array($langs) ? $langs : ['es', 'en']
        ))));
        $query['languages'] = implode(',', $normalizedLangs ?: ['es', 'en']);

        $preferredLang = $this->normalizeLanguageCode((string) ($params['preferred_language'] ?? ($normalizedLangs[0] ?? 'es')));
        ksort($query);

        $cacheKey = 'flix_os_search:' . hash('sha256', json_encode($query));
        $results = Cache::remember($cacheKey, now()->addMinutes($this->cacheTtlMinutes), function () use ($apiKey, $query) {
            $response = Http::timeout(12)
                ->withHeaders($this->baseHeaders($apiKey))
                ->get($this->baseUrl . '/subtitles', $query);

            if (!$response->successful()) {
                $err = $response->json('message') ?? ('HTTP ' . $response->status());
                throw new \RuntimeException("Error consultando OpenSubtitles: {$err}");
            }

            $rows = [];
            foreach ($response->json('data', []) as $entry) {
                $attr = $entry['attributes'] ?? [];
                $files = $attr['files'] ?? [];
                if (empty($files[0]['file_id'])) {
                    continue;
                }
                $rows[] = [
                    'subtitle_id' => (string) ($attr['subtitle_id'] ?? $entry['id'] ?? ''),
                    'file_id' => (int) $files[0]['file_id'],
                    'file_name' => (string) ($files[0]['file_name'] ?? 'subtitle.srt'),
                    'language' => (string) ($attr['language'] ?? ''),
                    'release' => (string) ($attr['release'] ?? ''),
                    'ratings' => (float) ($attr['ratings'] ?? 0),
                    'download_count' => (int) ($attr['download_count'] ?? 0),
                    'moviehash_match' => (bool) ($attr['moviehash_match'] ?? false),
                    'hearing_impaired' => (bool) ($attr['hearing_impaired'] ?? false),
                    'ai_translated' => (bool) ($attr['ai_translated'] ?? false),
                ];
            }

            return $rows;
        });

        usort($results, static function (array $a, array $b) use ($preferredLang): int {
            $cmpHash = ((int) $b['moviehash_match']) <=> ((int) $a['moviehash_match']);
            if ($cmpHash !== 0) {
                return $cmpHash;
            }
            $cmpRating = $b['ratings'] <=> $a['ratings'];
            if ($cmpRating !== 0) {
                return $cmpRating;
            }
            $aPref = strtolower($a['language']) === $preferredLang ? 1 : 0;
            $bPref = strtolower($b['language']) === $preferredLang ? 1 : 0;
            return $bPref <=> $aPref;
        });

        return [
            'query' => $query,
            'total' => count($results),
            'results' => $results,
        ];
    }

    public function downloadSubtitle(int $fileId, ?string $targetDir = null, ?string $desiredFileName = null): array
    {
        $settings = $this->getSettings();
        $apiKey = trim($settings['api_key']);
        if ($apiKey === '') {
            throw new \RuntimeException('API Key de OpenSubtitles no configurada.');
        }

        $token = null;
        if ($settings['username'] !== '' && $settings['password'] !== '') {
            $loginResp = Http::timeout(12)
                ->withHeaders($this->baseHeaders($apiKey))
                ->post($this->baseUrl . '/login', [
                    'username' => $settings['username'],
                    'password' => $settings['password'],
                ]);

            if ($loginResp->successful()) {
                $token = $loginResp->json('token');
            }
        }

        try {
            $headers = $this->baseHeaders($apiKey);
            if ($token) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $dlResp = Http::timeout(15)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/download', [
                    'file_id' => $fileId,
                ]);

            if (!$dlResp->successful()) {
                $err = $dlResp->json('message') ?? ('HTTP ' . $dlResp->status());
                throw new \RuntimeException("Error solicitando enlace de descarga OpenSubtitles: {$err}");
            }

            $link = (string) $dlResp->json('link', '');
            $remoteName = (string) ($dlResp->json('file_name') ?: "subtitle_{$fileId}.srt");
            if ($link === '') {
                throw new \RuntimeException('OpenSubtitles no devolvió un enlace de descarga válido.');
            }

            $dir = $targetDir ?: $settings['storage_dir'];
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0775, true, true);
            }

            $safeName = preg_replace('/[<>:"\/\\\\|?*%+]/', '_', $desiredFileName ?: $remoteName) ?: "subtitle_{$fileId}.srt";
            $savePath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $safeName;

            $contentResp = Http::timeout(20)->get($link);
            if (!$contentResp->successful()) {
                throw new \RuntimeException('No se pudo descargar el contenido del subtítulo: HTTP ' . $contentResp->status());
            }

            File::put($savePath, $contentResp->body());

            return [
                'success' => true,
                'file_id' => $fileId,
                'file_name' => $safeName,
                'saved_path' => $savePath,
                'remaining_quota' => $dlResp->json('remaining'),
                'reset_time_utc' => $dlResp->json('reset_time_utc'),
            ];
        } finally {
            if ($token) {
                try {
                    Http::timeout(6)
                        ->withHeaders(array_merge($this->baseHeaders($apiKey), [
                            'Authorization' => 'Bearer ' . $token,
                        ]))
                        ->delete($this->baseUrl . '/logout');
                } catch (\Throwable $e) {
                    // Ignorar error de logout al cerrar sesión
                }
            }
        }
    }

    protected function baseHeaders(string $apiKey): array
    {
        return [
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
    }
}
