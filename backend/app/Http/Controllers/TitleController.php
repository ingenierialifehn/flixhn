<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Models\PlaybackProgress;
use App\Models\MediaIngestTask;
use App\Services\RemoteMediaConnectorService;
use App\Services\MediaIntegrityService;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TitleController extends Controller
{
    /**
     * Obtener el catálogo dinámico estructurado por categorías reales del servidor para la vista (/browse).
     * Optimizado para alta concurrencia ISP con almacenamiento en caché (Cache::remember).
     */
    public function index(Request $request): JsonResponse
    {
        $profileId = $request->query('profile_id') ?? $request->header('X-Profile-Id');
        if (!$profileId && $request->user()) {
            $token = $request->user()->currentAccessToken();
            $profileId = ($token ? Cache::get("token_{$token->id}_current_profile") : null)
                ?? Cache::get("user_{$request->user()->id}_current_profile");
        }

        $searchQuery = trim($request->query('q') ?? $request->query('search') ?? '');

        // Búsqueda reactiva en tiempo real por término (rápida, ligera e integrada con OTT)
        if ($searchQuery !== '') {
            // 1. Obtener títulos coincidentes de la base de datos local con verificación estricta de reproducción física y no pirata
            $localCandidates = Title::select([
                'id', 'name', 'slug', 'type', 'provider', 'description',
                'poster_url', 'backdrop_url', 'release_year', 'genre',
                'duration_seconds', 'stream_path', 'file_path', 'source_type', 'category_id', 'created_at'
            ])
            ->where(function ($w) use ($searchQuery) {
                $w->where('name', 'LIKE', "%{$searchQuery}%")
                  ->orWhere('description', 'LIKE', "%{$searchQuery}%")
                  ->orWhere('genre', 'LIKE', "%{$searchQuery}%");
            })
            ->orderBy('created_at', 'desc')
            ->take(60)
            ->get();

            // 2. Si la búsqueda tiene 2 o más caracteres, buscar en las plataformas OTT conectadas
            $ottResults = [];
            $mergedLocalIds = [];

            if (mb_strlen($searchQuery) >= 2) {
                try {
                    $cacheKey = 'ott_search_agg_' . md5(mb_strtolower($searchQuery));
                    $ottRaw = null;
                    try {
                        $ottRaw = Cache::get($cacheKey);
                    } catch (\Throwable $e) {}

                    if (!$ottRaw) {
                        $ottRaw = OttSyncService::searchReal($searchQuery);
                        try {
                            Cache::put($cacheKey, $ottRaw, 300);
                        } catch (\Throwable $e) {}
                    }

                    foreach ($ottRaw as $item) {
                        $itemTitle = trim($item['title'] ?? '');
                        if (empty($itemTitle)) continue;

                        $normHelper = function(string $s): string {
                            $s = mb_strtolower($s);
                            $map = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u'];
                            return strtr($s, $map);
                        };

                        $itemTitleNorm = $normHelper($itemTitle);
                        $providerKey = strtolower($item['provider'] ?? 'netflix');
                        $mediaType = ($item['type'] ?? 'movie') === 'series' ? 'series' : 'movie';
                        $uniqueOttId = 'ott_' . md5($providerKey . '_' . $itemTitle);

                        // Buscar si existe un título local auténtico correspondiente con coincidencia exacta (no substring general)
                        $cleanAlpha = function(string $s): string {
                            return preg_replace('/[^a-z0-9]/', '', $s);
                        };
                        $itemAlpha = $cleanAlpha($itemTitleNorm);

                        $matchedLocal = $localCandidates->first(function ($t) use ($itemTitleNorm, $normHelper, $cleanAlpha, $itemAlpha) {
                            $tNorm = $normHelper(trim($t->name ?? ''));
                            if ($tNorm === $itemTitleNorm) return true;
                            $tAlpha = $cleanAlpha($tNorm);
                            return !empty($tAlpha) && !empty($itemAlpha) && $tAlpha === $itemAlpha;
                        });

                        $hasAuthenticMedia = false;
                        $targetLocalTitle = $matchedLocal;

                        if (!$targetLocalTitle) {
                            $targetLocalTitle = Title::where('name', $itemTitle)
                                ->orWhere('name', 'LIKE', $itemTitle)
                                ->first();
                        }

                        if ($targetLocalTitle) {
                            if ($targetLocalTitle->type === 'series') {
                                $ep = $targetLocalTitle->episodes()->first();
                                if ($ep && !empty($ep->file_path)) {
                                    $resEp = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($ep->file_path);
                                    if ($resEp && file_exists($resEp) && !\App\Jobs\ProcessMediaIngestJob::isBannedOrPiratedSource($resEp) && \App\Jobs\ProcessMediaIngestJob::isVideoFilePlayable($resEp)) {
                                        $hasAuthenticMedia = true;
                                    }
                                }
                            }
                            if (!$hasAuthenticMedia && !empty($targetLocalTitle->file_path)) {
                                $resFile = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($targetLocalTitle->file_path);
                                if ($resFile && file_exists($resFile) && !\App\Jobs\ProcessMediaIngestJob::isBannedOrPiratedSource($resFile) && \App\Jobs\ProcessMediaIngestJob::isVideoFilePlayable($resFile)) {
                                    $hasAuthenticMedia = true;
                                }
                            }
                        }

                        if ($hasAuthenticMedia && $targetLocalTitle) {
                            $mergedLocalIds[] = $targetLocalTitle->id;
                            $formattedOtt = [
                                'id' => $targetLocalTitle->id,
                                'name' => $itemTitle,
                                'title' => $itemTitle,
                                'slug' => $targetLocalTitle->slug ?: Str::slug($itemTitle),
                                'type' => $targetLocalTitle->type ?: $mediaType,
                                'media_type' => $targetLocalTitle->type ?: $mediaType,
                                'provider' => $providerKey,
                                'source_platform' => $providerKey,
                                'description' => $targetLocalTitle->description ?: ($item['overview'] ?? "Disponible para transmisión On-Net desde " . ucfirst($providerKey)),
                                'overview' => $targetLocalTitle->description ?: ($item['overview'] ?? "Disponible para transmisión On-Net desde " . ucfirst($providerKey)),
                                'poster_url' => $item['poster_url'] ?: $targetLocalTitle->poster_url,
                                'backdrop_url' => $item['backdrop_url'] ?: ($item['poster_url'] ?: $targetLocalTitle->backdrop_url),
                                'release_year' => $targetLocalTitle->release_year ?: ($item['year'] ?? date('Y')),
                                'year' => $targetLocalTitle->release_year ?: ($item['year'] ?? date('Y')),
                                'genre' => $targetLocalTitle->genre ?: ($item['genres'] ?? ($mediaType === 'series' ? 'Serie Original' : 'Largometraje')),
                                'duration_seconds' => $targetLocalTitle->duration_seconds ?: ($item['duration_seconds'] ?? 7200),
                                'stream_path' => "/api/stream/title/{$targetLocalTitle->id}",
                                'file_path' => $targetLocalTitle->file_path,
                                'is_ott' => false,
                                'is_downloaded' => true,
                                'is_already_downloaded' => true,
                                'is_available_locally' => true,
                                'status' => 'En Catálogo',
                                'created_at' => $targetLocalTitle->created_at?->toIso8601String() ?: now()->toIso8601String(),
                                'metadata' => $item,
                            ];
                        } else {
                            $formattedOtt = [
                                'id' => $uniqueOttId,
                                'name' => $itemTitle,
                                'title' => $itemTitle,
                                'slug' => Str::slug($itemTitle) ?: $uniqueOttId,
                                'type' => $mediaType,
                                'media_type' => $mediaType,
                                'provider' => $providerKey,
                                'source_platform' => $providerKey,
                                'description' => $item['overview'] ?? ($item['description'] ?? "Disponible para transmisión On-Net desde " . ucfirst($providerKey)),
                                'overview' => $item['overview'] ?? ($item['description'] ?? "Disponible para transmisión On-Net desde " . ucfirst($providerKey)),
                                'poster_url' => $item['poster_url'] ?? null,
                                'backdrop_url' => $item['backdrop_url'] ?? ($item['poster_url'] ?? null),
                                'release_year' => $item['year'] ?? ($item['release_year'] ?? date('Y')),
                                'year' => $item['year'] ?? ($item['release_year'] ?? date('Y')),
                                'genre' => is_array($item['genres'] ?? null) ? implode(', ', $item['genres']) : ($item['genres'] ?? ($item['genre'] ?? ($mediaType === 'series' ? 'Serie Original' : 'Largometraje'))),
                                'duration_seconds' => $item['duration_seconds'] ?? 7200,
                                'is_ott' => true,
                                'is_downloaded' => false,
                                'is_already_downloaded' => false,
                                'is_available_locally' => false,
                                'status' => 'Disponible OTT',
                                'created_at' => now()->toIso8601String(),
                                'metadata' => $item,
                            ];

                            try {
                                Cache::put('ott_item_' . $uniqueOttId, $formattedOtt, 3600);
                            } catch (\Throwable $e) {}
                        }

                        $ottResults[] = $formattedOtt;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Error integrando búsqueda OTT en TitleController@index: " . $e->getMessage());
                }
            }

            // 3. Filtrar los títulos locales: SOLO conservar aquellos que tienen archivo físico reproducible y no pirata, y que no fueron ya fusionados en ottResults
            $validLocal = $localCandidates->filter(function ($t) use ($mergedLocalIds) {
                if (in_array($t->id, $mergedLocalIds, true)) {
                    return false;
                }
                $hasValidMedia = false;
                if ($t->type === 'series') {
                    $firstEp = $t->episodes()->first();
                    if ($firstEp && !empty($firstEp->file_path)) {
                        $resEp = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($firstEp->file_path);
                        if ($resEp && file_exists($resEp) && !\App\Jobs\ProcessMediaIngestJob::isBannedOrPiratedSource($resEp) && \App\Jobs\ProcessMediaIngestJob::isVideoFilePlayable($resEp)) {
                            $hasValidMedia = true;
                        }
                    }
                }
                if (!$hasValidMedia && !empty($t->file_path)) {
                    $res = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($t->file_path);
                    if ($res && file_exists($res) && !\App\Jobs\ProcessMediaIngestJob::isBannedOrPiratedSource($res) && \App\Jobs\ProcessMediaIngestJob::isVideoFilePlayable($res)) {
                        $hasValidMedia = true;
                    }
                }
                return $hasValidMedia;
            })->map(function ($item) {
                $item->title = $item->name;
                $item->year = $item->release_year;
                $item->overview = $item->description;
                $item->media_type = $item->type;
                $item->is_already_downloaded = true;
                $item->is_available_locally = true;
                $item->is_ott = false;
                $item->is_downloaded = true;
                $item->status = 'En Catálogo';
                return $item;
            })->values();

            // Combinar: Resultados de plataformas conectadas primero (con auténticos ya marcados como En Catálogo), seguidos por títulos locales verificados adicionales
            $allResults = collect($ottResults)->concat($validLocal)->values();

            return response()->json([
                'status' => 'success',
                'data' => $allResults,
                'results' => $allResults,
                'total_titles' => $allResults->count(),
                'local_count' => $validLocal->count(),
                'ott_count' => count($ottResults),
            ]);
        }

        // Cachear las respuestas de /api/titles y /api/home (1 hora) usando caché optimizado
        $baseData = Cache::remember('catalog_home_base', 3600, function () {
            // Cargar títulos ligeros sin relaciones anidadas pesadas para el catálogo principal
            $allTitles = Title::select([
                'id', 'name', 'slug', 'type', 'provider', 'description',
                'poster_url', 'backdrop_url', 'release_year', 'genre',
                'is_featured', 'stream_path', 'file_path', 'source_type', 'duration_seconds', 'category_id', 'created_at'
            ])
            ->withCount('seasons')
            ->orderBy('created_at', 'desc')
            ->get();

            if ($allTitles->isEmpty()) {
                return [
                    'billboard' => null,
                    'rows' => [],
                    'total_titles' => 0,
                    'categories' => [],
                ];
            }

            // Billboard Principal: título destacado o el más reciente
            $billboardTitle = $allTitles->firstWhere('is_featured', true) ?? $allTitles->first();

            $rows = [];

            // 1. Títulos Destacados FlixHN
            $featured = $allTitles->where('is_featured', true)->values();
            if ($featured->count() > 1) {
                $rows[] = [
                    'id' => 'featured_row',
                    'title' => 'Aclamados y Destacados FlixHN',
                    'items' => $featured->take(30),
                ];
            }

            // 2. Fila Películas
            $movies = $allTitles->where('type', 'movie')->values();
            if ($movies->isNotEmpty()) {
                $rows[] = [
                    'id' => 'movies_row',
                    'title' => 'Películas',
                    'items' => $movies->take(30),
                ];
            }

            // 3. Fila Series
            $series = $allTitles->where('type', 'series')->values();
            if ($series->isNotEmpty()) {
                $rows[] = [
                    'id' => 'series_row',
                    'title' => 'Series',
                    'items' => $series->take(30),
                ];
            }

            // 4. Diccionario de internacionalización en español para categorías sincronizadas
            $categoryTranslations = [
                'action' => 'Acción',
                'accion' => 'Acción',
                'action & adventure' => 'Acción y Aventura',
                'adventure' => 'Aventura',
                'aventura' => 'Aventura',
                'animation' => 'Animación',
                'animacion' => 'Animación',
                'animadas' => 'Películas Animadas',
                'animate' => 'Animación',
                'anime' => 'Anime',
                'comedy' => 'Comedia',
                'comedia' => 'Comedia',
                'crime' => 'Crimen',
                'crimen' => 'Crimen',
                'documentary' => 'Documentales',
                'documental' => 'Documentales',
                'documentales' => 'Documentales',
                'documéntales' => 'Documentales',
                'drama' => 'Drama',
                'family' => 'Infantiles y Familia',
                'familia' => 'Infantiles y Familia',
                'children' => 'Infantiles',
                'infantiles' => 'Infantiles',
                'fantasy' => 'Fantasía',
                'fantasia' => 'Fantasía',
                'horror' => 'Terror',
                'terror' => 'Terror',
                'mystery' => 'Misterio y Suspenso',
                'misterio' => 'Misterio',
                'misterio suspenso' => 'Misterio y Suspenso',
                'romance' => 'Romance',
                'romanticas' => 'Romance',
                'sci-fi & fantasy' => 'Ciencia Ficción y Fantasía',
                'science fiction' => 'Ciencia Ficción',
                'ciencia ficcion' => 'Ciencia Ficción',
                'ciencia ficción' => 'Ciencia Ficción',
                'ficcion' => 'Ciencia Ficción',
                'ficción' => 'Ciencia Ficción',
                'thriller' => 'Suspenso',
                'suspense' => 'Suspenso',
                'war' => 'Bélicas e Históricas',
                'belica' => 'Bélica',
                'bélica' => 'Bélica',
                'blico' => 'Bélica',
                'historia' => 'Historia y Documental',
                'western' => 'Western',
                'music' => 'Música y Conciertos',
                'musica' => 'Música y Conciertos',
                'música' => 'Música y Conciertos',
                'musical' => 'Musicales',
                'biography' => 'Biográficas',
                'food' => 'Gastronomía y Cocina',
                'estrenos' => 'Estrenos',
                'cristianas' => 'Contenido Familiar y Fe',
                'colecciones' => 'Colecciones',
                'collections' => 'Colecciones',
            ];

            // Agrupar títulos por categorías reales normalizadas en español
            $categories = Category::all();
            $groupedByCleanCategory = [];

            foreach ($categories as $cat) {
                $rawSlug = strtolower(trim($cat->slug));
                $rawName = strtolower(trim($cat->name));
                $cleanName = $categoryTranslations[$rawSlug] ?? $categoryTranslations[$rawName] ?? $cat->name;

                $catTitles = $allTitles->where('category_id', $cat->id)->values();
                if ($catTitles->isNotEmpty()) {
                    if (!isset($groupedByCleanCategory[$cleanName])) {
                        $groupedByCleanCategory[$cleanName] = collect();
                    }
                    $groupedByCleanCategory[$cleanName] = $groupedByCleanCategory[$cleanName]->concat($catTitles);
                }
            }

            // También complementar con géneros si existen títulos
            $genres = $allTitles->pluck('genre')->unique()->filter()->values();
            foreach ($genres as $genre) {
                $gLower = strtolower(trim($genre));
                $cleanName = $categoryTranslations[$gLower] ?? ucfirst($genre);
                $genreTitles = $allTitles->where('genre', $genre)->values();
                if ($genreTitles->isNotEmpty()) {
                    if (!isset($groupedByCleanCategory[$cleanName])) {
                        $groupedByCleanCategory[$cleanName] = collect();
                    }
                    $groupedByCleanCategory[$cleanName] = $groupedByCleanCategory[$cleanName]->concat($genreTitles);
                }
            }

            // Orden cinematográfico prioritario
            $priorityOrder = [
                'Estrenos',
                'Acción',
                'Acción y Aventura',
                'Comedia',
                'Drama',
                'Ciencia Ficción',
                'Terror',
                'Suspenso',
                'Misterio y Suspenso',
                'Animación',
                'Películas Animadas',
                'Infantiles y Familia',
                'Infantiles',
                'Aventura',
                'Crimen',
                'Documentales',
                'Fantasía',
                'Romance',
                'Biográficas',
                'Bélica',
                'Western',
                'Musicales',
            ];

            $renderedNames = ['películas', 'series', 'aclamados y destacados flixhn'];

            // 1. Agregar según orden prioritario
            foreach ($priorityOrder as $priorityName) {
                $pLower = strtolower($priorityName);
                if (isset($groupedByCleanCategory[$priorityName]) && !in_array($pLower, $renderedNames)) {
                    $uniqueTitles = $groupedByCleanCategory[$priorityName]->unique('id')->values();
                    if ($uniqueTitles->isNotEmpty()) {
                        $rows[] = [
                            'id' => 'cat_' . Str::slug($priorityName),
                            'title' => $priorityName,
                            'items' => $uniqueTitles->take(30),
                        ];
                        $renderedNames[] = $pLower;
                    }
                }
            }

            // 2. Agregar cualquier otra categoría restante con títulos
            foreach ($groupedByCleanCategory as $catName => $col) {
                $cLower = strtolower($catName);
                if (!in_array($cLower, $renderedNames)) {
                    $uniqueTitles = $col->unique('id')->values();
                    if ($uniqueTitles->isNotEmpty()) {
                        $rows[] = [
                            'id' => 'cat_' . Str::slug($catName),
                            'title' => $catName,
                            'items' => $uniqueTitles->take(30),
                        ];
                        $renderedNames[] = $cLower;
                    }
                }
            }

            return [
                'billboard' => $billboardTitle,
                'rows' => $rows,
                'total_titles' => $allTitles->count(),
                'categories' => $categories,
            ];
        });

        $finalRows = $baseData['rows'];

        // Si el perfil activo es Kids, restringir el catálogo exclusivamente a contenidos familiares/infantiles
        if ($profileId) {
            $activeProfile = \App\Models\UserProfile::find($profileId);
            if ($activeProfile && $activeProfile->is_kids) {
                $adultKeywords = ['terror', 'horror', 'crimen', 'erot', 'violenc', 'gore', 'matanza', 'narco'];
                $filteredRows = [];

                foreach ($finalRows as $row) {
                    $rowTitle = strtolower($row['title'] ?? '');
                    if (str_contains($rowTitle, 'terror') || str_contains($rowTitle, 'crimen') || str_contains($rowTitle, 'suspenso')) {
                        continue;
                    }

                    $safeItems = collect($row['items'] ?? [])->filter(function ($item) use ($adultKeywords) {
                        $it = $item->title ?? $item;
                        $genre = strtolower($it->genre ?? '');
                        foreach ($adultKeywords as $bad) {
                            if (str_contains($genre, $bad)) return false;
                        }
                        return true;
                    })->values();

                    if ($safeItems->isNotEmpty()) {
                        $row['items'] = $safeItems;
                        $filteredRows[] = $row;
                    }
                }
                $finalRows = $filteredRows;
            }
        }

        // 3. Fila "Continuar Viendo"
        // REGLA: El título SOLO debe agregarse si el usuario reprodujo al menos 10 minutos (>= 600s)
        // y aún no ha llegado al 95% de la duración total.
        if ($profileId) {
            $rawProgress = PlaybackProgress::with(['title', 'episode'])
                ->where('profile_id', $profileId)
                ->where('current_seconds', '>=', 600)
                ->whereRaw('current_seconds < (duration_seconds * 0.95)')
                ->orderBy('last_watched_at', 'desc')
                ->take(30)
                ->get();

            // Desduplicar por title_id para que cada título aparezca solo una vez con su progreso más reciente
            $continueWatching = $rawProgress->filter(function ($p) {
                return !empty($p->title);
            })->unique(function ($p) {
                return $p->title_id ?: ('item_' . $p->id);
            })->values()->take(15);

            if ($continueWatching->isNotEmpty()) {
                array_unshift($finalRows, [
                    'id' => 'continue_watching',
                    'title' => 'Continuar Viendo',
                    'is_progress_row' => true,
                    'items' => $continueWatching,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [], // Retornamos rows estructuradas para máxima ligereza y evitar duplicar 3500 objetos
            'categories' => $baseData['categories'],
            'billboard' => $baseData['billboard'],
            'rows' => $finalRows,
            'total_titles' => $baseData['total_titles'],
            'has_active_downloads' => MediaIngestTask::whereIn('status', ['pending', 'downloading', 'processing'])->exists(),
        ]);
    }

    /**
     * Comprobar de forma ultraligera si existen descargas activas en proceso.
     */
    public function activeDownloads(): JsonResponse
    {
        $hasActive = MediaIngestTask::whereIn('status', ['pending', 'downloading', 'processing'])->exists();
        return response()->json([
            'status' => 'success',
            'has_active_downloads' => $hasActive,
        ]);
    }

    /**
     * Obtener detalle completo de un título con sus temporadas, episodios y progreso.
     * Organiza jerárquicamente las series estilo Netflix (ORDER BY season_number ASC, episode_number ASC).
     * Detecta y extrae elementos que comiencen con "0" como Trailer / Previa, excluyéndolos de episodios regulares.
     */
    public function show(Request $request, $id, MediaIntegrityService $integrityService): JsonResponse
    {
        $profileId = $request->query('profile_id') ?? $request->header('X-Profile-Id');
        if (!$profileId && $request->user()) {
            $token = $request->user()->currentAccessToken();
            $profileId = ($token ? Cache::get("token_{$token->id}_current_profile") : null)
                ?? Cache::get("user_{$request->user()->id}_current_profile");
        }

        // Si es un contenido OTT que aún no ha sido descargado al servidor local
        if (str_starts_with((string) $id, 'ott_')) {
            $cachedOtt = Cache::get('ott_item_' . $id);
            if ($cachedOtt) {
                return response()->json([
                    'status' => 'success',
                    'title' => (object) $cachedOtt,
                    'progress' => null,
                ]);
            }

            // Buscar si ya existe un trabajo de descarga para este contenido
            $job = \App\Models\DownloadJob::where('content_id', $id)->latest()->first();
            if ($job) {
                return response()->json([
                    'status' => 'success',
                    'title' => (object) [
                        'id' => $id,
                        'name' => $job->title,
                        'title' => $job->title,
                        'type' => $job->media_type ?: 'movie',
                        'provider' => $job->provider ?: 'netflix',
                        'poster_url' => $job->poster_url,
                        'backdrop_url' => $job->backdrop_url,
                        'stream_path' => $job->hls_playlist_url ?: "/api/streaming/hls/{$job->job_id}/playlist.m3u8",
                    ],
                    'progress' => null,
                ]);
            }

            // Fallback sintético para cualquier ID ott_ sin responder 410 Gone
            return response()->json([
                'status' => 'success',
                'title' => (object) [
                    'id' => $id,
                    'name' => ucwords(str_replace(['ott_', '_', '-'], ' ', $id)),
                    'title' => ucwords(str_replace(['ott_', '_', '-'], ' ', $id)),
                    'type' => 'movie',
                    'provider' => 'netflix',
                    'is_ott' => true,
                ],
                'progress' => null,
            ]);
        }

        $title = Title::with(['category'])->find($id);

        if (!$title) {
            return $integrityService->goneResponse(is_numeric($id) ? (int)$id : null, 'media');
        }

        // Auto-verificación de integridad de archivo físico en disco
        if (!$integrityService->verifyOrPurgeTitle($title)) {
            return $integrityService->goneResponse($title->id, $title->type ?? 'media');
        }

        $trailer = null;

        if ($title->type === 'series') {
            // Si la serie no tiene temporadas/episodios locales y proviene de Emby, sincronizarlos
            $existingCount = Season::where('title_id', $title->id)->where('season_number', '>', 0)->count();
            if ($existingCount === 0 && !empty($title->source_path)) {
                try {
                    app(RemoteMediaConnectorService::class)->syncSeriesEpisodes($title);
                } catch (\Throwable $e) {
                    // Continuar si falla la conexión remota
                }
            }

            // Carga jerárquica estricta de temporadas y episodios regulares (excluyendo temporada 0 y episodios 0)
            $title->load([
                'seasons' => function ($q) {
                    $q->where('season_number', '>', 0)->orderBy('season_number', 'asc');
                },
                'seasons.episodes' => function ($q) {
                    $q->where('episode_number', '>', 0)
                      ->where('title', 'NOT REGEXP', '^(trailer|tráiler|preview|teaser|especial|cap[ií]tulo 0|episodio 0)')
                      ->orderBy('episode_number', 'asc');
                }
            ]);

            // Detección de trailers o previas con "0" (Punto 4)
            $trailerEpisode = Episode::where('title_id', $title->id)
                ->where(function ($q) {
                    $q->where('episode_number', 0)
                      ->orWhere('title', 'REGEXP', '^(trailer|tráiler|preview|teaser|especial|cap[ií]tulo 0|episodio 0)');
                })
                ->first();

            if (!$trailerEpisode) {
                $seasonZero = Season::where('title_id', $title->id)->where('season_number', 0)->first();
                if ($seasonZero) {
                    $trailerEpisode = Episode::where('season_id', $seasonZero->id)->first();
                }
            }

            if ($trailerEpisode) {
                $trailer = [
                    'id' => $trailerEpisode->id,
                    'title' => $trailerEpisode->title,
                    'stream_url' => $trailerEpisode->stream_url ?: $trailerEpisode->stream_path,
                    'thumbnail_url' => $trailerEpisode->thumbnail_url ?: $title->backdrop_url,
                ];
                $title->setAttribute('trailer', $trailer);
                $title->setAttribute('trailer_url', $trailer['stream_url']);
            }
        } else {
            // Para películas, verificar si existe un trailer asociado
            $trailerEpisode = Episode::where('title_id', $title->id)
                ->where(function ($q) {
                    $q->where('episode_number', 0)
                      ->orWhere('title', 'REGEXP', '^0+(\\s|$|_|-)|^trailer');
                })
                ->first();

            if ($trailerEpisode) {
                $trailer = [
                    'id' => $trailerEpisode->id,
                    'title' => $trailerEpisode->title,
                    'stream_url' => $trailerEpisode->stream_url ?: $trailerEpisode->stream_path,
                    'thumbnail_url' => $trailerEpisode->thumbnail_url ?: $title->backdrop_url,
                ];
                $title->setAttribute('trailer', $trailer);
                $title->setAttribute('trailer_url', $trailer['stream_url']);
            }
        }

        $progress = null;
        if ($profileId) {
            $progress = PlaybackProgress::where('profile_id', $profileId)
                ->where('title_id', $title->id)
                ->get()
                ->keyBy(fn($p) => $p->episode_id ?: 'main');
        }

        return response()->json([
            'title' => $title,
            'trailer' => $trailer,
            'trailer_url' => $trailer ? $trailer['stream_url'] : null,
            'progress' => $progress,
        ]);
    }
}
