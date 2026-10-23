<?php

namespace App\Services;

use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PosterDownloadService
{
    /**
     * Disco de almacenamiento público de Laravel.
     */
    protected string $diskName = 'public';

    /**
     * Directorio relativo dentro de storage/app/public/.
     */
    protected string $postersSubdir = 'posters';

    /**
     * Catálogo de respaldo curado para títulos OTT populares con URLs CDN directas de TMDb.
     * Permite descargar el póster original de TMDb incluso sin clave de API configurada.
     */
    protected array $knownOttPosters = [
        'stranger things' => 'https://image.tmdb.org/t/p/w500/49WJfeN0moxb9IPfGn8AIqMGskD.jpg',
        'merlina' => 'https://media.themoviedb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg',
        'wednesday' => 'https://media.themoviedb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg',
        'el juego del calamar' => 'https://media.themoviedb.org/t/p/w500/1QdXdRYfktUSONkl1oD5gc6Be0s.jpg',
        'squid game' => 'https://media.themoviedb.org/t/p/w500/1QdXdRYfktUSONkl1oD5gc6Be0s.jpg',
        'mision de rescate 2' => 'https://media.themoviedb.org/t/p/w500/7gKI9hpEMcZUQpNgKrkDzJpbnNS.jpg',
        'extraction 2' => 'https://media.themoviedb.org/t/p/w500/7gKI9hpEMcZUQpNgKrkDzJpbnNS.jpg',
        'glass onion' => 'https://media.themoviedb.org/t/p/w500/vDGr1YdrlfbU9wxTOdpf3zChmv9.jpg',
        'the mandalorian' => 'https://image.tmdb.org/t/p/w500/eU1i6eHXlzMOlEq0ku1R07YQ7Te.jpg',
        'loki' => 'https://image.tmdb.org/t/p/w500/voHUmlvjysvjGkc6s1L3K7D4T5m.jpg',
        'avatar el camino del agua' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMCA60NsSmeqe9RmNV.jpg',
        'avatar the way of water' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMCA60NsSmeqe9RmNV.jpg',
        'guardianes de la galaxia vol 3' => 'https://image.tmdb.org/t/p/w500/r2J02Z2OpNTctfOSN2Ydgii51I3.jpg',
        'elementos' => 'https://image.tmdb.org/t/p/w500/8riWfqPTUI0G5q5vj8Vb4q7L0L0.jpg',
        'elemental' => 'https://image.tmdb.org/t/p/w500/8riWfqPTUI0G5q5vj8Vb4q7L0L0.jpg',
        'the last of us' => 'https://image.tmdb.org/t/p/w500/uKvVjHNqB5VmOrdxqMisytaq9m.jpg',
        'la casa del dragon' => 'https://image.tmdb.org/t/p/w500/1X4h40fcB4WWUmIBK0auT4zRBAV.jpg',
        'house of the dragon' => 'https://image.tmdb.org/t/p/w500/1X4h40fcB4WWUmIBK0auT4zRBAV.jpg',
        'duna parte dos' => 'https://image.tmdb.org/t/p/w500/8b8R8l88Qje9dn9OE8PY05Nxl1X.jpg',
        'dune part two' => 'https://image.tmdb.org/t/p/w500/8b8R8l88Qje9dn9OE8PY05Nxl1X.jpg',
        'the batman' => 'https://image.tmdb.org/t/p/w500/74xTEgt7R36Fpooo50r9T25onhq.jpg',
        'succession' => 'https://image.tmdb.org/t/p/w500/7DTkn8s1L5e51JjVwXzCg1sCj2J.jpg',
        'the boys' => 'https://image.tmdb.org/t/p/w500/mY7SeH4YFFxWv167RIIz3el9mNL.jpg',
        'el senor de los anillos los anillos de poder' => 'https://image.tmdb.org/t/p/w500/mYLOqiStMxDK3fYZFirgrMt8z5d.jpg',
        'the lord of the rings the rings of power' => 'https://image.tmdb.org/t/p/w500/mYLOqiStMxDK3fYZFirgrMt8z5d.jpg',
        'severance' => 'https://image.tmdb.org/t/p/w500/u3bZgnGQ9T01sWNhyveQz0wH0Hl.jpg',
        'ted lasso' => 'https://image.tmdb.org/t/p/w500/5fhZdwP1DVJ0FySXBD6Cz7ST6BP.jpg',
        'halo' => 'https://image.tmdb.org/t/p/w500/hmHA5jqxN3ESIAGx0jAwV7T43Te.jpg',
        'top gun maverick' => 'https://image.tmdb.org/t/p/w500/62HCnUTziyWcpDaBO2i1DX17ljH.jpg',
    ];

    /**
     * Asegura la creación física del directorio storage/app/public/posters con permisos 0777.
     */
    public function ensureDirectoryExists(): string
    {
        $dirPath = storage_path('app/public/' . $this->postersSubdir);
        if (!File::isDirectory($dirPath)) {
            File::makeDirectory($dirPath, 0777, true, true);
        }
        @chmod($dirPath, 0777);

        return $dirPath;
    }

    /**
     * Descarga el póster físicamente a storage/app/public/posters/{slug}.jpg
     * y retorna la ruta servida por symlink: /storage/posters/{slug}.jpg
     *
     * @param string $slug Identificador único para el archivo (ej: stranger-things)
     * @param string|null $candidateUrl URL externa previa (ej: https://image.tmdb.org/...)
     * @param string|null $title Título del medio para búsqueda en TMDb si candidateUrl falla
     * @param string|null $tmdbId ID en TMDb si se conoce
     * @param bool $force Si es true, sobrescribe el archivo existente
     * @return string Ruta relativa web servida por symlink (/storage/posters/{slug}.jpg)
     */
    public function downloadAndStorePoster(
        string $slug,
        ?string $candidateUrl = null,
        ?string $title = null,
        ?string $tmdbId = null,
        bool $force = false
    ): string {
        $this->ensureDirectoryExists();

        $cleanSlug = Str::slug($slug);
        if (empty($cleanSlug)) {
            $cleanSlug = !empty($title) ? Str::slug($title) : 'poster-' . time();
        }

        $fileName = "{$cleanSlug}.jpg";
        $storageRelative = "{$this->postersSubdir}/{$fileName}";
        $publicSymlinkUrl = "/storage/{$storageRelative}";
        $fullPhysicalPath = storage_path("app/public/{$storageRelative}");

        // 1. Si no se fuerza y el archivo ya existe con contenido válido (> 500 bytes), retornar de inmediato
        if (!$force && File::exists($fullPhysicalPath) && File::size($fullPhysicalPath) > 500) {
            return $publicSymlinkUrl;
        }

        $saved = false;

        // 2. Si candidateUrl es una URL HTTP(S) externa válida, intentar descarga directa
        if (!empty($candidateUrl) && (str_starts_with($candidateUrl, 'http://') || str_starts_with($candidateUrl, 'https://'))) {
            $saved = $this->fetchAndSaveImage($candidateUrl, $fullPhysicalPath);
        }

        // 3. Si no se pudo guardar con candidateUrl, consultar TMDb por ID o por título
        if (!$saved) {
            $tmdbUrl = $this->resolveTmdbPosterUrl($title, $tmdbId);
            if ($tmdbUrl) {
                $saved = $this->fetchAndSaveImage($tmdbUrl, $fullPhysicalPath);
            }
        }

        // 4. Si aún no se pudo descargar (ej: sin conexión o error de red), usar fallback físico
        if (!$saved && !File::exists($fullPhysicalPath)) {
            $this->copyFallbackPlaceholder($fullPhysicalPath);
        }

        // Asegurar permisos de lectura en el archivo guardado
        if (File::exists($fullPhysicalPath)) {
            @chmod($fullPhysicalPath, 0666);
        }

        return $publicSymlinkUrl;
    }

    /**
     * Descarga la imagen mediante HTTP con User-Agent estándar y timeout seguro.
     */
    protected function fetchAndSaveImage(string $url, string $destinationPath): bool
    {
        try {
            $response = Http::timeout(10)
                ->withOptions([
                    'allow_redirects' => true,
                    'verify' => false,
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/jpeg,image/png,image/*,*/*;q=0.8',
                    'Referer' => 'https://www.themoviedb.org/',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning("PosterDownloadService: Error HTTP {$response->status()} descargando desde {$url}");
                return false;
            }

            $body = $response->body();
            if (strlen($body) < 200) {
                return false;
            }

            // Validar que no sea una página de error en HTML
            $start = strtolower(substr(ltrim($body), 0, 100));
            if (str_starts_with($start, '<!doctype') || str_starts_with($start, '<html')) {
                return false;
            }

            File::put($destinationPath, $body);
            @chmod($destinationPath, 0666);

            Log::info("PosterDownloadService: Póster guardado físicamente en {$destinationPath} (" . strlen($body) . " bytes)");
            return true;
        } catch (\Throwable $e) {
            Log::warning("PosterDownloadService: Excepción descargando {$url}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resuelve la URL del póster en TMDb mediante API o mapa curado.
     */
    public function resolveTmdbPosterUrl(?string $title, ?string $tmdbId = null): ?string
    {
        // 1. Intentar consulta a la API oficial de TMDb si hay plugin configurado
        $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
        $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        $language = $tmdbPlugin?->settings['language'] ?? 'es-ES';

        if (!empty($apiKey) && !str_starts_with($apiKey, 'tmdb_live_key')) {
            try {
                // Si tenemos tmdb_id específico
                if (!empty($tmdbId)) {
                    $endpoint = "https://api.themoviedb.org/3/movie/{$tmdbId}";
                    $res = Http::timeout(6)->get($endpoint, ['api_key' => $apiKey, 'language' => $language]);
                    if (!$res->successful()) {
                        $endpoint = "https://api.themoviedb.org/3/tv/{$tmdbId}";
                        $res = Http::timeout(6)->get($endpoint, ['api_key' => $apiKey, 'language' => $language]);
                    }
                    if ($res->successful() && !empty($res['poster_path'])) {
                        return "https://image.tmdb.org/t/p/w500{$res['poster_path']}";
                    }
                }

                // Si tenemos título, buscar en TMDb
                if (!empty($title)) {
                    $res = Http::timeout(6)->get('https://api.themoviedb.org/3/search/multi', [
                        'api_key' => $apiKey,
                        'query' => $title,
                        'language' => $language,
                        'include_adult' => false,
                    ]);

                    if ($res->successful() && !empty($res['results'])) {
                        foreach ($res['results'] as $item) {
                            if (!empty($item['poster_path'])) {
                                return "https://image.tmdb.org/t/p/w500{$item['poster_path']}";
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("PosterDownloadService: Error consultando TMDb API: " . $e->getMessage());
            }
        }

        // 2. Consulta en diccionario curado por título normalizado
        if (!empty($title)) {
            $norm = Str::slug($title);
            $normSpaces = str_replace('-', ' ', $norm);

            // Búsqueda exacta
            if (isset($this->knownOttPosters[$normSpaces])) {
                return $this->knownOttPosters[$normSpaces];
            }

            // Búsqueda por coincidencia parcial de palabras clave
            foreach ($this->knownOttPosters as $k => $url) {
                if (str_contains($normSpaces, $k) || str_contains($k, $normSpaces)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Copia la imagen de placeholder existente como respaldo físico garantizando que el archivo nunca falte.
     */
    protected function copyFallbackPlaceholder(string $destinationPath): void
    {
        $candidates = [
            public_path('placeholder-poster.png'),
            public_path('img/placeholder-poster.png'),
            base_path('../frontend/public/placeholder-poster.png'),
        ];

        foreach ($candidates as $cand) {
            if (File::exists($cand)) {
                @copy($cand, $destinationPath);
                @chmod($destinationPath, 0666);
                Log::info("PosterDownloadService: Copiado fallback desde {$cand} hacia {$destinationPath}");
                return;
            }
        }

        // Si no se encuentra archivo existente, crear imagen PNG básica 1x1 transparente
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        File::put($destinationPath, $tinyPng);
        @chmod($destinationPath, 0666);
    }
}
