<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Season;
use App\Models\ServerSetting;
use App\Models\Title;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MediaSyncService
{
    /**
     * Definición de bibliotecas principales requeridas según arquitectura FlixHN.
     */
    protected array $defaultLibraries = [
        [
            'name' => 'Estrenos',
            'slug' => 'estrenos',
            'type' => 'movie',
            'folder_path' => 'estrenos',
            'description' => 'Lanzamientos recientes y estrenos del servidor.',
            'sort_order' => 1,
        ],
        [
            'name' => 'Películas',
            'slug' => 'peliculas',
            'type' => 'movie',
            'folder_path' => 'movies',
            'description' => 'Largometrajes cinematográficos en alta definición On-Net.',
            'sort_order' => 2,
        ],
        [
            'name' => 'Series',
            'slug' => 'series',
            'type' => 'series',
            'folder_path' => 'series',
            'description' => 'Series completas, temporadas y episodios para maratonear.',
            'sort_order' => 3,
        ],
        [
            'name' => 'Animadas',
            'slug' => 'animadas',
            'type' => 'movie',
            'folder_path' => 'animadas',
            'description' => 'Películas animadas y animación familiar.',
            'sort_order' => 4,
        ],
        [
            'name' => 'Infantiles',
            'slug' => 'infantiles',
            'type' => 'mixed',
            'folder_path' => 'infantiles',
            'description' => 'Contenido infantil y caricaturas para toda la familia.',
            'sort_order' => 5,
        ],
        [
            'name' => 'Documentales',
            'slug' => 'documentales',
            'type' => 'mixed',
            'folder_path' => 'documentales',
            'description' => 'Naturaleza, ciencia, historia y exploraciones.',
            'sort_order' => 6,
        ],
        [
            'name' => 'Crimen',
            'slug' => 'crimen',
            'type' => 'mixed',
            'folder_path' => 'crimen',
            'description' => 'Investigaciones policiales, suspense y drama criminal.',
            'sort_order' => 7,
        ],
        [
            'name' => 'Ficción',
            'slug' => 'ficcion',
            'type' => 'mixed',
            'folder_path' => 'ficcion',
            'description' => 'Ciencia ficción, viajes espaciales y tecnología.',
            'sort_order' => 8,
        ],
        [
            'name' => 'Cristianas',
            'slug' => 'cristianas',
            'type' => 'movie',
            'folder_path' => 'cristianas',
            'description' => 'Películas y producciones cristianas de valores.',
            'sort_order' => 9,
        ],
        [
            'name' => 'Colecciones',
            'slug' => 'colecciones',
            'type' => 'mixed',
            'folder_path' => 'colecciones',
            'description' => 'Colecciones cinematográficas y sagas completas.',
            'sort_order' => 10,
        ],
    ];

    /**
     * Extensiones de video reconocidas por el servidor.
     */
    protected array $videoExtensions = ['m3u8', 'mp4', 'mkv', 'webm', 'avi', 'mov'];

    /**
     * Nombres de archivos de carátulas/posters.
     */
    protected array $posterNames = ['poster.jpg', 'poster.png', 'cover.jpg', 'cover.png', 'folder.jpg', 'folder.png'];

    /**
     * Nombres de archivos de backdrops/fondos.
     */
    protected array $backdropNames = ['backdrop.jpg', 'backdrop.png', 'fanart.jpg', 'fanart.png', 'background.jpg'];

    /**
     * Ejecutar sincronización completa del servidor de medios.
     */
    public function sync(?string $serverUrl = null, ?string $apiKey = null): array
    {
        // 1. Inicializar/asegurar las categorías y bibliotecas base
        $categories = $this->ensureDefaultCategories();

        // 2. Determinar la ruta de almacenamiento de medios
        $storagePath = is_dir('/var/media') ? '/var/media' : base_path('../media');
        $this->ensureLibraryDirectories($storagePath);

        // 3. Sincronizar servidor Emby (server01-PowerEdge-R720)
        $remoteResults = $this->syncEmby($serverUrl, $apiKey);

        // 4. Escanear el almacenamiento local de medios
        $localResults = $this->scanLocalStorage($storagePath, $categories);

        // 5. Limpiar registros inexistentes o quemados previamente
        $validSourcePaths = array_merge($remoteResults['synced_sources'] ?? [], $localResults['synced_sources'] ?? []);
        $deletedCount = $this->cleanupStaleTitles($validSourcePaths);

        // 6. Recuento final
        $totalTitles = Title::count();
        $totalMovies = Title::where('type', 'movie')->count();
        $totalSeries = Title::where('type', 'series')->count();

        return [
            'status' => 'success',
            'server_name' => $remoteResults['server_name'] ?? 'server01-PowerEdge-R720',
            'message' => "Sincronización completada exitosamente. Total de títulos reales: {$totalTitles}",
            'metrics' => [
                'total_titles' => $totalTitles,
                'total_movies' => $totalMovies,
                'total_series' => $totalSeries,
                'deleted_stale' => $deletedCount,
                'synced_local' => count($localResults['synced_sources'] ?? []),
                'synced_remote' => count($remoteResults['synced_sources'] ?? []),
            ],
            'categories' => Category::withCount('titles')->get(),
        ];
    }

    /**
     * Asegurar que las categorías oficiales existan en la base de datos.
     */
    protected function ensureDefaultCategories(): array
    {
        $categories = [];
        foreach ($this->defaultLibraries as $lib) {
            $cat = Category::updateOrCreate(
                ['slug' => $lib['slug']],
                [
                    'name' => $lib['name'],
                    'type' => $lib['type'],
                    'folder_path' => $lib['folder_path'],
                    'description' => $lib['description'],
                    'sort_order' => $lib['sort_order'],
                    'is_active' => true,
                ]
            );
            $categories[$lib['folder_path']] = $cat;
            $categories[$lib['slug']] = $cat;
        }

        return $categories;
    }

    /**
     * Crear las carpetas de biblioteca si no existen en el almacenamiento.
     */
    protected function ensureLibraryDirectories(string $storagePath): void
    {
        if (!is_dir($storagePath)) {
            @mkdir($storagePath, 0777, true);
        }

        foreach ($this->defaultLibraries as $lib) {
            $dir = $storagePath . '/' . $lib['folder_path'];
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }

    /**
     * Escanear el sistema de archivos local (/var/media).
     */
    protected function scanLocalStorage(string $storagePath, array $categories): array
    {
        $syncedSources = [];
        if (!is_dir($storagePath)) {
            return ['synced_sources' => $syncedSources];
        }

        // Obtener todas las carpetas dentro del directorio de medios
        $libraryDirs = glob($storagePath . '/*', GLOB_ONLYDIR);
        if (!$libraryDirs) {
            return ['synced_sources' => $syncedSources];
        }

        foreach ($libraryDirs as $libDir) {
            $libFolderName = basename($libDir);
            
            // Asociar categoría correspondiente
            $category = $categories[$libFolderName] ?? $categories[strtolower($libFolderName)] ?? null;
            if (!$category) {
                // Crear categoría dinámica para carpetas adicionales creadas por el usuario
                $category = Category::firstOrCreate(
                    ['slug' => Str::slug($libFolderName)],
                    [
                        'name' => ucfirst($libFolderName),
                        'type' => str_contains(strtolower($libFolderName), 'serie') ? 'series' : 'movie',
                        'folder_path' => $libFolderName,
                        'sort_order' => 10,
                        'is_active' => true,
                    ]
                );
            }

            // Escanear contenido de esta biblioteca
            $syncedInLib = $this->scanLibraryFolder($libDir, $category, $storagePath);
            $syncedSources = array_merge($syncedSources, $syncedInLib);
        }

        return ['synced_sources' => $syncedSources];
    }

    /**
     * Escanear una carpeta de biblioteca específica.
     */
    protected function scanLibraryFolder(string $libDir, Category $category, string $storagePath): array
    {
        $syncedSources = [];
        $entries = scandir($libDir);
        if (!$entries) return [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $entryPath = $libDir . '/' . $entry;

            if (is_dir($entryPath)) {
                // Caso A: El título es una carpeta (ej. movies/Matrix (1999)/)
                $titleResult = $this->processTitleDirectory($entryPath, $category, $storagePath);
                if ($titleResult) {
                    $syncedSources[] = $titleResult;
                }
            } elseif (is_file($entryPath)) {
                // Caso B: El título es un archivo de video directo (ej. movies/Gladiador.mp4)
                $ext = strtolower(pathinfo($entryPath, PATHINFO_EXTENSION));
                if (in_array($ext, $this->videoExtensions) && $ext !== 'ts') {
                    $titleResult = $this->processDirectVideoFile($entryPath, $category, $storagePath);
                    if ($titleResult) {
                        $syncedSources[] = $titleResult;
                    }
                }
            }
        }

        return $syncedSources;
    }

    /**
     * Procesar un directorio que contiene un título (película o serie).
     */
    protected function processTitleDirectory(string $dirPath, Category $category, string $storagePath): ?string
    {
        $folderName = basename($dirPath);
        $cleanTitleInfo = $this->parseTitleAndYear($folderName);
        $titleName = $cleanTitleInfo['name'];
        $releaseYear = $cleanTitleInfo['year'];

        // Determinar si es serie según categoría o estructura interna
        $isSeries = $category->type === 'series' || str_contains(strtolower($category->slug), 'serie');

        // Buscar archivo de stream principal (index.m3u8 o video)
        $hlsPlaylist = $dirPath . '/index.m3u8';
        $streamFile = null;

        if (file_exists($hlsPlaylist)) {
            $streamFile = $hlsPlaylist;
        } else {
            // Buscar primer archivo de video en la carpeta
            $files = scandir($dirPath);
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, $this->videoExtensions) && $ext !== 'ts') {
                    $streamFile = $dirPath . '/' . $f;
                    break;
                }
            }
        }

        // Buscar imágenes de poster y backdrop
        $artwork = $this->findArtworkInDir($dirPath, $storagePath, $titleName);

        // Ruta relativa para streaming por nginx (puerto 7000)
        $relativeStreamPath = null;
        if ($streamFile) {
            $relativeStreamPath = ltrim(str_replace($storagePath, '', $streamFile), '/');
        }

        $sourcePath = 'local://' . ltrim(str_replace($storagePath, '', $dirPath), '/');
        $slug = Str::slug($titleName) . '-' . substr(md5($sourcePath), 0, 6);

        // Guardar o actualizar registro de título
        $title = Title::updateOrCreate(
            ['source_path' => $sourcePath],
            [
                'name' => $titleName,
                'slug' => $slug,
                'type' => $isSeries ? 'series' : 'movie',
                'category_id' => $category->id,
                'description' => "Contenido transmitido localmente desde la biblioteca {$category->name} del servidor.",
                'poster_url' => $artwork['poster_url'],
                'backdrop_url' => $artwork['backdrop_url'],
                'release_year' => $releaseYear ?: (int) date('Y'),
                'genre' => $category->name,
                'stream_path' => $relativeStreamPath ?: '',
                'duration_seconds' => $this->probeDuration($streamFile),
                'is_featured' => false,
            ]
        );

        // Si es serie, escanear temporadas y episodios
        if ($isSeries) {
            $this->processSeriesEpisodes($dirPath, $title, $storagePath);
        }

        return $sourcePath;
    }

    /**
     * Procesar un archivo de video directo suelto dentro de una biblioteca.
     */
    protected function processDirectVideoFile(string $filePath, Category $category, string $storagePath): ?string
    {
        $fileName = pathinfo($filePath, PATHINFO_FILENAME);
        $cleanTitleInfo = $this->parseTitleAndYear($fileName);
        $titleName = $cleanTitleInfo['name'];
        $releaseYear = $cleanTitleInfo['year'];

        $relativeStreamPath = ltrim(str_replace($storagePath, '', $filePath), '/');
        $sourcePath = 'local://' . $relativeStreamPath;
        $slug = Str::slug($titleName) . '-' . substr(md5($sourcePath), 0, 6);

        // Buscar póster en el mismo directorio si existe con mismo nombre
        $dir = dirname($filePath);
        $artwork = $this->findArtworkInDir($dir, $storagePath, $titleName);

        $title = Title::updateOrCreate(
            ['source_path' => $sourcePath],
            [
                'name' => $titleName,
                'slug' => $slug,
                'type' => $category->type === 'series' ? 'series' : 'movie',
                'category_id' => $category->id,
                'description' => "Archivo de video {$category->name} disponible en almacenamiento de alta velocidad.",
                'poster_url' => $artwork['poster_url'],
                'backdrop_url' => $artwork['backdrop_url'],
                'release_year' => $releaseYear ?: (int) date('Y'),
                'genre' => $category->name,
                'stream_path' => $relativeStreamPath,
                'duration_seconds' => $this->probeDuration($filePath),
                'is_featured' => false,
            ]
        );

        return $sourcePath;
    }

    /**
     * Escanear episodios y temporadas de una serie.
     */
    protected function processSeriesEpisodes(string $seriesDir, Title $title, string $storagePath): void
    {
        $entries = scandir($seriesDir);
        if (!$entries) return;

        // Temporada predeterminada 1
        $season = Season::firstOrCreate(
            [
                'title_id' => $title->id,
                'season_number' => 1,
            ],
            [
                'title' => 'Temporada 1',
            ]
        );

        $episodeNumber = 1;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $seriesDir . '/' . $entry;
            $entryName = pathinfo($entry, PATHINFO_FILENAME);

            // Detección de trailers o previas que comiencen con "0" (Punto 4)
            $isTrailer = preg_match('/^(0+|0\d+|cap[ií]tulo\s*0+|episodio\s*0+|trailer|especial)/i', $entryName);

            if (is_dir($path)) {
                // Subcarpeta de temporada o episodio (ej. s01e01/ o Temporada 1/)
                $subFiles = scandir($path);
                foreach ($subFiles as $sf) {
                    $ext = strtolower(pathinfo($sf, PATHINFO_EXTENSION));
                    if (in_array($ext, $this->videoExtensions) && $ext !== 'ts') {
                        $epStream = ltrim(str_replace($storagePath, '', $path . '/' . $sf), '/');
                        $fileIsTrailer = $isTrailer || preg_match('/^(0+|0\d+|cap[ií]tulo\s*0+|episodio\s*0+|trailer|especial)/i', pathinfo($sf, PATHINFO_FILENAME));

                        if ($fileIsTrailer) {
                            $seasonZero = Season::firstOrCreate(
                                ['title_id' => $title->id, 'season_number' => 0],
                                ['title' => 'Especiales / Trailers']
                            );
                            Episode::updateOrCreate(
                                [
                                    'title_id' => $title->id,
                                    'season_id' => $seasonZero->id,
                                    'episode_number' => 0,
                                ],
                                [
                                    'title' => "Trailer / Previa: " . ucwords(str_replace(['_', '-'], ' ', $entryName)),
                                    'description' => "Trailer y vista previa de {$title->name}.",
                                    'stream_path' => $epStream,
                                    'duration_seconds' => $this->probeDuration($path . '/' . $sf),
                                    'thumbnail_url' => $title->backdrop_url ?: $title->poster_url,
                                ]
                            );
                        } else {
                            Episode::updateOrCreate(
                                [
                                    'title_id' => $title->id,
                                    'season_id' => $season->id,
                                    'episode_number' => $episodeNumber,
                                ],
                                [
                                    'title' => "Episodio {$episodeNumber}: " . ucwords(str_replace(['_', '-'], ' ', pathinfo($entry, PATHINFO_FILENAME))),
                                    'description' => "Transmisión del episodio {$episodeNumber} de {$title->name}.",
                                    'stream_path' => $epStream,
                                    'duration_seconds' => $this->probeDuration($path . '/' . $sf),
                                    'thumbnail_url' => $title->backdrop_url ?: $title->poster_url,
                                ]
                            );
                            $episodeNumber++;
                        }
                        break;
                    }
                }
            } elseif (is_file($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $this->videoExtensions) && $ext !== 'ts') {
                    $epStream = ltrim(str_replace($storagePath, '', $path), '/');

                    if ($isTrailer) {
                        $seasonZero = Season::firstOrCreate(
                            ['title_id' => $title->id, 'season_number' => 0],
                            ['title' => 'Especiales / Trailers']
                        );
                        Episode::updateOrCreate(
                            [
                                'title_id' => $title->id,
                                'season_id' => $seasonZero->id,
                                'episode_number' => 0,
                            ],
                            [
                                'title' => "Trailer / Previa: " . ucwords(str_replace(['_', '-'], ' ', $entryName)),
                                'description' => "Trailer y vista previa de {$title->name}.",
                                'stream_path' => $epStream,
                                'duration_seconds' => $this->probeDuration($path),
                                'thumbnail_url' => $title->backdrop_url ?: $title->poster_url,
                            ]
                        );
                    } else {
                        Episode::updateOrCreate(
                            [
                                'title_id' => $title->id,
                                'season_id' => $season->id,
                                'episode_number' => $episodeNumber,
                            ],
                            [
                                'title' => "Episodio {$episodeNumber}: " . ucwords(str_replace(['_', '-'], ' ', pathinfo($entry, PATHINFO_FILENAME))),
                                'description' => "Transmisión del episodio {$episodeNumber} de {$title->name}.",
                                'stream_path' => $epStream,
                                'duration_seconds' => $this->probeDuration($path),
                                'thumbnail_url' => $title->backdrop_url ?: $title->poster_url,
                            ]
                        );
                        $episodeNumber++;
                    }
                }
            }
        }
    }

    /**
     * Buscar archivos de arte o generar póster SVG estilizado si no existen.
     */
    protected function findArtworkInDir(string $dir, string $storagePath, string $titleName): array
    {
        $posterUrl = null;
        $backdropUrl = null;

        // Buscar poster
        foreach ($this->posterNames as $pName) {
            $fullPath = $dir . '/' . $pName;
            if (file_exists($fullPath)) {
                $rel = ltrim(str_replace($storagePath, '', $fullPath), '/');
                $posterUrl = "http://localhost:7000/media/{$rel}";
                break;
            }
        }

        // Buscar backdrop
        foreach ($this->backdropNames as $bName) {
            $fullPath = $dir . '/' . $bName;
            if (file_exists($fullPath)) {
                $rel = ltrim(str_replace($storagePath, '', $fullPath), '/');
                $backdropUrl = "http://localhost:7000/media/{$rel}";
                break;
            }
        }

        // Generar arte SVG limpio si no hay imágenes físicas
        if (!$posterUrl) {
            $posterUrl = '/api/media/poster?title=' . urlencode($titleName);
        }
        if (!$backdropUrl) {
            $backdropUrl = '/api/media/backdrop?title=' . urlencode($titleName);
        }

        return [
            'poster_url' => $posterUrl,
            'backdrop_url' => $backdropUrl,
        ];
    }

    /**
     * Contenido SVG para póster vertical.
     */
    public function getSvgPosterContent(string $titleName): string
    {
        $escapedTitle = htmlspecialchars(Str::limit($titleName, 28), ENT_QUOTES, 'UTF-8');
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 750" width="100%" height="100%">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#1f1f1f"/>
      <stop offset="50%" stop-color="#141414"/>
      <stop offset="100%" stop-color="#0a0a0a"/>
    </linearGradient>
    <linearGradient id="redGrad" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#E50914"/>
      <stop offset="100%" stop-color="#B20710"/>
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#bg)"/>
  <rect x="20" y="20" width="460" height="710" fill="none" stroke="#2a2a2a" stroke-width="2" rx="8"/>
  <rect x="40" y="50" width="90" height="24" rx="4" fill="url(#redGrad)"/>
  <text x="85" y="66" fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="11" font-weight="900" text-anchor="middle" letter-spacing="1">FLIXHN</text>
  <circle cx="250" cy="320" r="64" fill="#181818" stroke="#333333" stroke-width="2"/>
  <polygon points="242,295 242,345 272,320" fill="#E50914"/>
  <text x="250" y="470" fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="24" font-weight="bold" text-anchor="middle">{$escapedTitle}</text>
  <text x="250" y="510" fill="#888888" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="13" font-weight="normal" text-anchor="middle">ALMACENAMIENTO LOCAL ON-NET</text>
  <text x="250" y="680" fill="#555555" font-family="monospace" font-size="10" text-anchor="middle">4K ULTRA HD • DIRECT PLAY</text>
</svg>
SVG;
    }

    /**
     * Contenido SVG para backdrop horizontal.
     */
    public function getSvgBackdropContent(string $titleName): string
    {
        $escapedTitle = htmlspecialchars(Str::limit($titleName, 40), ENT_QUOTES, 'UTF-8');
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080" width="100%" height="100%">
  <defs>
    <linearGradient id="bgH" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#1c1c1c"/>
      <stop offset="60%" stop-color="#121212"/>
      <stop offset="100%" stop-color="#080808"/>
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="40%" r="50%">
      <stop offset="0%" stop-color="#E50914" stop-opacity="0.25"/>
      <stop offset="100%" stop-color="#000000" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#bgH)"/>
  <rect width="100%" height="100%" fill="url(#glow)"/>
  <text x="960" y="540" fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="56" font-weight="900" text-anchor="middle" letter-spacing="2">{$escapedTitle}</text>
  <text x="960" y="620" fill="#E50914" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="20" font-weight="bold" text-anchor="middle" letter-spacing="4">STREAMING LOCAL ISP ON-NET</text>
</svg>
SVG;
    }

    /**
     * Sincronizar con el servidor de medios Emby (server01-PowerEdge-R720).
     */
    public function syncEmby(?string $serverUrl = null, ?string $apiKey = null): array
    {
        $serverUrl = $serverUrl ? rtrim(trim($serverUrl), '/') : (ServerSetting::get('emby_server_url') ?: 'http://45.4.87.126:6789');
        if (!str_starts_with($serverUrl, 'http://') && !str_starts_with($serverUrl, 'https://')) {
            $serverUrl = 'http://' . $serverUrl;
        }

        $apiKey = $apiKey !== null ? trim($apiKey) : ServerSetting::get('emby_api_key');

        // Persistir parámetros de conexión para el servidor Emby
        ServerSetting::set('emby_server_url', $serverUrl, 'emby', 'string');
        if ($apiKey !== null && $apiKey !== '') {
            ServerSetting::set('emby_api_key', $apiKey, 'emby', 'string');
        }

        $syncedSources = [];
        $serverName = 'server01-PowerEdge-R720';

        try {
            $headers = [
                'Accept' => 'application/json',
                'X-Emby-Authorization' => 'MediaBrowser Client="FlixHN", Device="server01-PowerEdge-R720", DeviceId="flixhn-core", Version="1.0.0"',
            ];
            if (!empty($apiKey)) {
                $headers['X-Emby-Token'] = $apiKey;
            }

            // 1. Probar conectividad con System/Info/Public (timeout estricto de 5s / connect 3s)
            $publicUrl = "{$serverUrl}/emby/System/Info/Public";
            $publicResp = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->get($publicUrl);

            if ($publicResp->successful()) {
                $info = $publicResp->json();
                if (!empty($info['ServerName'])) {
                    $serverName = $info['ServerName'];
                    ServerSetting::set('emby_server_name', $serverName, 'emby', 'string');
                }
            }

            // 2. Consultar colecciones y bibliotecas de Emby
            // Endpoints: /emby/Library/MediaFolders, /emby/Library/VirtualFolders o /emby/Users/{userId}/Views
            $foldersUrl = "{$serverUrl}/emby/Library/MediaFolders";
            $queryParams = [];
            if (!empty($apiKey)) {
                $queryParams['api_key'] = $apiKey;
            }

            $foldersResp = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->get($foldersUrl, $queryParams);

            $libraries = [];
            if ($foldersResp->successful() && isset($foldersResp['Items']) && is_array($foldersResp['Items'])) {
                $libraries = $foldersResp['Items'];
            } else {
                // Fallback a /emby/Library/VirtualFolders
                $vResp = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->get("{$serverUrl}/emby/Library/VirtualFolders", $queryParams);
                if ($vResp->successful() && is_array($vResp->json())) {
                    $libraries = $vResp->json();
                }
            }

            // Si las carpetas requieren token o no se devolvieron por MediaFolders
            if (empty($libraries)) {
                if ($foldersResp->status() === 401 || (isset($vResp) && $vResp->status() === 401)) {
                    Log::warning("Emby en {$serverUrl} requiere token o API Key autorizada.");
                    return [
                        'status' => 'unauthorized',
                        'server_name' => $serverName,
                        'server_url' => $serverUrl,
                        'message' => "Servidor Emby {$serverName} conectado en {$serverUrl}. Se requiere configurar una API Key de Emby para sincronizar las bibliotecas protegidas.",
                        'synced_sources' => [],
                    ];
                }

                // Intentar recuperar ítems de nivel raíz
                $itemsResp = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->get("{$serverUrl}/emby/Items", array_merge($queryParams, [
                    'Recursive' => 'true',
                    'IncludeItemTypes' => 'Movie,Series',
                    'Fields' => 'Overview,ProductionYear,PremiereDate,Path,MediaSources,Genres,RunTimeTicks',
                ]));

                if ($itemsResp->successful() && isset($itemsResp['Items']) && is_array($itemsResp['Items'])) {
                    foreach ($itemsResp['Items'] as $item) {
                        $synced = $this->storeEmbyItem($item, null, $serverUrl, $apiKey);
                        if ($synced) $syncedSources[] = $synced;
                    }
                }
            } else {
                // Iterar sobre cada biblioteca detectada en Emby
                foreach ($libraries as $index => $lib) {
                    $libName = $lib['Name'] ?? 'General';
                    $folderId = $lib['Id'] ?? ($lib['ItemId'] ?? null);
                    $collectionType = strtolower($lib['CollectionType'] ?? '');

                    $type = 'mixed';
                    if ($collectionType === 'movies' || str_contains(strtolower($libName), 'película') || str_contains(strtolower($libName), 'pelicula')) {
                        $type = 'movie';
                    } elseif ($collectionType === 'tvshows' || str_contains(strtolower($libName), 'serie')) {
                        $type = 'series';
                    }

                    // Registrar o actualizar categoría en la base de datos local MySQL
                    $category = Category::updateOrCreate(
                        ['slug' => Str::slug($libName)],
                        [
                            'name' => $libName,
                            'type' => $type,
                            'folder_path' => Str::slug($libName),
                            'description' => "Biblioteca {$libName} sincronizada desde el servidor Emby {$serverName}.",
                            'sort_order' => $index + 1,
                            'is_active' => true,
                        ]
                    );

                    // Consultar títulos pertenecientes a esta biblioteca
                    $itemQuery = [
                        'Recursive' => 'true',
                        'IncludeItemTypes' => 'Movie,Series',
                        'Fields' => 'Overview,ProductionYear,PremiereDate,Path,MediaSources,Genres,RunTimeTicks',
                    ];
                    if ($folderId) {
                        $itemQuery['ParentId'] = $folderId;
                    }
                    if (!empty($apiKey)) {
                        $itemQuery['api_key'] = $apiKey;
                    }

                    $itemsResp = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->get("{$serverUrl}/emby/Items", $itemQuery);
                    if ($itemsResp->successful() && isset($itemsResp['Items']) && is_array($itemsResp['Items'])) {
                        foreach ($itemsResp['Items'] as $item) {
                            $synced = $this->storeEmbyItem($item, $category, $serverUrl, $apiKey);
                            if ($synced) $syncedSources[] = $synced;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error sincronizando servidor Emby en {$serverUrl}: " . $e->getMessage());
            return [
                'status' => 'error',
                'server_name' => $serverName,
                'server_url' => $serverUrl,
                'message' => "No se pudo sincronizar con el servidor Emby ({$serverUrl}): " . $e->getMessage(),
                'synced_sources' => [],
            ];
        }

        return [
            'status' => 'success',
            'server_name' => $serverName,
            'server_url' => $serverUrl,
            'message' => "Sincronización con Emby completada. Total títulos procesados: " . count($syncedSources),
            'synced_sources' => $syncedSources,
        ];
    }

    /**
     * Almacenar un ítem de Emby en la tabla titles de MySQL.
     */
    protected function storeEmbyItem(array $item, ?Category $category, string $serverUrl, ?string $apiKey): ?string
    {
        $id = $item['Id'] ?? null;
        if (!$id) return null;

        $name = trim($item['Name'] ?? 'Sin Título');
        $type = ($item['Type'] ?? '') === 'Series' ? 'series' : 'movie';
        $sourcePath = "emby://{$serverUrl}/{$id}";

        // Generar URLs de imágenes
        $keyParam = !empty($apiKey) ? "?api_key={$apiKey}" : '';
        $posterUrl = "{$serverUrl}/emby/Items/{$id}/Images/Primary{$keyParam}";
        $backdropUrl = "{$serverUrl}/emby/Items/{$id}/Images/Backdrop{$keyParam}";

        // Generar URL de streaming directa para el reproductor DirectPlay con static=true
        $mediaSourceId = $item['MediaSources'][0]['Id'] ?? null;
        $streamParams = ['static' => 'true'];
        if ($mediaSourceId) {
            $streamParams['MediaSourceId'] = $mediaSourceId;
        }
        if (!empty($apiKey)) {
            $streamParams['api_key'] = $apiKey;
        }
        $streamPath = "{$serverUrl}/emby/Videos/{$id}/stream.mp4?" . http_build_query($streamParams);

        $duration = isset($item['RunTimeTicks']) ? (int) ($item['RunTimeTicks'] / 10000000) : 5400;
        $genre = $item['Genres'][0] ?? ($category?->name ?: ($type === 'movie' ? 'Película' : 'Serie'));

        // Si no se proporcionó categoría, buscar o crear una por género
        if (!$category) {
            $category = Category::firstOrCreate(
                ['slug' => Str::slug($genre)],
                [
                    'name' => $genre,
                    'type' => $type,
                    'folder_path' => Str::slug($genre),
                    'sort_order' => 10,
                    'is_active' => true,
                ]
            );
        }

        // Extracción estricta del año en orden de precedencia:
        // 1. ProductionYear
        // 2. Si no existe, parsear el año de PremiereDate
        // 3. Si no existe, buscar el año entre paréntesis en el nombre/archivo (regex: /\((\d{4})\)/)
        // 4. NUNCA asignar date('Y') o el año en curso como fallback para evitar fechas falsas.
        $year = null;
        if (!empty($item['ProductionYear']) && (int) $item['ProductionYear'] > 1800 && (int) $item['ProductionYear'] <= ((int) date('Y') + 2)) {
            $year = (int) $item['ProductionYear'];
        } elseif (!empty($item['PremiereDate'])) {
            $parsedTime = strtotime($item['PremiereDate']);
            if ($parsedTime !== false) {
                $parsedYear = (int) date('Y', $parsedTime);
                if ($parsedYear > 1800 && $parsedYear <= ((int) date('Y') + 2)) {
                    $year = $parsedYear;
                }
            }
        }
        if (!$year) {
            $searchStrings = [$item['Path'] ?? '', $item['FileName'] ?? '', $name];
            if (!empty($item['MediaSources'][0]['Path'])) {
                $searchStrings[] = $item['MediaSources'][0]['Path'];
            }
            foreach ($searchStrings as $str) {
                if (!empty($str) && preg_match('/\((19\d{2}|20\d{2})\)/', $str, $matches)) {
                    $year = (int) $matches[1];
                    break;
                }
            }
        }
        $dbYear = $year ?: 0;

        Title::updateOrCreate(
            ['source_path' => $sourcePath],
            [
                'name' => $name,
                'slug' => Str::slug($name) . '-' . substr(md5($sourcePath), 0, 6),
                'type' => $type,
                'category_id' => $category->id,
                'description' => $item['Overview'] ?? "Contenido disponible en el servidor Emby de la red.",
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_year' => $dbYear,
                'genre' => $genre,
                'stream_path' => $streamPath,
                'duration_seconds' => $duration,
                'is_featured' => false,
            ]
        );

        return $sourcePath;
    }

    /**
     * Limpiar títulos que ya no existen físicamente o eran datos simulados previos.
     */
    protected function cleanupStaleTitles(array $validSourcePaths): int
    {
        // 1. Eliminar títulos viejos inventados (que no tienen source_path o no están en los archivos reales detectados)
        $query = Title::query();

        if (empty($validSourcePaths)) {
            // Si no hay archivos reales en storage, eliminar todos los inventados
            $count = $query->count();
            // Eliminar temporadas y episodios huérfanos
            Episode::query()->delete();
            Season::query()->delete();
            $query->delete();
            return $count;
        }

        $staleTitles = Title::whereNotIn('source_path', $validSourcePaths)
            ->orWhereNull('source_path')
            ->get();

        $count = $staleTitles->count();
        foreach ($staleTitles as $t) {
            $t->seasons()->delete();
            $t->episodes()->delete();
            $t->playbackProgresses()->delete();
            $t->delete();
        }

        return $count;
    }

    /**
     * Extraer año del título si está en el formato "Nombre (2024)".
     */
    protected function parseTitleAndYear(string $rawName): array
    {
        $year = null;
        $name = str_replace(['_', '-'], ' ', $rawName);

        if (preg_match('/\((\d{4})\)/', $name, $matches)) {
            $year = (int) $matches[1];
            $name = trim(str_replace($matches[0], '', $name));
        }

        // Limpieza de caracteres y formato
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $name = ucwords(strtolower($name));

        return [
            'name' => $name ?: 'Título Sin Nombre',
            'year' => $year,
        ];
    }

    /**
     * Sondear duración con ffmpeg/ffprobe si está disponible.
     */
    protected function probeDuration(?string $filePath): ?int
    {
        if (!$filePath || !file_exists($filePath)) {
            return 5400; // 90 min por defecto
        }

        // Si es m3u8, estimar 5400s
        if (str_ends_with($filePath, '.m3u8')) {
            return 5400;
        }

        if (function_exists('exec') && file_exists('/usr/bin/ffmpeg')) {
            $cmd = "ffmpeg -nostdin -i " . escapeshellarg($filePath) . " 2>&1 | grep 'Duration'";
            $output = @shell_exec($cmd);
            if ($output && preg_match('/Duration: (\d{2}):(\d{2}):(\d{2})/', $output, $m)) {
                return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
            }
        }

        return 5400;
    }
}
