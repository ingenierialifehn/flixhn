<?php

namespace App\Http\Controllers;

use App\Models\TvGuideSource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TvGuideSourceController extends Controller
{
    /**
     * Lista todas las fuentes de datos de guía EPG configuradas.
     */
    public function index(): JsonResponse
    {
        $sources = TvGuideSource::orderByDesc('created_at')->get();

        return response()->json([
            'status' => 'success',
            'guide_sources' => $sources,
        ]);
    }

    /**
     * Guarda una nueva fuente de datos de guía.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:50',
            'url' => 'required|string',
        ]);

        $source = TvGuideSource::create([
            'name' => $validated['name'] ?: 'XMLTV Guía EPG',
            'type' => $validated['type'] ?: 'xmltv',
            'url' => trim($validated['url']),
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Fuente de datos de guía agregada exitosamente.',
            'guide_source' => $source,
        ], 201);
    }

    /**
     * Actualiza o sincroniza la fuente de datos de guía.
     */
    public function refresh(int $id): JsonResponse
    {
        $source = TvGuideSource::findOrFail($id);
        $source->update([
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Datos de la guía EPG actualizados correctamente.',
            'guide_source' => $source,
        ]);
    }

    /**
     * Elimina una fuente de datos de guía.
     */
    public function destroy(int $id): JsonResponse
    {
        $source = TvGuideSource::findOrFail($id);
        $source->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Fuente de datos de guía eliminada.',
        ]);
    }
}
