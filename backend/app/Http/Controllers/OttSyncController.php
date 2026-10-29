<?php

namespace App\Http\Controllers;

use App\Models\OttContent;
use App\Models\OttSession;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OttSyncController extends Controller
{
    /**
     * Resuelve de forma segura el ID de usuario activo o null si no hay sesión web activa
     */
    protected function getActiveUserId(): ?int
    {
        try {
            return auth()->id() ? (int) auth()->id() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resuelve la sesión activa de Netflix desde DB
     */
    protected function resolveNetflixSession(): ?OttSession
    {
        $userId = $this->getActiveUserId();
        return OttSession::getActiveSession('netflix', $userId);
    }

    /**
     * Vista inicial donde se muestran estadísticas y estado actual de la cuenta conectada
     */
    public function index(Request $request)
    {
        if ($request->has('q') && trim((string) $request->input('q')) !== '') {
            return $this->search($request);
        }

        // 1. Obtener plataformas conectadas reales
        $activePlatforms = OttSyncService::getActiveConnectedPlatforms();
        $sessionData = $this->resolveNetflixSession();
        $isAnyConnected = !empty($activePlatforms) || $sessionData !== null;

        // 2. Catálogo real disponible exclusivamente de plataformas conectadas
        $catalogResults = OttSyncService::getAvailableCatalog();
        $providers = app(OttSyncService::class)->getProvidersOverview();

        $viewData = [
            'query'        => '',
            'results'      => $catalogResults,
            'totalResults' => count($catalogResults),
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success'         => true,
                'status'          => $isAnyConnected ? 'connected' : 'disconnected',
                'activePlatforms' => $activePlatforms,
                'sessionId'       => $sessionData?->id,
                'userName'        => $sessionData?->user_name ?? ($sessionData?->session_metadata['user_name'] ?? 'Usuario Netflix'),
                'totalWatched'    => OttContent::whereNotNull('local_file_path')->count(),
                'viewData'        => $viewData,
                'results'         => $catalogResults,
                'totalResults'    => count($catalogResults),
                'providers'       => $providers,
            ]);
        }

        return view('admin.ott-sync.dashboard', [
            'sessionId'       => $sessionData?->id,
            'clientId'        => $sessionData?->client_id ?? '',
            'refreshToken'    => $sessionData?->refresh_token ?? '',
            'userName'        => $sessionData?->user_name ?? ($sessionData?->session_metadata['user_name'] ?? 'Usuario Netflix'),
            'totalWatched'    => OttContent::whereNotNull('local_file_path')->count(),
            'viewData'        => $viewData,
            'results'         => $catalogResults,
            'totalResults'    => count($catalogResults),
            'sessionData'     => $sessionData,
            'status'          => $isAnyConnected ? 'connected' : 'disconnected',
            'providers'       => $providers,
            'activePlatforms' => $activePlatforms,
        ]);
    }

    /**
     * Endpoint específico para búsquedas dinámicas reales
     */
    public function search(Request $request)
    {
        $query = trim((string) ($request->input('q') ?: $request->input('query', '')));

        if ($query === '') {
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'      => true,
                    'viewData'     => ['query' => '', 'results' => [], 'totalResults' => 0],
                    'results'      => [],
                    'totalResults' => 0,
                ]);
            }
            return redirect()->route('admin.ott-sync.index');
        }

        $platform = $request->input('platform') ?: $request->input('provider');
        $sessionData = $this->resolveNetflixSession();

        try {
            // Buscar contenido REAL en la plataforma especificada
            $results = OttSyncService::searchReal($query, $platform ? (string) $platform : null);

            $viewData = [
                'query'        => $query,
                'results'      => $results,
                'totalResults' => count($results),
            ];

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'      => true,
                    'viewData'     => $viewData,
                    'results'      => $results,
                    'totalResults' => count($results),
                    'sessionId'    => $sessionData?->id,
                ]);
            }

            return view('admin.ott-sync.search-results', [
                'viewData'     => $viewData,
                'results'      => $results,
                'totalResults' => count($results),
                'sessionId'    => $sessionData?->id,
                'sessionData'  => $sessionData,
                'userName'     => $sessionData?->user_name ?? ($sessionData?->session_metadata['user_name'] ?? 'Usuario Netflix'),
                'totalWatched' => OttContent::whereNotNull('local_file_path')->count(),
                'status'       => $sessionData ? 'connected' : 'disconnected',
            ]);

        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'      => false,
                    'message'      => $e->getMessage(),
                    'results'      => [],
                    'totalResults' => 0,
                ], 500);
            }
            return view('admin.ott-search-error', ['message' => "Error al consultar catálogo: {$e->getMessage()}"]);
        }
    }

    /**
     * Vista principal donde se muestran las tarjetas y botones
     */
    public function dashboard()
    {
        return $this->index(request());
    }

    /**
     * Vista de cola de ingesta OTT
     */
    public function queue()
    {
        $tasks = app(OttSyncService::class)->getDownloadTasksList()->toArray();
        return view('admin.ott-sync.queue', compact('tasks'));
    }

    /**
     * Métodos de búsqueda por plataforma
     */
    public function searchNetflix(Request $request): JsonResponse
    {
        return $this->searchProvider($request, 'netflix');
    }

    public function searchProvider(Request $request, string $provider): JsonResponse
    {
        $query = trim((string) ($request->input('q') ?: $request->input('query', '')));
        $results = OttSyncService::searchReal($query, $provider);

        return response()->json($results);
    }

    public function clearCache(Request $request): JsonResponse
    {
        $provider = $request->input('platform') ?: $request->input('provider', 'all');
        app(OttSyncService::class)->clearCache($provider);

        return response()->json([
            'success' => true,
            'message' => "Caché de [{$provider}] purgada exitosamente.",
        ]);
    }
}
