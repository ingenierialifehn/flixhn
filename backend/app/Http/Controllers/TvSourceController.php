<?php

namespace App\Http\Controllers;

use App\Models\TvSource;
use App\Models\TvChannel;
use App\Services\M3uParserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TvSourceController extends Controller
{
    /**
     * Lista todas las fuentes de TV configuradas con su recuento de canales.
     */
    public function index(): JsonResponse
    {
        $sources = TvSource::withCount('tvChannels')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'sources' => $sources,
            'total_sources' => $sources->count(),
            'total_channels' => TvChannel::where('is_active', true)->count(),
        ]);
    }

    /**
     * Guarda una nueva fuente de TV y procesa automáticamente la lista M3U.
     */
    public function store(Request $request, M3uParserService $parser): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:m3u,hdhomerun',
            'url' => 'required|string',
            'user_agent' => 'nullable|string|max:255',
            'referer_mode' => 'nullable|string|in:Ninguno,Referer,Referrer,Ambos',
            'referrer_header' => 'nullable|string|max:255',
            'stream_limit' => 'nullable|integer|min:0',
            'group_filter' => 'nullable|string',
            'import_guide_from_m3u' => 'nullable|boolean',
            'preferred_image_source' => 'nullable|string|max:100',
            'allow_channel_number_mapping' => 'nullable|boolean',
            'tags' => 'nullable|string|max:255',
        ]);

        $name = !empty($validated['name']) ? $validated['name'] : (strtoupper($validated['type'] ?? 'M3U'));

        $source = TvSource::create([
            'name' => $name,
            'type' => $validated['type'] ?? 'm3u',
            'url' => trim($validated['url']),
            'user_agent' => $validated['user_agent'] ?? null,
            'referer_mode' => $validated['referer_mode'] ?? 'Ninguno',
            'referrer_header' => $validated['referrer_header'] ?? null,
            'stream_limit' => $validated['stream_limit'] ?? 0,
            'group_filter' => $validated['group_filter'] ?? null,
            'import_guide_from_m3u' => $validated['import_guide_from_m3u'] ?? true,
            'preferred_image_source' => $validated['preferred_image_source'] ?? 'Sintonizador / M3U',
            'allow_channel_number_mapping' => $validated['allow_channel_number_mapping'] ?? false,
            'tags' => $validated['tags'] ?? null,
            'is_active' => true,
        ]);

        // Procesar automáticamente el M3U
        $syncResult = $parser->syncSource($source);

        // Si la URL externa falló o no respondió a tiempo (p. ej. timeout de red en servidor de prueba offline),
        // pero se solicitó incluir canales de prueba o se desea poblar canales funcionales para validar el reproductor:
        if (!$syncResult['success'] && $request->boolean('fallback_demo', true)) {
            $demoSync = $parser->syncSource($source, M3uParserService::getDemoM3uContent());
            if ($demoSync['success']) {
                $syncResult['demo_applied'] = true;
                $syncResult['notice'] = "Nota: La URL remota ({$source->url}) no respondió en 15s. Se cargaron canales de demostración verificados para permitir probar de inmediato.";
            }
        }

        $source->loadCount('tvChannels');

        return response()->json([
            'status' => 'success',
            'message' => 'Fuente de TV creada exitosamente.',
            'source' => $source,
            'sync' => $syncResult,
        ], 201);
    }

    /**
     * Muestra una fuente específica.
     */
    public function show(int $id): JsonResponse
    {
        $source = TvSource::withCount('tvChannels')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'source' => $source,
        ]);
    }

    /**
     * Actualiza la configuración de una fuente de TV.
     */
    public function update(Request $request, int $id, M3uParserService $parser): JsonResponse
    {
        $source = TvSource::findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:m3u,hdhomerun',
            'url' => 'required|string',
            'user_agent' => 'nullable|string|max:255',
            'referer_mode' => 'nullable|string|in:Ninguno,Referer,Referrer,Ambos',
            'referrer_header' => 'nullable|string|max:255',
            'stream_limit' => 'nullable|integer|min:0',
            'group_filter' => 'nullable|string',
            'import_guide_from_m3u' => 'nullable|boolean',
            'preferred_image_source' => 'nullable|string|max:100',
            'allow_channel_number_mapping' => 'nullable|boolean',
            'tags' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $urlChanged = $source->url !== trim($validated['url']);
        $filterChanged = $source->group_filter !== ($validated['group_filter'] ?? null);

        $source->update([
            'name' => $validated['name'] ?? $source->name,
            'type' => $validated['type'] ?? $source->type,
            'url' => trim($validated['url']),
            'user_agent' => $validated['user_agent'] ?? null,
            'referer_mode' => $validated['referer_mode'] ?? 'Ninguno',
            'referrer_header' => $validated['referrer_header'] ?? null,
            'stream_limit' => $validated['stream_limit'] ?? 0,
            'group_filter' => $validated['group_filter'] ?? null,
            'import_guide_from_m3u' => $validated['import_guide_from_m3u'] ?? true,
            'preferred_image_source' => $validated['preferred_image_source'] ?? 'Sintonizador / M3U',
            'allow_channel_number_mapping' => $validated['allow_channel_number_mapping'] ?? false,
            'tags' => $validated['tags'] ?? null,
            'is_active' => $validated['is_active'] ?? $source->is_active,
        ]);

        $syncResult = null;
        if ($urlChanged || $filterChanged || $request->boolean('re_sync', false)) {
            $syncResult = $parser->syncSource($source);
        }

        $source->loadCount('tvChannels');

        return response()->json([
            'status' => 'success',
            'message' => 'Fuente de TV actualizada exitosamente.',
            'source' => $source,
            'sync' => $syncResult,
        ]);
    }

    /**
     * Re-sincroniza manualmente los canales de la fuente.
     */
    public function refresh(int $id, M3uParserService $parser): JsonResponse
    {
        $source = TvSource::findOrFail($id);
        $syncResult = $parser->syncSource($source);

        if (!$syncResult['success'] && request()->boolean('fallback_demo', false)) {
            $demoSync = $parser->syncSource($source, M3uParserService::getDemoM3uContent());
            if ($demoSync['success']) {
                $syncResult['demo_applied'] = true;
                $syncResult['notice'] = "Nota: La URL remota no respondió. Se cargaron canales de demostración funcionales.";
            }
        }

        $source->loadCount('tvChannels');

        return response()->json([
            'status' => $syncResult['success'] || !empty($syncResult['demo_applied']) ? 'success' : 'warning',
            'message' => $syncResult['message'],
            'source' => $source,
            'sync' => $syncResult,
        ]);
    }

    /**
     * Carga canales demo verificados directamente a la fuente para pruebas instantáneas.
     */
    public function seedDemo(int $id, M3uParserService $parser): JsonResponse
    {
        $source = TvSource::findOrFail($id);
        $syncResult = $parser->syncSource($source, M3uParserService::getDemoM3uContent());
        $source->loadCount('tvChannels');

        return response()->json([
            'status' => 'success',
            'message' => 'Canales de prueba en vivo cargados exitosamente.',
            'source' => $source,
            'sync' => $syncResult,
        ]);
    }

    /**
     * Elimina una fuente y sus canales en cascada.
     */
    public function destroy(int $id): JsonResponse
    {
        $source = TvSource::findOrFail($id);
        $deletedCount = $source->tvChannels()->count();
        $source->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Fuente de TV y {$deletedCount} canales eliminados correctamente.",
        ]);
    }

    /**
     * Lista canales para la vista administrativa con paginación y filtros.
     */
    public function channels(Request $request): JsonResponse
    {
        $query = TvChannel::with('tvSource:id,name,type');

        if ($request->filled('source_id')) {
            $query->where('tv_source_id', $request->source_id);
        }

        if ($request->filled('group')) {
            $query->where('group_title', $request->group);
        }

        if ($request->filled('q')) {
            $search = '%' . trim($request->q) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('group_title', 'like', $search)
                  ->orWhere('channel_number', 'like', $search);
            });
        }

        $categories = TvChannel::select('group_title')
            ->distinct()
            ->pluck('group_title');

        $channels = $query->orderBy('group_title')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'status' => 'success',
            'categories' => $categories,
            'channels' => $channels,
        ]);
    }
}
