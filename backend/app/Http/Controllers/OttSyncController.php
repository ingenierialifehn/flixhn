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
        $sessionData = null;

        if ($userId) {
            $sessionData = OttSession::where('user_id', $userId)
                                     ->where('provider', 'netflix')
                                     ->latest()
                                     ->first();
        }

        if (!$sessionData) {
            $sessionData = OttSession::where('provider', 'netflix')
                                     ->latest()
                                     ->first();
        }

        return $sessionData;
    }

    /**
     * Vista inicial donde se muestran estadísticas y estado actual de la cuenta conectada
     */
    public function index(Request $request)
    {
        if ($request->has('q') && trim((string) $request->input('q')) !== '') {
            return $this->search($request);
        }

        // 1. Obtener sesión activa real desde DB (ejemplo tabla 'ott_sessions')
        $sessionData = $this->resolveNetflixSession();

        if (!$sessionData) {
            return view('admin.ott-sync.dashboard', [
                'sessionData'  => null,
                'status'       => 'disconnected',
                'totalResults' => 0,
                'viewData'     => [
                    'query'        => '',
                    'results'      => [],
                    'totalResults' => 0,
                ],
            ]);
        }

        // 2. Preparar datos para vista inicial (sin arrays fijos hardcoded, limpio por defecto)
        $viewData = [
            'query'        => '',
            'results'      => [],
            'totalResults' => 0,
        ];

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success'      => true,
                'status'       => 'connected',
                'sessionId'    => $sessionData->id,
                'userName'     => $sessionData->user_name ?? ($sessionData->session_metadata['user_name'] ?? 'Usuario Netflix'),
                'totalWatched' => OttContent::whereNotNull('local_file_path')->count(),
                'viewData'     => $viewData,
            ]);
        }

        return view('admin.ott-sync.dashboard', [
            'sessionId'    => $sessionData->id,
            'clientId'     => $sessionData->client_id ?? '',
            'refreshToken' => $sessionData->refresh_token ?? '',
            'userName'     => $sessionData->user_name ?? ($sessionData->session_metadata['user_name'] ?? 'Usuario Netflix'), // Nombre del perfil conectado real
            'totalWatched' => OttContent::whereNotNull('local_file_path')->count(), // Conteo real desde DB
            'viewData'     => $viewData,
            'totalResults' => 0,
            'sessionData'  => $sessionData,
            'status'       => 'connected',
        ]);
    }

    /**
     * Endpoint específico para búsquedas dinámicas reales vía API
     */
    public function search(Request $request)
    {
        if (!$request->has('q') || trim((string) $request->input('q')) === '') {
            return redirect()->route('admin.ott-sync.index');
        }

        $query = trim((string) $request->input('q'));

        // Obtener sesión activa de nuevo (asegurando que siempre use la más reciente conectada)
        $sessionData = $this->resolveNetflixSession();

        if (!$sessionData || empty(trim((string) ($sessionData->refresh_token ?? '')))) {
            // Si no hay cuenta conectada o token vacío, devolver estado desconectado
            return view('admin.ott-sync.dashboard', [
                'status'       => 'disconnected',
                'sessionData'  => null,
                'totalResults' => 0,
                'viewData'     => [
                    'query'        => $query,
                    'results'      => [],
                    'totalResults' => 0,
                ],
            ]);
        }

        try {
            // 3. Llamar al servicio para buscar CONTENIDO REAL usando API de Netflix Graph v1.x
            $rawResults = OttSyncService::searchNetflixGraphAPI(
                $query,
                $sessionData->client_id,
                $sessionData->refresh_token
            );

            // Extraer lista limpia de resultados
            $results = [];
            if (isset($rawResults['items']) && is_array($rawResults['items'])) {
                $results = $rawResults['items'];
            } elseif (is_array($rawResults) && array_is_list($rawResults)) {
                $results = $rawResults;
            }

            // 4. Construir respuesta final con metadatos visuales necesarios para el Frontend (Poster real desde URL)
            $viewData = [
                'query'            => $query,
                'results'          => $results,
                'totalResults'     => count($results),
                'fallback_message' => $rawResults['fallback_message'] ?? null,
            ];

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success'      => true,
                    'viewData'     => $viewData,
                    'results'      => $results,
                    'totalResults' => count($results),
                    'sessionId'    => $sessionData->id,
                ]);
            }

            return view('admin.ott-sync.search-results', [
                'viewData'     => $viewData,
                'results'      => $results,
                'totalResults' => count($results),
                'sessionId'    => $sessionData->id,
                'sessionData'  => $sessionData,
                'userName'     => $sessionData->user_name ?? ($sessionData->session_metadata['user_name'] ?? 'Usuario Netflix'),
                'totalWatched' => OttContent::whereNotNull('local_file_path')->count(),
            ]);

        } catch (\Exception $e) {
            // Manejo de errores robusto: Si falla la API, muestra un mensaje claro sin datos fantasma
            return view('admin.ott-search-error', ['message' => "Error al conectar con Netflix API: {$e->getMessage()}"]);
        }
    }

    /**
     * Vista principal donde se muestran las tarjetas y botones (estado inicial o tras búsqueda reciente)
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
     * Métodos de compatibilidad con API existente
     */
    public function searchNetflix(Request $request): JsonResponse
    {
        return $this->searchProvider($request, 'netflix');
    }

    public function searchProvider(Request $request, string $provider): JsonResponse
    {
        $query = $request->input('q', '') ?: $request->input('query', '');
        $sessionData = OttSession::where('provider', $provider)->latest()->first();

        $rawResults = OttSyncService::searchNetflixGraphAPI(
            (string) $query,
            $sessionData?->client_id ?? '',
            $sessionData?->refresh_token ?? ''
        );

        $items = isset($rawResults['items']) && is_array($rawResults['items'])
            ? $rawResults['items']
            : (is_array($rawResults) ? $rawResults : []);

        return response()->json($items);
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