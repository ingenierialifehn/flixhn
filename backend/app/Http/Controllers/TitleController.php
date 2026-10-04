<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Models\PlaybackProgress;
use App\Services\RemoteMediaConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TitleController extends Controller
{
    /**
     * Obtener el catálogo dinámico estructurado por categorías reales del servidor para la vista (/browse).
     * Optimizado para alta concurrencia ISP con almacenamiento en caché (Cache::remember).
     */
    public function index(Request $request): JsonResponse
    {
        $profileId = $request->query('profile_id');
        $searchQuery = trim($request->query('q') ?? $request->query('search') ?? '');

        // Búsqueda reactiva en tiempo real por término (rápida y ligera)
        if ($searchQuery !== '') {
            $matched = Title::select([
                'id', 'name', 'slug', 'type', 'description',
                'poster_url', 'backdrop_url', 'release_year', 'genre',
                'duration_seconds', 'stream_path', 'category_id', 'created_at'
            ])
            ->where(function ($w) use ($searchQuery) {
                $w->where('name', 'LIKE', "%{$searchQuery}%")
                  ->orWhere('description', 'LIKE', "%{$searchQuery}%")
                  ->orWhere('genre', 'LIKE', "%{$searchQuery}%");
            })
            ->orderBy('created_at', 'desc')
            ->take(60)
            ->get();

            return response()->json([
                'status' => 'success',
                'data' => $matched,
                'results' => $matched,
                'total_titles' => $matched->count(),
            ]);
        }

        // Cachear las respuestas de /api/titles y /api/home (1 hora) usando caché optimizado
        $baseData = Cache::remember('catalog_home_base', 3600, function () {
            // Cargar títulos ligeros sin relaciones anidadas pesadas para el catálogo principal
            $allTitles = Title::select([
                'id', 'name', 'slug', 'type', 'description',
                'poster_url', 'backdrop_url', 'release_year', 'genre',
                'is_featured', 'stream_path', 'duration_seconds', 'category_id', 'created_at'
            ])
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

        // 3. Fila "Continuar Viendo"
        // REGLA: El título SOLO debe agregarse si el usuario reprodujo al menos 10 minutos (>= 600s)
        // y aún no ha llegado al 95% de la duración total.
        if ($profileId) {
            $continueWatching = PlaybackProgress::with(['title', 'episode'])
                ->where('profile_id', $profileId)
                ->where('current_seconds', '>=', 600)
                ->whereRaw('current_seconds < (duration_seconds * 0.95)')
                ->orderBy('last_watched_at', 'desc')
                ->take(15)
                ->get();

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
        ]);
    }

    /**
     * Obtener detalle completo de un título con sus temporadas, episodios y progreso.
     * Organiza jerárquicamente las series estilo Netflix (ORDER BY season_number ASC, episode_number ASC).
     * Detecta y extrae elementos que comiencen con "0" como Trailer / Previa, excluyéndolos de episodios regulares.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $profileId = $request->query('profile_id');

        $title = Title::with(['category'])->findOrFail($id);

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
                      ->where('title', 'NOT REGEXP', '^0+(\\s|$|_|-)|^cap[ií]tulo\\s*0|^episodio\\s*0|^trailer')
                      ->orderBy('episode_number', 'asc');
                }
            ]);

            // Detección de trailers o previas con "0" (Punto 4)
            $trailerEpisode = Episode::where('title_id', $title->id)
                ->where(function ($q) {
                    $q->where('episode_number', 0)
                      ->orWhere('title', 'REGEXP', '^0+(\\s|$|_|-)|^cap[ií]tulo\\s*0|^episodio\\s*0|^trailer');
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
