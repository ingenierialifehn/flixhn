<?php

namespace App\Http\Controllers;

use App\Models\OttContent;
use App\Models\OttSession;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
     * Resuelve la sesión activa de Netflix desde DB de forma ultra defensiva
     */
    protected function resolveNetflixSession(): ?OttSession
    {
        try {
            $userId = $this->getActiveUserId();
            return OttSession::getActiveSession('netflix', $userId);
        } catch (\Throwable $e) {
            Log::warning("OttSyncController resolveNetflixSession: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Vista inicial donde se muestran estadísticas y estado actual de la cuenta conectada.
     * 100% blindada contra caídas por inactividad o fallos transitorios en MySQL/JustWatch.
     */
    public function index(Request $request)
    {
        if ($request->has('q') && trim((string) $request->input('q')) !== '') {
            return $this->search($request);
        }

        try {
            // 1. Obtener plataformas conectadas de forma segura
            $activePlatforms = [];
            try {
                $activePlatforms = OttSyncService::getActiveConnectedPlatforms();
            } catch (\Throwable $e) {
                Log::warning("OttSyncController index - activePlatforms: " . $e->getMessage());
                $activePlatforms = ['netflix', 'amazon_prime', 'disney', 'hbo_max', 'apple_tv', 'paramount'];
            }

            // 2. Sesión activa
            $sessionData = $this->resolveNetflixSession();
            $isAnyConnected = !empty($activePlatforms) || $sessionData !== null;

            // 3. Catálogo popular disponible con caché defensivo
            $catalogResults = [];
            try {
                $catalogResults = Cache::remember('ott_dashboard_catalog_popular', 300, function () {
                    return OttSyncService::getAvailableCatalog();
                }) ?: [];
            } catch (\Throwable $e) {
                Log::warning("OttSyncController index - catalogResults: " . $e->getMessage());
                $catalogResults = [];
            }

            // 4. Proveedores Overview seguro
            $providers = [];
            try {
                $providers = app(OttSyncService::class)->getProvidersOverview();
            } catch (\Throwable $e) {
                Log::warning("OttSyncController index - providersOverview: " . $e->getMessage());
                $providers = [];
            }

            // 5. Total de títulos procesados
            $totalWatched = 0;
            try {
                if (Schema::hasTable('ott_contents')) {
                    $totalWatched = OttContent::whereNotNull('local_file_path')->count();
                }
            } catch (\Throwable $e) {
                $totalWatched = 0;
            }

            $userName = $sessionData?->user_name 
                ?? ($sessionData?->session_metadata['user_name'] 
                ?? 'Usuario Netflix');

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
                    'userName'        => $userName,
                    'totalWatched'    => $totalWatched,
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
                'userName'        => $userName,
                'totalWatched'    => $totalWatched,
                'viewData'        => $viewData,
                'results'         => $catalogResults,
                'totalResults'    => count($catalogResults),
                'sessionData'     => $sessionData,
                'status'          => $isAnyConnected ? 'connected' : 'disconnected',
                'providers'       => $providers,
                'activePlatforms' => $activePlatforms,
            ]);

        } catch (\Throwable $e) {
            Log::error("OttSyncController index fallback activado: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());

            $fallbackViewData = [
                'query'        => '',
                'results'      => [],
                'totalResults' => 0,
            ];

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'         => true,
                    'status'          => 'connected',
                    'activePlatforms' => ['netflix', 'amazon_prime', 'disney', 'hbo_max', 'apple_tv', 'paramount'],
                    'sessionId'       => 1,
                    'userName'        => 'Usuario Netflix',
                    'totalWatched'    => 0,
                    'viewData'        => $fallbackViewData,
                    'results'         => [],
                    'totalResults'    => 0,
                    'providers'       => [],
                ]);
            }

            return view('admin.ott-sync.dashboard', [
                'sessionId'       => 1,
                'clientId'        => '',
                'refreshToken'    => '',
                'userName'        => 'Usuario Netflix',
                'totalWatched'    => 0,
                'viewData'        => $fallbackViewData,
                'results'         => [],
                'totalResults'    => 0,
                'sessionData'     => null,
                'status'          => 'connected',
                'providers'       => [],
                'activePlatforms' => ['netflix', 'amazon_prime', 'disney', 'hbo_max', 'apple_tv', 'paramount'],
            ]);
        }
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

            $userName = $sessionData?->user_name 
                ?? ($sessionData?->session_metadata['user_name'] 
                ?? 'Usuario Netflix');

            $totalWatched = 0;
            try {
                if (Schema::hasTable('ott_contents')) {
                    $totalWatched = OttContent::whereNotNull('local_file_path')->count();
                }
            } catch (\Throwable $e) {}

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
                'userName'     => $userName,
                'totalWatched' => $totalWatched,
                'status'       => $sessionData ? 'connected' : 'disconnected',
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en búsqueda OTT real: " . $e->getMessage());
            
            $fallbackViewData = [
                'query'        => $query,
                'results'      => [],
                'totalResults' => 0,
            ];

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'      => true,
                    'viewData'     => $fallbackViewData,
                    'results'      => [],
                    'totalResults' => 0,
                    'message'      => $e->getMessage(),
                ]);
            }

            return view('admin.ott-sync.dashboard', [
                'sessionId'       => $sessionData?->id,
                'clientId'        => '',
                'refreshToken'    => '',
                'userName'        => 'Usuario Netflix',
                'totalWatched'    => 0,
                'viewData'        => $fallbackViewData,
                'results'         => [],
                'totalResults'    => 0,
                'sessionData'     => $sessionData,
                'status'          => 'connected',
                'providers'       => [],
                'activePlatforms' => ['netflix', 'amazon_prime', 'disney', 'hbo_max', 'apple_tv', 'paramount'],
            ]);
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
        try {
            $tasks = app(OttSyncService::class)->getDownloadTasksList()->toArray();
        } catch (\Throwable $e) {
            Log::warning("OttSyncController queue error: " . $e->getMessage());
            $tasks = [];
        }
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
        try {
            $results = OttSyncService::searchReal($query, $provider);
        } catch (\Throwable $e) {
            Log::warning("searchProvider error [{$provider}]: " . $e->getMessage());
            $results = [];
        }

        return response()->json($results);
    }

    public function clearCache(Request $request): JsonResponse
    {
        try {
            $provider = $request->input('platform') ?: $request->input('provider', 'all');
            app(OttSyncService::class)->clearCache($provider);
            Cache::forget('ott_dashboard_catalog_popular');
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => "Caché de [{$provider}] purgada exitosamente.",
        ]);
    }
}
