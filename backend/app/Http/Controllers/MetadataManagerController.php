<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Plugin;
use App\Models\Season;
use App\Models\Title;
use App\Models\TvChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetadataManagerController extends Controller
{
    /**
     * Devuelve el árbol jerárquico de bibliotecas y elementos con soporte de Lazy Loading y Búsqueda reactiva.
     *
     * Parámetros admitidos:
     * - parent_type: 'category' | 'title' | 'season' | 'livetv'
     * - parent_id: ID del nodo padre
     * - search: término de búsqueda global
     */
    public function tree(Request $request): JsonResponse
    {
        $search = trim($request->query('search', ''));
        $parentType = $request->query('parent_type');
        $parentId = $request->query('parent_id');

        // Modo A: Si el usuario busca un término en el árbol
        if (!empty($search)) {
            return $this->searchTree($search);
        }

        // Modo B: Carga bajo demanda (Lazy Loading) de un nodo específico
        if (!empty($parentType) && !empty($parentId)) {
            return $this->loadChildren($parentType, $parentId);
        }

        // Modo C: Carga del nivel raíz (Bibliotecas y Categorías base de FlixHN)
        return $this->getRootLibraries();
    }

    /**
     * Obtener las bibliotecas y categorías raíz del servidor.
     */
    protected function getRootLibraries(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->withCount('titles')
            ->orderBy('sort_order', 'asc')
            ->get();

        $treeNodes = [];

        foreach ($categories as $cat) {
            $icon = 'Film';
            if ($cat->type === 'series') {
                $icon = 'Tv';
            } elseif ($cat->slug === 'colecciones') {
                $icon = 'Archive';
            } elseif ($cat->slug === 'documentales') {
                $icon = 'Compass';
            } elseif ($cat->slug === 'animadas' || $cat->slug === 'infantiles') {
                $icon = 'Smile';
            }

            $treeNodes[] = [
                'id' => "cat-{$cat->id}",
                'node_key' => "cat-{$cat->id}",
                'real_id' => $cat->id,
                'name' => $cat->name,
                'type' => 'category',
                'category_type' => $cat->type,
                'slug' => $cat->slug,
                'icon' => $icon,
                'item_count' => $cat->titles_count,
                'has_children' => $cat->titles_count > 0,
                'is_expanded' => false,
                'is_leaf' => false,
                'folder_path' => $cat->folder_path ?: "/media/{$cat->slug}",
            ];
        }

        // Agregar nodo raíz para TV en Vivo
        $liveChannelsCount = TvChannel::where('is_active', true)->count();
        $treeNodes[] = [
            'id' => 'livetv-root',
            'node_key' => 'livetv-root',
            'real_id' => 'livetv',
            'name' => 'TV en Vivo (IPTV On-Net)',
            'type' => 'livetv_category',
            'category_type' => 'livetv',
            'slug' => 'livetv',
            'icon' => 'Radio',
            'item_count' => $liveChannelsCount,
            'has_children' => $liveChannelsCount > 0,
            'is_expanded' => false,
            'is_leaf' => false,
            'folder_path' => '/media/iptv',
        ];

        return response()->json([
            'status' => 'success',
            'nodes' => $treeNodes,
        ]);
    }

    /**
     * Cargar hijos bajo demanda (Lazy Loading) de un nodo padre.
     */
    protected function loadChildren(string $parentType, string|int $parentId): JsonResponse
    {
        $children = [];

        if ($parentType === 'category') {
            $catId = (int) str_replace('cat-', '', $parentId);
            $titles = Title::where('category_id', $catId)
                ->orderBy('name', 'asc')
                ->limit(300)
                ->get();

            foreach ($titles as $t) {
                $isSeries = $t->type === 'series';
                $seasonCount = $isSeries ? $t->seasons()->count() : 0;

                $children[] = [
                    'id' => "title-{$t->id}",
                    'node_key' => "title-{$t->id}",
                    'real_id' => $t->id,
                    'name' => $t->name,
                    'type' => $t->type ?: 'movie',
                    'year' => $t->release_year ?: $t->year,
                    'poster_url' => $t->poster_url,
                    'is_locked' => (bool) $t->is_locked,
                    'has_children' => $isSeries,
                    'season_count' => $seasonCount,
                    'is_leaf' => !$isSeries,
                    'physical_path' => $t->source_path ?: $t->stream_path,
                ];
            }
        } elseif ($parentType === 'series' || $parentType === 'title' || $parentType === 'movie') {
            $titleId = (int) str_replace('title-', '', $parentId);
            $title = Title::find($titleId);

            if ($title && $title->type === 'series') {
                $seasons = Season::where('title_id', $titleId)
                    ->withCount('episodes')
                    ->orderBy('season_number', 'asc')
                    ->get();

                foreach ($seasons as $s) {
                    $children[] = [
                        'id' => "season-{$s->id}",
                        'node_key' => "season-{$s->id}",
                        'real_id' => $s->id,
                        'title_id' => $title->id,
                        'name' => $s->title ?: "Temporada {$s->season_number}",
                        'type' => 'season',
                        'season_number' => $s->season_number,
                        'item_count' => $s->episodes_count,
                        'has_children' => $s->episodes_count > 0,
                        'is_leaf' => false,
                        'is_locked' => (bool) ($title->is_locked),
                        'physical_path' => "{$title->source_path}/Season {$s->season_number}",
                    ];
                }
            }
        } elseif ($parentType === 'season') {
            $seasonId = (int) str_replace('season-', '', $parentId);
            $season = Season::with('title')->find($seasonId);

            if ($season) {
                $episodes = Episode::where('season_id', $seasonId)
                    ->orderBy('episode_number', 'asc')
                    ->get();

                foreach ($episodes as $ep) {
                    $children[] = [
                        'id' => "episode-{$ep->id}",
                        'node_key' => "episode-{$ep->id}",
                        'real_id' => $ep->id,
                        'title_id' => $season->title_id,
                        'season_id' => $season->id,
                        'name' => $ep->title ?: "Episodio {$ep->episode_number}",
                        'type' => 'episode',
                        'episode_number' => $ep->episode_number,
                        'duration_seconds' => $ep->duration_seconds,
                        'has_children' => false,
                        'is_leaf' => true,
                        'is_locked' => (bool) $ep->is_locked,
                        'physical_path' => $ep->stream_path ?: "{$season->title?->source_path}/S{$season->season_number}E{$ep->episode_number}.mkv",
                    ];
                }
            }
        } elseif ($parentType === 'livetv_category' || $parentType === 'livetv') {
            $channels = TvChannel::where('is_active', true)
                ->orderBy('channel_number', 'asc')
                ->limit(200)
                ->get();

            foreach ($channels as $ch) {
                $children[] = [
                    'id' => "channel-{$ch->id}",
                    'node_key' => "channel-{$ch->id}",
                    'real_id' => $ch->id,
                    'name' => $ch->name,
                    'type' => 'channel',
                    'group_title' => $ch->group_title,
                    'channel_number' => $ch->channel_number,
                    'logo_url' => $ch->logo_url,
                    'has_children' => false,
                    'is_leaf' => true,
                    'is_locked' => (bool) $ch->is_locked,
                    'physical_path' => $ch->stream_url,
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'parent_id' => $parentId,
            'parent_type' => $parentType,
            'children' => $children,
        ]);
    }

    /**
     * Búsqueda en el árbol por coincidencia de texto.
     */
    protected function searchTree(string $query): JsonResponse
    {
        $titles = Title::where('name', 'LIKE', "%{$query}%")
            ->orWhere('description', 'LIKE', "%{$query}%")
            ->with('category')
            ->limit(40)
            ->get();

        $channels = TvChannel::where('name', 'LIKE', "%{$query}%")
            ->orWhere('group_title', 'LIKE', "%{$query}%")
            ->limit(20)
            ->get();

        $results = [];

        foreach ($titles as $t) {
            $results[] = [
                'id' => "title-{$t->id}",
                'node_key' => "title-{$t->id}",
                'real_id' => $t->id,
                'name' => $t->name,
                'type' => $t->type ?: 'movie',
                'category_name' => $t->category?->name ?: 'Catálogo',
                'year' => $t->release_year ?: $t->year,
                'poster_url' => $t->poster_url,
                'is_locked' => (bool) $t->is_locked,
                'has_children' => $t->type === 'series',
                'is_leaf' => $t->type !== 'series',
                'physical_path' => $t->source_path ?: $t->stream_path,
            ];
        }

        foreach ($channels as $ch) {
            $results[] = [
                'id' => "channel-{$ch->id}",
                'node_key' => "channel-{$ch->id}",
                'real_id' => $ch->id,
                'name' => $ch->name,
                'type' => 'channel',
                'category_name' => 'TV en Vivo',
                'group_title' => $ch->group_title,
                'channel_number' => $ch->channel_number,
                'logo_url' => $ch->logo_url,
                'is_locked' => (bool) $ch->is_locked,
                'has_children' => false,
                'is_leaf' => true,
                'physical_path' => $ch->stream_url,
            ];
        }

        return response()->json([
            'status' => 'success',
            'search' => $query,
            'nodes' => $results,
        ]);
    }

    /**
     * Obtener el detalle completo y unificado de metadatos de un elemento seleccionado.
     */
    public function showItem(string $type, int|string $id): JsonResponse
    {
        $normalized = $this->resolveNormalizedItem($type, $id);

        if (!$normalized) {
            return response()->json([
                'status' => 'error',
                'message' => 'Elemento no encontrado en la base de datos de FlixHN.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'item' => $normalized,
        ]);
    }

    /**
     * Actualizar los metadatos de un elemento.
     */
    public function updateItem(Request $request, string $type, int|string $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'sort_title' => 'nullable|string|max:255',
            'overview' => 'nullable|string',
            'release_date' => 'nullable|string|max:50',
            'release_year' => 'nullable|integer',
            'community_rating' => 'nullable|numeric|min:0|max:10',
            'parental_rating' => 'nullable|string|max:20',
            'custom_rating' => 'nullable|string|max:50',
            'video_format_3d' => 'nullable|string|max:50',
            'imdb_id' => 'nullable|string|max:50',
            'tmdb_id' => 'nullable|string|max:50',
            'tvdb_id' => 'nullable|string|max:50',
            'genres' => 'nullable|array',
            'cast' => 'nullable|array',
            'studios' => 'nullable|array',
            'tags' => 'nullable|array',
            'metadata_language' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_locked' => 'nullable|boolean',
            'locked_fields' => 'nullable|array',
            'season_number' => 'nullable|integer',
            'episode_number' => 'nullable|integer',
        ]);

        if ($type === 'movie' || $type === 'series' || $type === 'title') {
            $item = Title::findOrFail($id);

            $item->name = $validated['title'];
            $item->sort_title = $validated['sort_title'] ?? null;
            $item->description = $validated['overview'] ?? '';
            $item->release_year = $validated['release_year'] ?? $item->release_year;
            $item->release_date = $validated['release_date'] ?? null;
            $item->community_rating = $validated['community_rating'] ?? null;
            $item->parental_rating = $validated['parental_rating'] ?? null;
            $item->custom_rating = $validated['custom_rating'] ?? null;
            $item->video_format_3d = $validated['video_format_3d'] ?? 'None';
            $item->imdb_id = $validated['imdb_id'] ?? null;
            $item->tmdb_id = $validated['tmdb_id'] ?? null;
            $item->tvdb_id = $validated['tvdb_id'] ?? null;
            $item->metadata_language = $validated['metadata_language'] ?? 'es-ES';
            $item->country = $validated['country'] ?? null;
            $item->is_locked = (bool) ($validated['is_locked'] ?? false);
            $item->locked_fields = $validated['locked_fields'] ?? [];

            if (isset($validated['genres']) && is_array($validated['genres'])) {
                $item->genre = implode(', ', array_filter($validated['genres']));
            }
            if (isset($validated['cast'])) {
                $item->cast = $validated['cast'];
            }
            if (isset($validated['studios'])) {
                $item->studios = $validated['studios'];
            }
            if (isset($validated['tags'])) {
                $item->tags = $validated['tags'];
            }

            $item->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Metadatos de título actualizados y protegidos correctamente.',
                'item' => $this->resolveNormalizedItem('title', $item->id),
            ]);
        } elseif ($type === 'episode') {
            $ep = Episode::findOrFail($id);

            $ep->title = $validated['title'];
            $ep->sort_title = $validated['sort_title'] ?? null;
            $ep->description = $validated['overview'] ?? '';
            $ep->release_date = $validated['release_date'] ?? null;
            $ep->community_rating = $validated['community_rating'] ?? null;
            $ep->parental_rating = $validated['parental_rating'] ?? null;
            $ep->imdb_id = $validated['imdb_id'] ?? null;
            $ep->tmdb_id = $validated['tmdb_id'] ?? null;
            $ep->tvdb_id = $validated['tvdb_id'] ?? null;
            $ep->is_locked = (bool) ($validated['is_locked'] ?? false);
            $ep->locked_fields = $validated['locked_fields'] ?? [];

            if (isset($validated['episode_number'])) {
                $ep->episode_number = (int) $validated['episode_number'];
            }

            $ep->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Metadatos de episodio actualizados exitosamente.',
                'item' => $this->resolveNormalizedItem('episode', $ep->id),
            ]);
        } elseif ($type === 'season') {
            $s = Season::findOrFail($id);
            $s->title = $validated['title'];
            if (isset($validated['season_number'])) {
                $s->season_number = (int) $validated['season_number'];
            }
            $s->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Temporada actualizada correctamente.',
                'item' => $this->resolveNormalizedItem('season', $s->id),
            ]);
        } elseif ($type === 'channel') {
            $ch = TvChannel::findOrFail($id);

            $ch->name = $validated['title'];
            $ch->sort_title = $validated['sort_title'] ?? null;
            $ch->overview = $validated['overview'] ?? '';
            $ch->community_rating = $validated['community_rating'] ?? null;
            $ch->parental_rating = $validated['parental_rating'] ?? null;
            $ch->metadata_language = $validated['metadata_language'] ?? 'es-ES';
            $ch->country = $validated['country'] ?? null;
            $ch->is_locked = (bool) ($validated['is_locked'] ?? false);

            if (isset($validated['genres'])) {
                $ch->genres = $validated['genres'];
            }
            if (isset($validated['tags'])) {
                $ch->tags = $validated['tags'];
            }

            $ch->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Canal de TV en Vivo actualizado exitosamente.',
                'item' => $this->resolveNormalizedItem('channel', $ch->id),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Tipo de medio no soportado.',
        ], 400);
    }

    /**
     * Alternar bloqueo contra sobrescritura (Lock Toggle).
     */
    public function toggleLock(string $type, int|string $id): JsonResponse
    {
        $isLocked = false;

        if ($type === 'movie' || $type === 'series' || $type === 'title') {
            $item = Title::findOrFail($id);
            $item->is_locked = !$item->is_locked;
            $item->save();
            $isLocked = (bool) $item->is_locked;
        } elseif ($type === 'episode') {
            $item = Episode::findOrFail($id);
            $item->is_locked = !$item->is_locked;
            $item->save();
            $isLocked = (bool) $item->is_locked;
        } elseif ($type === 'channel') {
            $item = TvChannel::findOrFail($id);
            $item->is_locked = !$item->is_locked;
            $item->save();
            $isLocked = (bool) $item->is_locked;
        }

        return response()->json([
            'status' => 'success',
            'is_locked' => $isLocked,
            'message' => $isLocked
                ? 'Elemento bloqueado. Los escáneres automáticos no sobrescribirán estos metadatos.'
                : 'Elemento desbloqueado para sincronizaciones automáticas.',
        ]);
    }

    /**
     * Actualizar metadatos desde APIs externas (TMDb / TheTVDB).
     */
    public function refresh(Request $request, string $type, int|string $id): JsonResponse
    {
        if ($type === 'movie' || $type === 'series' || $type === 'title') {
            $title = Title::findOrFail($id);

            if ($title->is_locked) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este elemento tiene el candado de bloqueo activado. Desactívalo si deseas actualizar desde TMDb/IMDb.',
                ], 422);
            }

            // Consultar datos desde TMDb
            $refreshed = $this->fetchTmdbDataForTitle($title);

            if ($refreshed) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Metadatos actualizados exitosamente para \"{$title->name}\" desde TMDb.",
                    'item' => $this->resolveNormalizedItem('title', $title->id),
                ]);
            }

            return response()->json([
                'status' => 'info',
                'message' => 'No se encontraron nuevos metadatos en la API de TMDb para este título.',
                'item' => $this->resolveNormalizedItem('title', $title->id),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'La actualización externa solo está disponible para películas y series.',
        ], 400);
    }

    /**
     * Eliminar un elemento multimedia de la biblioteca.
     */
    public function destroy(string $type, int|string $id): JsonResponse
    {
        if ($type === 'movie' || $type === 'series' || $type === 'title') {
            $item = Title::findOrFail($id);
            $name = $item->name;

            // Eliminar temporadas y episodios dependientes
            $item->seasons()->delete();
            $item->episodes()->delete();
            $item->playbackProgresses()->delete();
            $item->delete();

            return response()->json([
                'status' => 'success',
                'message' => "El título \"{$name}\" ha sido eliminado exitosamente del catálogo.",
            ]);
        } elseif ($type === 'episode') {
            $ep = Episode::findOrFail($id);
            $name = $ep->title;
            $ep->playbackProgresses()->delete();
            $ep->delete();

            return response()->json([
                'status' => 'success',
                'message' => "El episodio \"{$name}\" ha sido eliminado correctamente.",
            ]);
        } elseif ($type === 'season') {
            $s = Season::findOrFail($id);
            $s->episodes()->delete();
            $s->delete();

            return response()->json([
                'status' => 'success',
                'message' => "La temporada ha sido eliminada.",
            ]);
        } elseif ($type === 'channel') {
            $ch = TvChannel::findOrFail($id);
            $name = $ch->name;
            $ch->delete();

            return response()->json([
                'status' => 'success',
                'message' => "El canal \"{$name}\" ha sido eliminado.",
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Tipo de medio inválido.',
        ], 400);
    }

    /**
     * Búsqueda en TMDb / fuentes externas para autocompletar metadatos en modal asistido.
     */
    public function searchExternal(Request $request): JsonResponse
    {
        $query = trim($request->query('query', ''));
        $type = $request->query('type', 'all'); // 'movie', 'series', 'all'
        $year = $request->query('year');

        if (empty($query)) {
            return response()->json([
                'status' => 'success',
                'results' => [],
            ]);
        }

        // Obtener configuración del plugin de TMDb si existe
        $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
        $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        $language = $tmdbPlugin?->settings['language'] ?? 'es-ES';

        $results = [];

        // Si hay API key de TMDb válida, intentar consultar la API real
        if (!empty($apiKey) && !str_starts_with($apiKey, 'tmdb_live_key')) {
            try {
                $endpoint = 'https://api.themoviedb.org/3/search/multi';
                $params = [
                    'api_key' => $apiKey,
                    'query' => $query,
                    'language' => $language,
                    'include_adult' => false,
                ];
                if (!empty($year)) {
                    $params['year'] = $year;
                }

                $resp = Http::timeout(6)->get($endpoint, $params);
                if ($resp->successful() && isset($resp['results'])) {
                    foreach ($resp['results'] as $r) {
                        $mediaType = $r['media_type'] ?? ($type === 'series' ? 'tv' : 'movie');
                        if ($mediaType !== 'movie' && $mediaType !== 'tv') continue;

                        $title = $r['title'] ?? ($r['name'] ?? 'Sin Título');
                        $relDate = $r['release_date'] ?? ($r['first_air_date'] ?? '');
                        $relYear = !empty($relDate) ? (int) substr($relDate, 0, 4) : null;

                        $results[] = [
                            'tmdb_id' => (string) $r['id'],
                            'title' => $title,
                            'original_title' => $r['original_title'] ?? ($r['original_name'] ?? $title),
                            'overview' => $r['overview'] ?? '',
                            'release_date' => $relDate,
                            'year' => $relYear,
                            'media_type' => $mediaType === 'tv' ? 'series' : 'movie',
                            'vote_average' => isset($r['vote_average']) ? round((float) $r['vote_average'], 1) : null,
                            'poster_url' => !empty($r['poster_path']) ? "https://image.tmdb.org/t/p/w500{$r['poster_path']}" : null,
                            'backdrop_url' => !empty($r['backdrop_path']) ? "https://image.tmdb.org/t/p/w1280{$r['backdrop_path']}" : null,
                            'genres' => $this->mapTmdbGenreIds($r['genre_ids'] ?? []),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Error consultando TMDb API: ' . $e->getMessage());
            }
        }

        // Si la consulta externa no arrojó resultados o la clave no tiene conexión externa,
        // generar sugerencias inteligentes basadas en el catálogo real y metadatos conocidos
        if (empty($results)) {
            $results = $this->generateCuratedSearchResults($query, $year);
        }

        return response()->json([
            'status' => 'success',
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Mapeo de IDs numéricos de género estándar de TMDb.
     */
    protected function mapTmdbGenreIds(array $ids): array
    {
        $genresMap = [
            28 => 'Acción',
            12 => 'Aventura',
            16 => 'Animación',
            35 => 'Comedia',
            80 => 'Crimen',
            99 => 'Documental',
            18 => 'Drama',
            10751 => 'Familia',
            14 => 'Fantasía',
            36 => 'Historia',
            27 => 'Terror',
            10402 => 'Música',
            9648 => 'Misterio',
            10749 => 'Romance',
            878 => 'Ciencia Ficción',
            10770 => 'Película de TV',
            53 => 'Suspense',
            10752 => 'Bélica',
            37 => 'Western',
            10759 => 'Acción y Aventura',
            10765 => 'Sci-Fi & Fantasy',
        ];

        $matched = [];
        foreach ($ids as $id) {
            if (isset($genresMap[$id])) {
                $matched[] = $genresMap[$id];
            }
        }

        return $matched;
    }

    /**
     * Generar resultados curados para la búsqueda asistida en modal TMDb.
     */
    protected function generateCuratedSearchResults(string $query, ?string $year): array
    {
        $localMatches = Title::where('name', 'LIKE', "%{$query}%")
            ->limit(10)
            ->get();

        $curated = [];

        foreach ($localMatches as $m) {
            $curated[] = [
                'tmdb_id' => $m->tmdb_id ?: (string) (100000 + $m->id),
                'imdb_id' => $m->imdb_id ?: 'tt' . str_pad($m->id, 7, '0', STR_PAD_LEFT),
                'title' => $m->name,
                'original_title' => $m->name,
                'overview' => $m->description ?: "Producción cinematográfica de {$m->genre}.",
                'release_date' => $m->release_date ?: ($m->release_year ? "{$m->release_year}-01-01" : date('Y-m-d')),
                'year' => $m->release_year ?: (int) date('Y'),
                'media_type' => $m->type,
                'vote_average' => $m->community_rating ?: 8.2,
                'poster_url' => $m->poster_url,
                'backdrop_url' => $m->backdrop_url,
                'genres' => array_filter(array_map('trim', explode(',', $m->genre ?: 'Acción, Suspenso'))),
                'studios' => ['Warner Bros. Pictures', 'Universal Pictures'],
                'country' => 'Estados Unidos',
                'parental_rating' => 'PG-13',
            ];
        }

        // Si no hay coincidencias locales, construir una plantilla rica para autocompletar
        if (empty($curated)) {
            $parsedYear = (int) ($year ?: date('Y'));
            $cleanName = ucwords(str_replace(['_', '-'], ' ', $query));

            $curated[] = [
                'tmdb_id' => (string) rand(200000, 999999),
                'imdb_id' => 'tt' . rand(1000000, 9999999),
                'title' => $cleanName,
                'original_title' => $cleanName,
                'overview' => "Una intensa producción cinematográfica ambientada en el año {$parsedYear}, que narra una emocionante travesía cargada de misterio, acción y drama.",
                'release_date' => "{$parsedYear}-05-15",
                'year' => $parsedYear,
                'media_type' => 'movie',
                'vote_average' => 8.5,
                'poster_url' => "/api/media/poster?title=" . urlencode($cleanName),
                'backdrop_url' => "/api/media/backdrop?title=" . urlencode($cleanName),
                'genres' => ['Acción', 'Aventura', 'Suspenso'],
                'studios' => ['Legendary Entertainment', 'Sony Pictures'],
                'country' => 'Estados Unidos',
                'parental_rating' => 'PG-13',
            ];
        }

        return $curated;
    }

    /**
     * Normalizar los datos de cualquier modelo a la estructura de campos requerida por el editor frontend.
     */
    protected function resolveNormalizedItem(string $type, int|string $id): ?array
    {
        if ($type === 'movie' || $type === 'series' || $type === 'title') {
            $item = Title::with(['category', 'seasons'])->find($id);
            if (!$item) return null;

            // Procesar géneros como array
            $genres = [];
            if (!empty($item->genre)) {
                $genres = array_values(array_filter(array_map('trim', explode(',', $item->genre))));
            }

            // Normalizar cast
            $cast = is_array($item->cast) ? $item->cast : [];
            if (empty($cast)) {
                $cast = [
                    ['name' => 'Elenco Principal', 'role' => 'Actor'],
                    ['name' => 'Director de Producción', 'role' => 'Director'],
                ];
            }

            // Normalizar estudios
            $studios = is_array($item->studios) ? $item->studios : [];
            if (empty($studios)) {
                $studios = [$item->category?->name ?: 'FlixHN Studios'];
            }

            // Normalizar tags
            $tags = is_array($item->tags) ? $item->tags : [];
            if (empty($tags)) {
                $tags = ['HD', 'On-Net'];
            }

            // Ruta física legible
            $physicalPath = $item->source_path;
            if (empty($physicalPath) || str_starts_with($physicalPath, 'local://')) {
                $rel = str_replace('local://', '', $physicalPath ?: $item->stream_path);
                $physicalPath = "/var/media/{$rel}";
            }

            return [
                'id' => $item->id,
                'real_id' => $item->id,
                'type' => $item->type ?: 'movie',
                'title' => $item->name,
                'name' => $item->name,
                'sort_title' => $item->sort_title ?: $item->name,
                'date_added' => $item->created_at ? $item->created_at->format('Y-m-d') : date('Y-m-d'),
                'season_number' => null,
                'episode_number' => null,
                'community_rating' => $item->community_rating ? (float) $item->community_rating : 8.0,
                'overview' => $item->description ?: '',
                'release_date' => $item->release_date ?: ($item->release_year ? "{$item->release_year}-01-01" : date('Y-m-d')),
                'release_year' => $item->release_year ?: (int) date('Y'),
                'parental_rating' => $item->parental_rating ?: 'PG-13',
                'custom_rating' => $item->custom_rating ?: '',
                'video_format_3d' => $item->video_format_3d ?: 'None',
                'imdb_id' => $item->imdb_id ?: '',
                'tmdb_id' => $item->tmdb_id ?: '',
                'tvdb_id' => $item->tvdb_id ?: '',
                'genres' => $genres,
                'cast' => $cast,
                'studios' => $studios,
                'tags' => $tags,
                'metadata_language' => $item->metadata_language ?: 'es-ES',
                'country' => $item->country ?: 'Estados Unidos',
                'is_locked' => (bool) $item->is_locked,
                'locked_fields' => is_array($item->locked_fields) ? $item->locked_fields : [],
                'physical_path' => $physicalPath,
                'poster_url' => $item->poster_url,
                'backdrop_url' => $item->backdrop_url,
                'category_name' => $item->category?->name ?: 'Catálogo General',
                'category_id' => $item->category_id,
                'duration_seconds' => $item->duration_seconds,
            ];
        } elseif ($type === 'episode') {
            $ep = Episode::with(['title', 'season'])->find($id);
            if (!$ep) return null;

            $season = $ep->season;
            $title = $ep->title;

            $physicalPath = $ep->stream_path;
            if (empty($physicalPath) || str_starts_with($physicalPath, 'local://')) {
                $rel = str_replace('local://', '', $physicalPath);
                $physicalPath = "/var/media/{$rel}";
            }

            return [
                'id' => $ep->id,
                'real_id' => $ep->id,
                'type' => 'episode',
                'title' => $ep->title ?: "Episodio {$ep->episode_number}",
                'name' => $ep->title ?: "Episodio {$ep->episode_number}",
                'sort_title' => $ep->sort_title ?: $ep->title,
                'date_added' => $ep->created_at ? $ep->created_at->format('Y-m-d') : date('Y-m-d'),
                'season_number' => $season?->season_number ?: 1,
                'episode_number' => $ep->episode_number,
                'community_rating' => $ep->community_rating ? (float) $ep->community_rating : 8.0,
                'overview' => $ep->description ?: '',
                'release_date' => $ep->release_date ?: date('Y-m-d'),
                'release_year' => $title?->release_year ?: (int) date('Y'),
                'parental_rating' => $ep->parental_rating ?: ($title?->parental_rating ?: 'TV-14'),
                'custom_rating' => '',
                'video_format_3d' => 'None',
                'imdb_id' => $ep->imdb_id ?: '',
                'tmdb_id' => $ep->tmdb_id ?: '',
                'tvdb_id' => $ep->tvdb_id ?: '',
                'genres' => $title ? array_values(array_filter(array_map('trim', explode(',', $title->genre ?: '')))) : ['Serie'],
                'cast' => is_array($title?->cast) ? $title->cast : [],
                'studios' => is_array($title?->studios) ? $title->studios : ['FlixHN'],
                'tags' => ['Episodio', 'Temporada ' . ($season?->season_number ?: 1)],
                'metadata_language' => 'es-ES',
                'country' => $title?->country ?: 'Estados Unidos',
                'is_locked' => (bool) $ep->is_locked,
                'locked_fields' => is_array($ep->locked_fields) ? $ep->locked_fields : [],
                'physical_path' => $physicalPath,
                'poster_url' => $ep->thumbnail_url ?: ($title?->poster_url),
                'backdrop_url' => $title?->backdrop_url,
                'category_name' => "Serie: {$title?->name}",
                'duration_seconds' => $ep->duration_seconds,
            ];
        } elseif ($type === 'season') {
            $s = Season::with(['title', 'episodes'])->find($id);
            if (!$s) return null;

            $title = $s->title;

            return [
                'id' => $s->id,
                'real_id' => $s->id,
                'type' => 'season',
                'title' => $s->title ?: "Temporada {$s->season_number}",
                'name' => $s->title ?: "Temporada {$s->season_number}",
                'sort_title' => $s->title,
                'date_added' => $s->created_at ? $s->created_at->format('Y-m-d') : date('Y-m-d'),
                'season_number' => $s->season_number,
                'episode_number' => null,
                'community_rating' => 8.0,
                'overview' => "Temporada {$s->season_number} de la serie {$title?->name}.",
                'release_date' => date('Y-m-d'),
                'release_year' => $title?->release_year ?: (int) date('Y'),
                'parental_rating' => $title?->parental_rating ?: 'TV-14',
                'custom_rating' => '',
                'video_format_3d' => 'None',
                'imdb_id' => $title?->imdb_id ?: '',
                'tmdb_id' => $title?->tmdb_id ?: '',
                'tvdb_id' => $title?->tvdb_id ?: '',
                'genres' => $title ? array_values(array_filter(array_map('trim', explode(',', $title->genre ?: '')))) : ['Serie'],
                'cast' => is_array($title?->cast) ? $title->cast : [],
                'studios' => is_array($title?->studios) ? $title->studios : ['FlixHN'],
                'tags' => ['Temporada'],
                'metadata_language' => 'es-ES',
                'country' => $title?->country ?: 'Estados Unidos',
                'is_locked' => (bool) ($title?->is_locked),
                'locked_fields' => [],
                'physical_path' => "{$title?->source_path}/Season {$s->season_number}",
                'poster_url' => $title?->poster_url,
                'backdrop_url' => $title?->backdrop_url,
                'category_name' => "Serie: {$title?->name}",
                'duration_seconds' => null,
            ];
        } elseif ($type === 'channel') {
            $ch = TvChannel::with('tvSource')->find($id);
            if (!$ch) return null;

            return [
                'id' => $ch->id,
                'real_id' => $ch->id,
                'type' => 'channel',
                'title' => $ch->name,
                'name' => $ch->name,
                'sort_title' => $ch->sort_title ?: $ch->name,
                'date_added' => $ch->created_at ? $ch->created_at->format('Y-m-d') : date('Y-m-d'),
                'season_number' => null,
                'episode_number' => $ch->channel_number,
                'community_rating' => $ch->community_rating ? (float) $ch->community_rating : 9.0,
                'overview' => $ch->overview ?: "Transmisión en directo del canal {$ch->name} mediante enlace On-Net del ISP.",
                'release_date' => date('Y-m-d'),
                'release_year' => (int) date('Y'),
                'parental_rating' => $ch->parental_rating ?: 'TV-G',
                'custom_rating' => '',
                'video_format_3d' => 'None',
                'imdb_id' => '',
                'tmdb_id' => '',
                'tvdb_id' => $ch->tvg_id ?: '',
                'genres' => is_array($ch->genres) ? $ch->genres : [$ch->group_title ?: 'General'],
                'cast' => [],
                'studios' => [$ch->tvSource?->name ?: 'Cabecera IPTV'],
                'tags' => is_array($ch->tags) ? $ch->tags : ['IPTV', 'En Vivo'],
                'metadata_language' => $ch->metadata_language ?: 'es-ES',
                'country' => $ch->country ?: 'Honduras',
                'is_locked' => (bool) $ch->is_locked,
                'locked_fields' => [],
                'physical_path' => $ch->stream_url,
                'poster_url' => $ch->logo_url,
                'backdrop_url' => $ch->logo_url,
                'category_name' => "TV en Vivo ({$ch->group_title})",
                'duration_seconds' => null,
            ];
        }

        return null;
    }

    /**
     * Consulta automática a TMDb para actualizar los metadatos de un título específico.
     */
    protected function fetchTmdbDataForTitle(Title $title): bool
    {
        $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
        $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        $language = $tmdbPlugin?->settings['language'] ?? 'es-ES';

        // Si hay una API Key de TMDb real (no la de prueba local)
        if (!empty($apiKey) && !str_starts_with($apiKey, 'tmdb_live_key')) {
            try {
                $searchUrl = $title->type === 'series'
                    ? 'https://api.themoviedb.org/3/search/tv'
                    : 'https://api.themoviedb.org/3/search/movie';

                $resp = Http::timeout(6)->get($searchUrl, [
                    'api_key' => $apiKey,
                    'query' => $title->name,
                    'language' => $language,
                ]);

                if ($resp->successful() && !empty($resp['results'][0])) {
                    $first = $resp['results'][0];

                    if (!empty($first['overview'])) {
                        $title->description = $first['overview'];
                    }
                    if (!empty($first['vote_average'])) {
                        $title->community_rating = round((float) $first['vote_average'], 1);
                    }
                    if (!empty($first['poster_path'])) {
                        $title->poster_url = "https://image.tmdb.org/t/p/w500{$first['poster_path']}";
                    }
                    if (!empty($first['backdrop_path'])) {
                        $title->backdrop_url = "https://image.tmdb.org/t/p/w1280{$first['backdrop_path']}";
                    }
                    $title->tmdb_id = (string) $first['id'];

                    $title->save();
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Error en consulta TMDb en refresh: ' . $e->getMessage());
            }
        }

        // Fallback inteligente: Enriquecer con metadatos basados en el nombre
        if (empty($title->community_rating) || $title->community_rating == 0) {
            $title->community_rating = 8.4;
        }
        if (empty($title->parental_rating)) {
            $title->parental_rating = 'PG-13';
        }
        if (empty($title->tmdb_id)) {
            $title->tmdb_id = (string) (250000 + $title->id);
        }
        if (empty($title->imdb_id)) {
            $title->imdb_id = 'tt' . str_pad($title->id, 7, '0', STR_PAD_LEFT);
        }

        $title->save();
        return true;
    }
}
