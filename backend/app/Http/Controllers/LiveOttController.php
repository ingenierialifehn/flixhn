<?php

namespace App\Http\Controllers;

use App\Models\OttProviderConfig;
use App\Services\Ott\OttManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class LiveOttController extends Controller
{
    protected OttManager $ottManager;

    public function __construct(OttManager $ottManager)
    {
        $this->ottManager = $ottManager;
    }

    /**
     * POST /api/ott/search
     * Búsqueda unificada en catálogo local y proveedores externos compatibles.
     */
    public function search(Request $request): JsonResponse
    {
        // Rate limiting para evitar saturación de búsquedas: 120 peticiones/minuto por IP/usuario
        $throttleKey = 'ott-search:' . ($request->user()?->id ?: $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 120)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Límite de solicitudes de búsqueda excedido. Reintente en {$seconds} segundos.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        // Validación estricta de parámetros
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:200',
            'title' => 'nullable|string|max:200',
            'query' => 'nullable|string|max:200',
            'type' => 'nullable|string|in:all,movie,series,live',
            'provider' => 'nullable|string|max:50',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $searchTerm = trim((string)($request->input('q') ?? $request->input('title') ?? $request->input('query') ?? ''));
        $type = $request->input('type', 'all');
        $provider = $request->input('provider', 'all');
        $limit = (int) $request->input('limit', 30);

        try {
            $searchData = $this->ottManager->unifiedSearch($searchTerm, [
                'type' => $type,
                'provider' => $provider,
                'limit' => $limit,
            ]);

            return response()->json([
                'success' => true,
                'query' => $searchData['query'],
                'total' => $searchData['total'],
                'results' => $searchData['results'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en LiveOttController@search: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al procesar la búsqueda unificada.',
                'results' => [],
            ], 500);
        }
    }

    /**
     * POST /api/ott/stream-link
     * Resuelve el enlace y modo de reproducción para un contenido seleccionado.
     */
    public function streamLink(Request $request): JsonResponse
    {
        $throttleKey = 'ott-stream:' . ($request->user()?->id ?: $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 180)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Límite de solicitudes de reproducción excedido. Reintente en {$seconds} segundos.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $validator = Validator::make($request->all(), [
            'content_id' => 'required|string|max:255',
            'provider' => 'nullable|string|max:50',
            'type' => 'nullable|string|in:movie,series,episode,live',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $contentId = $request->input('content_id');
        $provider = $request->input('provider');
        $type = $request->input('type');

        try {
            $playbackInfo = $this->ottManager->resolveStreamLink($contentId, $provider, [
                'type' => $type,
                'client_ip' => $request->ip(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json($playbackInfo, 200);
        } catch (\Throwable $e) {
            Log::error('Error en LiveOttController@streamLink: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => 'Error interno al consultar el enlace de reproducción: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/ott/providers
     * Devuelve el catálogo de proveedores configurados y su estado sin exponer secretos.
     */
    public function getProviders(): JsonResponse
    {
        try {
            $providers = $this->ottManager->getProvidersOverview();

            return response()->json([
                'success' => true,
                'total' => count($providers),
                'providers' => $providers,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en LiveOttController@getProviders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No se pudo cargar la lista de proveedores OTT.',
                'providers' => [],
            ], 500);
        }
    }

    /**
     * GET /api/ott/provider/{id}/status
     * Verifica la disponibilidad y conectividad del proveedor solicitado.
     */
    public function getProviderStatus(string $id): JsonResponse
    {
        try {
            $status = $this->ottManager->checkProviderStatus($id);

            return response()->json([
                'success' => $status['success'] ?? false,
                'data' => $status,
            ], ($status['status'] ?? '') === 'not_found' ? 404 : 200);
        } catch (\Throwable $e) {
            Log::error("Error en LiveOttController@getProviderStatus para [{$id}]: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el estado del proveedor.',
            ], 500);
        }
    }
}
