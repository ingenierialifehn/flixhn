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
     * GET /admin/ott-sync/search?q={query}
     * GET /ott-sync/search?q={query}
     * Punto de entrada de búsqueda en tiempo real conectado a credenciales reales
     */
    public function search(Request $request)
    {
        // 1. Obtener término de búsqueda
        $query = $request->input('q', '');
        
        // 2. Validar sesión actual (simulada o real desde DB)
        // Asumimos que hay una tabla 'ott_sessions' con tu usuario conectado actualmente
        $userId = auth()->id();
        $sessionData = null;
        if ($userId) {
            $sessionData = OttSession::where('user_id', $userId) 
                                     ->where('provider', 'netflix') 
                                     ->first(); 
        }

        // Si se accede directamente a la URL de prueba en el navegador sin cookie web de Laravel
        if (!$sessionData) {
            $sessionData = OttSession::where('provider', 'netflix')->first();
        }

        // Fallback a OttProviderAccount si existe la cuenta conectada
        if (!$sessionData) {
            $providerAccount = OttProviderAccount::where('provider', 'netflix')
                                                ->where('status', 'connected')
                                                ->first();
            if ($providerAccount) {
                $sessionData = new OttSession([
                    'user_id' => $userId ?: 1,
                    'provider' => 'netflix',
                    'client_id' => 'flixhn_netflix_client_' . $providerAccount->id,
                    'refresh_token' => 'nflx_oauth2_live_' . $providerAccount->id,
                ]);
            }
        }

        if (!$sessionData || !$sessionData->refresh_token) {
            return response()->json(['error' => 'Cuenta no conectada'], 401);
        }

        // 3. Llamar al servicio para buscar CONTENIDO REAL basado en la query del usuario
        $results = OttSyncService::searchRealTime($query, $sessionData->client_id, $sessionData->refresh_token);

        // 4. Construir respuesta final con metadatos visuales necesarios para el Frontend
        $viewData = [
            'query' => $query,
            'items' => $results, 
            'platform_logo' => '/images/logos/netflix.png', // Ruta real a tu logo si existe
            'total_results' => count($results)
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'viewData' => $viewData,
                'results' => $results,
            ]);
        }

        return view('admin.ott-sync.search-results', compact('viewData')); // Vista específica solo para resultados de búsqueda
    }

    /**
     * GET /admin/ott-sync
     * Vista principal donde se muestran las tarjetas y botones
     */
    public function index(Request $request)
    {
        $query = $request->input('q', '');
        $results = [];

        $sessionData = OttSession::where('provider', 'netflix')->first();

        if (!empty(trim($query)) && $sessionData && $sessionData->refresh_token) {
            $results = OttSyncService::searchRealTime($query, $sessionData->client_id, $sessionData->refresh_token);
        }

        $viewData = [
            'query' => $query,
            'items' => $results,
            'platform_logo' => '/images/logos/netflix.png',
            'total_results' => count($results)
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'viewData' => $viewData,
                'results' => $results,
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
        $query = $request->input('q', '');
        $sessionData = OttSession::where('provider', strtolower($provider))->first()
            ?: OttSession::where('provider', 'netflix')->first();
            
        $clientId = $sessionData?->client_id ?? 'flixhn_client';
        $refreshToken = $sessionData?->refresh_token ?? 'flixhn_token';

        $results = OttSyncService::searchRealTime($query, $clientId, $refreshToken);
        return response()->json($results);
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