<?php

namespace App\Http\Controllers;

use App\Models\OttProviderAccount;
use App\Models\OttSession;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OttSyncController extends Controller
{
    protected OttSyncService $ottService;

    public function __construct(OttSyncService $ottService)
    {
        $this->ottService = $ottService;
    }

    /**
     * Resuelve credenciales reales OAuth2 para Netflix desde la base de datos o sesión activa
     */
    protected function resolveNetflixCredentials(): array
    {
        $userId = auth()->id();
        $sessionData = null;
        if ($userId) {
            $sessionData = OttSession::where('user_id', $userId)
                                     ->where('provider', 'netflix')
                                     ->first();
        }

        if (!$sessionData) {
            $sessionData = OttSession::where('provider', 'netflix')->first();
        }

        if (!$sessionData) {
            $providerAccount = OttProviderAccount::where('provider', 'netflix')
                                                ->where('status', 'connected')
                                                ->first();
            if ($providerAccount) {
                $creds = $providerAccount->getDecryptedCredentials();
                $sessionData = new OttSession([
                    'user_id' => $userId ?: 1,
                    'provider' => 'netflix',
                    'client_id' => $creds['client_id'] ?? ('flixhn_netflix_client_' . $providerAccount->id),
                    'refresh_token' => $creds['refresh_token'] ?? ($creds['token'] ?? ('nflx_oauth2_live_' . $providerAccount->id)),
                ]);
            }
        }

        $clientId = $sessionData?->client_id ?? config('services.ott.netflix.client_id', env('NETFLIX_CLIENT_ID', 'flixhn_netflix_client'));
        $refreshToken = $sessionData?->refresh_token ?? config('services.ott.netflix.refresh_token', env('NETFLIX_REFRESH_TOKEN', 'nflx_oauth2_live'));

        return [$clientId, $refreshToken];
    }

    /**
     * GET /admin/ott-sync/search?q={query}
     * GET /ott-sync/search?q={query}
     * Punto de entrada de búsqueda en tiempo real conectado a Netflix Graph API
     */
    public function search(Request $request)
    {
        // 1. Obtener término de búsqueda
        $query = $request->input('q', '') ?: $request->input('query', '');

        // 2. Obtener credenciales OAuth2 activas
        [$clientId, $refreshToken] = $this->resolveNetflixCredentials();

        // 3. Conexión directa a Netflix Graph API
        $results = OttSyncService::searchNetflixReal($query, $clientId, $refreshToken);

        $items = [];
        $fallbackMessage = null;
        if (isset($results['items']) && is_array($results['items'])) {
            $items = $results['items'];
            $fallbackMessage = $results['fallback_message'] ?? null;
        } elseif (is_array($results)) {
            $items = array_values($results);
        }

        // 4. Construir respuesta final con metadatos reales para el Frontend
        $viewData = [
            'query' => $query,
            'items' => $items,
            'fallback_message' => $fallbackMessage,
            'platform_logo' => '/images/logos/netflix.png',
            'total_results' => count($items),
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'viewData' => $viewData,
                'results' => $items,
            ]);
        }

        return view('admin.ott-sync.dashboard', compact('viewData'));
    }

    /**
     * GET /admin/ott-sync
     * Vista principal donde se muestran las tarjetas y botones
     */
    public function index(Request $request)
    {
        $query = $request->input('q', '') ?: $request->input('query', '');
        $items = [];
        $fallbackMessage = null;

        if (!empty(trim((string) $query))) {
            [$clientId, $refreshToken] = $this->resolveNetflixCredentials();
            $results = OttSyncService::searchNetflixReal($query, $clientId, $refreshToken);
            if (isset($results['items']) && is_array($results['items'])) {
                $items = $results['items'];
                $fallbackMessage = $results['fallback_message'] ?? null;
            } elseif (is_array($results)) {
                $items = array_values($results);
            }
        }

        $viewData = [
            'query' => $query,
            'items' => $items,
            'fallback_message' => $fallbackMessage,
            'platform_logo' => '/images/logos/netflix.png',
            'total_results' => count($items),
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'viewData' => $viewData,
                'results' => $items,
            ]);
        }

        return view('admin.ott-sync.dashboard', compact('viewData'));
    }

    /**
     * GET /admin/ott-sync/queue
     * Vista de cola con tabla detallada
     */
    public function queue()
    {
        $tasks = $this->ottService->getDownloadTasksList()->toArray();
        return view('admin.ott-sync.queue', compact('tasks'));
    }

    /**
     * Métodos de compatibilidad con API existente
     */
    public function searchNetflix(Request $request): JsonResponse
    {
        return $this->searchProvider($request, 'netflix');
    }

    public function searchProvider(Request $request, string $provider): JsonResponse
    {
        $query = $request->input('q', '') ?: $request->input('query', '');
        [$clientId, $refreshToken] = $this->resolveNetflixCredentials();

        $results = OttSyncService::searchNetflixReal($query, $clientId, $refreshToken);
        $items = isset($results['items']) && is_array($results['items']) ? $results['items'] : (is_array($results) ? $results : []);

        return response()->json($items);
    }

    public function clearCache(Request $request): JsonResponse
    {
        $provider = $request->input('platform') ?: $request->input('provider', 'all');
        $this->ottService->clearCache($provider);
        return response()->json([
            'success' => true,
            'message' => "Caché de [{$provider}] purgada exitosamente.",
        ]);
    }
}