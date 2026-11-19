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
    public const UA_FLIXGRAB = 'FlixGrab/4.0 (OTT-Sync-Official)';
    public const UA_VISIONOS = 'FlixGrab/4.0 (OTT-Sync-Official)';
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
        363976887  => 'hawkeye',    // Fake hardlink to mitorrent.org Hawkeye
        2853203619 => 'invasion',   // Fake hardlink to PelisEnHD Secret Invasion
        781274825  => 'phineas',    // Fake hardlink to Phineas y Ferb
        456678163  => 'spartacus',  // Fake hardlink to Spartacus
        2542046808 => 'mandalorian',
    ];

    /**
     * Sitios web externos y fuentes prohibidas (YouTube, Dailymotion, Archive.org y sitios piratas).
     */
    protected array $bannedPatterns = [
        'youtube.com', 'youtu.be', 'youtubei', 'googlevideo.com',
        'dailymotion.com', 'dmcdn.net', 'vimeo.com', 'archive.org',
        'commondatastorage.googleapis.com', 'gtv-videos-bucket',
        'blender.org', 'test-streams.mux.dev', 'akamaized.net/dash264',
        'cuevana', 'pelisplus', 'repelis',
    ];

    public function isBannedUrl(?string $url): bool
    {
        if (empty($url)) return false;
        $lower = strtolower($url);
        foreach ($this->bannedPatterns as $banned) {
            if (str_contains($lower, $banned)) return true;
        }
        return false;
    }

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

    /**
     * Extrae todas las variantes del título (ej. "John Wick (Otro día para matar)" -> ["John Wick (Otro día para matar)", "John Wick", "Otro día para matar"]).
     */
    public function extractTitleVariants(string $text): array
    {
        $raw = trim($text);
        if ($raw === '') return [];
        $variants = [$raw];
        $noYear = trim((string) preg_replace('/\b(19|20)\d{2}\b/', '', $raw));
        if ($noYear !== '' && !in_array($noYear, $variants, true)) {
            $variants[] = $noYear;
        }
        $outsideParens = trim((string) preg_replace('/\(.*?\)|\[.*?\]/', '', $noYear));
        if ($outsideParens !== '' && !in_array($outsideParens, $variants, true)) {
            $variants[] = $outsideParens;
        }
        if (preg_match_all('/\(([^()]{3,})\)/u', $raw, $matches)) {
            foreach ($matches[1] as $inner) {
                $innerClean = trim($inner);
                if ($innerClean !== '' && !is_numeric($innerClean) && !in_array($innerClean, $variants, true)) {
                    $variants[] = $innerClean;
                }
            }
        }
        if (str_contains($outsideParens, ':')) {
            $parts = explode(':', $outsideParens);
            foreach ($parts as $p) {
                $pClean = trim($p);
                if (mb_strlen($pClean) >= 3 && !in_array($pClean, $variants, true)) {
                    $variants[] = $pClean;
                }
            }
        }
        return $variants;
    }

    public function extractNormalizedSet(string $text): array
    {
        $norms = [];
        foreach ($this->extractTitleVariants($text) as $v) {
            $n = preg_replace('/[^a-z0-9]+/', '', $this->stripAccents(mb_strtolower($v)));
            if (strlen($n) >= 3) {
                $norms[$n] = true;
            }
        }
        $base = $this->normalizeExact($text);
        if (strlen($base) >= 3) {
            $norms[$base] = true;
        }
        return array_keys($norms);
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
     * Resuelve la fuente de video REAL EXCLUSIVAMENTE desde:
     * 1) Archivo físico autenticado en titles (file_path o source_path) o episodes.
     * 2) Biblioteca del servidor multimedia vinculado (Emby).
     * 3) Stream directo legítimo (NO YouTube/Dailymotion/Archive/pirata) de una cuenta vinculada en OTT Sync.
     * PROHIBIDO terminantemente usar YouTube, Dailymotion o páginas piratas.
     */
    public function resolveRealSource(string $titleName, ?string $mediaType = null, ?string $sourceUrl = null, ?string $provider = null): ?array
    {
        $titleName = trim($titleName);
        if (empty($titleName)) {
            return null;
        }

        // Si sourceUrl es prohibida (YouTube, Dailymotion, Archive.org, etc.), descartarla
        if (!empty($sourceUrl) && $this->isBannedUrl($sourceUrl)) {
            Log::warning("RealMediaSourceResolver: URL externa prohibida bloqueada para [{$titleName}]: {$sourceUrl}");
            $sourceUrl = null;
        }

        // Determinar si es serie o película
        if (empty($mediaType)) {
            $dbType = Title::where('name', $titleName)->value('type');
            $mediaType = $dbType ?: OttSyncService::normalizeMediaType(null, $titleName);
        }
        $isSeries = ($mediaType === 'series');

        // Obtener variantes del título para coincidencia exacta y parentética
        $titleVariants = $this->extractTitleVariants($titleName);

        // 1. Buscar en base de datos local (titles.file_path, titles.source_path y episodes.file_path)
        $localDbMatch = $this->findLocalDatabaseMatch($titleVariants, $isSeries);
        if ($localDbMatch) {
            return $localDbMatch;
        }

        // 2. Buscar en Emby local usando título y variantes (incluyendo OriginalTitle)
        $embyMatch = $this->findExactLocalOrEmbyMatch($titleName, $isSeries, $titleVariants);
        if ($embyMatch) {
            return $embyMatch;
        }

        // 3. Si sourceUrl es un stream directo legítimo y no prohibido
        if (!empty($sourceUrl) && !$this->isBannedUrl($sourceUrl)) {
            if (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://')) {
                return [
                    'mode' => 'single_url',
                    'video_url' => $sourceUrl,
                    'audio_url' => null,
                    'total_bytes' => 800 * 1024 * 1024,
                    'matched_title' => $titleName,
                    'user_agent' => self::UA_FLIXGRAB,
                ];
            }
        }

        Log::warning("RealMediaSourceResolver: No se encontró stream autenticado para [{$titleName}] en plataformas vinculadas. Se rechaza cualquier fallback a YouTube o páginas externas.");
        return null;
    }

    /**
     * Busca en la base de datos local verificando títulos auténticos en titles y episodes.
     * Revisa tanto file_path como source_path.
     */
    protected function findLocalDatabaseMatch(array $titleVariants, bool $isSeries): ?array
    {
        $targetNorms = [];
        foreach ($titleVariants as $v) {
            foreach ($this->extractNormalizedSet($v) as $n) {
                $targetNorms[$n] = true;
            }
        }
        if (empty($targetNorms)) {
            return null;
        }

        $titles = Title::where(function ($q) {
            $q->whereNotNull('file_path')->orWhereNotNull('source_path');
        })->get();

        foreach ($titles as $t) {
            $tNorms = $this->extractNormalizedSet($t->name);
            if (!empty($t->slug)) {
                $tNorms = array_merge($tNorms, $this->extractNormalizedSet(str_replace('-', ' ', $t->slug)));
            }
            $intersect = array_intersect(array_keys($targetNorms), $tNorms);
            if (empty($intersect)) {
                continue;
            }

            foreach (['file_path', 'source_path'] as $col) {
                $rawPath = $t->{$col};
                if (!empty($rawPath)) {
                    $diskPath = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($rawPath) ?: $rawPath;
                    if (file_exists($diskPath) && !$this->isWrongOrLegacyFile($diskPath, $t->name) && @filesize($diskPath) > 2 * 1024 * 1024) {
                        Log::info("RealMediaSourceResolver: Archivo local encontrado en BD para [{$t->name}] ({$col}): {$diskPath}");
                        return [
                            'mode' => 'local_file',
                            'file_path' => $diskPath,
                            'total_bytes' => (int) @filesize($diskPath),
                            'matched_title' => $t->name,
                            'title_id' => $t->id,
                        ];
                    }
                }
            }

            if ($t->type === 'series' || $isSeries) {
                $ep = Episode::where('title_id', $t->id)
                    ->whereNotNull('file_path')
                    ->orderBy('season_number', 'asc')
                    ->orderBy('episode_number', 'asc')
                    ->first();
                if ($ep && !empty($ep->file_path)) {
                    $epDiskPath = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($ep->file_path) ?: $ep->file_path;
                    if (file_exists($epDiskPath) && !$this->isWrongOrLegacyFile($epDiskPath, $t->name) && @filesize($epDiskPath) > 2 * 1024 * 1024) {
                        Log::info("RealMediaSourceResolver: Episodio local encontrado en BD para [{$t->name}]: {$epDiskPath}");
                        return [
                            'mode' => 'local_file',
                            'file_path' => $epDiskPath,
                            'total_bytes' => (int) @filesize($epDiskPath),
                            'matched_title' => $t->name . ' - ' . ($ep->name ?: "S01E01"),
                            'title_id' => $t->id,
                            'episode_id' => $ep->id,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Busca en Emby y discos locales mediante el servidor multimedia vinculado.
     */
    protected function findExactLocalOrEmbyMatch(string $titleName, bool $isSeries, array $titleVariants = []): ?array
    {
        if (empty($titleVariants)) {
            $titleVariants = $this->extractTitleVariants($titleName);
        }
        $targetNorms = [];
        foreach ($titleVariants as $v) {
            foreach ($this->extractNormalizedSet($v) as $n) {
                $targetNorms[$n] = true;
            }
        }
        if (empty($targetNorms)) {
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

        $ctx = stream_context_create(['http' => ['timeout' => 4, 'User-Agent' => self::UA_FLIXGRAB]]);

        foreach ($titleVariants as $searchVariant) {
            $q = urlencode($searchVariant);
            $url = "{$serverUrl}/emby/Items?SearchTerm={$q}&IncludeItemTypes=Movie,Series&Recursive=true&Fields=MediaSources,Path,OriginalTitle&api_key={$apiKey}";

            $res = @file_get_contents($url, false, $ctx);
            if (!$res) {
                continue;
            }

            $data = json_decode($res, true);
            foreach ($data['Items'] ?? [] as $item) {
                $itemName = $item['Name'] ?? '';
                $itemOrig = $item['OriginalTitle'] ?? '';
                $itemNorms = array_merge($this->extractNormalizedSet($itemName), $this->extractNormalizedSet($itemOrig));
                $intersect = array_intersect(array_keys($targetNorms), $itemNorms);
                if (empty($intersect)) {
                    continue;
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
                            if (file_exists($mapped) && !$this->isWrongOrLegacyFile($mapped, $itemName) && filesize($mapped) > 5 * 1024 * 1024) {
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
                                'user_agent' => self::UA_FLIXGRAB,
                            ];
                        }
                    }
                } else {
                    $rawPath = $item['Path'] ?? ($item['MediaSources'][0]['Path'] ?? '');
                    if ($rawPath) {
                        $mapped = str_replace('/home/server01/', '/mnt/media_library/', $rawPath);
                        if (file_exists($mapped) && !$this->isWrongOrLegacyFile($mapped, $itemName) && filesize($mapped) > 5 * 1024 * 1024) {
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
                        'user_agent' => self::UA_FLIXGRAB,
                    ];
                }
            }
        }

        return null;
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
        $ua = $resolved['user_agent'] ?? self::UA_FLIXGRAB;

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
