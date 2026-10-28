<?php

namespace App\Http\Controllers;

use App\Models\MediaIngestTask;
use App\Models\OttDownloadTask;
use App\Models\OttProviderAccount;
use App\Services\OttSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OttSyncController extends Controller
{
    protected OttSyncService $ottService;

    public function __construct(OttSyncService $ottService)
    {
        $this->ottService = $ottService;
    }

    /**
     * GET /admin/ott-sync
     * Vista Blade / Dashboard integrada para FlixGrab y Sincronización OTT On-Net.
     */
    public function index(Request $request)
    {
        try {
            $providers = $this->ottService->getProvidersOverview();
            $tasks = $this->ottService->getDownloadTasksList()->toArray();
            
            $query = trim((string) ($request->query('q') ?: $request->query('query', '')));
            $provider = $request->query('provider') ?: 'all';
            $type = $request->query('type');

            if (!empty($query)) {
                $results = $this->ottService->search($query, $provider, $type);
            } else {
                $results = $this->ottService->getAvailableCatalog($provider, $type);
            }

            $results = $this->normalizeResultsForFrontend($results);

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'providers' => $providers,
                    'tasks' => $tasks,
                    'results' => $results,
                ]);
            }

            return view('admin.ott-sync', compact('results', 'providers', 'tasks'));
        } catch (\Throwable $e) {
            Log::error('Error en OttSyncController@index: ' . $e->getMessage());

            $results = [];
            $providers = $this->ottService->getProvidersOverview();
            $tasks = [];

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json($results);
            }

            return view('admin.ott-sync', compact('results', 'providers', 'tasks'));
        }
    }

    /**
     * GET /api/v1/netflix/search?q={query}
     * Búsqueda especializada para Netflix con la estructura exacta esperada por el Frontend.
     */
    public function searchNetflix(Request $request): JsonResponse
    {
        return $this->searchProvider($request, 'netflix');
    }

    /**
     * GET /api/v1/{provider}/search?q={query}
     * Búsqueda para cualquier proveedor OTT (netflix, disney, hbo_max, amazon_prime, apple_tv, paramount).
     */
    public function searchProvider(Request $request, string $provider): JsonResponse
    {
        try {
            $query = trim((string) ($request->query('q') ?: $request->query('query', '')));
            $type = $request->query('type');
            $results = $this->ottService->search($query, $provider, $type);

            $normalized = $this->normalizeResultsForFrontend($results);

            if ($request->query('format') === 'wrapped') {
                return response()->json([
                    'success' => true,
                    'provider' => strtoupper($provider),
                    'total' => count($normalized),
                    'results' => $normalized,
                ]);
            }

            return response()->json($normalized);
        } catch (\Throwable $e) {
            Log::error("Error en OttSyncController@searchProvider [{$provider}]: " . $e->getMessage());
            return response()->json([]);
        }
    }

    /**
     * GET /api/v1/search?q={query}&platform={platform}
     * GET /api/admin/ott-providers/search
     * Búsqueda unificada global de catálogo OTT.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = trim((string) ($request->query('q') ?: $request->query('query', '')));
            $provider = $request->query('provider') ?: $request->query('platform', 'all');
            $type = $request->query('type');

            if (!empty($query)) {
                $results = $this->ottService->search($query, $provider, $type);
            } else {
                $results = $this->ottService->getAvailableCatalog($provider, $type);
            }

            $normalized = $this->normalizeResultsForFrontend($results);

            if ($request->query('format') === 'wrapped') {
                return response()->json([
                    'success' => true,
                    'total' => count($normalized),
                    'results' => $normalized,
                ]);
            }

            return response()->json($normalized);
        } catch (\Throwable $e) {
            Log::error('Error en OttSyncController@search: ' . $e->getMessage());
            return response()->json([]);
        }
    }

    /**
     * DELETE /api/v1/ott/cache
     * POST /api/admin/ott-providers/clear-cache
     * Purga la caché y contenido local para evitar que items borrados sigan apareciendo.
     */
    public function clearCache(Request $request): JsonResponse
    {
        try {
            $provider = $request->input('platform') ?: $request->input('provider', 'all');
            $this->ottService->clearCache($provider);

            return response()->json([
                'success' => true,
                'message' => "Caché de [{$provider}] purgada exitosamente. Sin datos huérfanos.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al purgar caché: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normaliza los resultados asegurando la existencia de localFilePath, filePath y poster
     */
    protected function normalizeResultsForFrontend(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $provider = strtolower($item['provider'] ?? 'netflix');
            $title = $item['title'] ?? 'Medio OTT';
            $slug = Str::slug($title);
            $uniqueId = $item['unique_id'] ?? ($item['id'] ?? "ott_{$slug}");

            $localFilePath = $item['localFilePath'] 
                ?? $item['local_file_path'] 
                ?? $item['filePath'] 
                ?? "/var/www/html/storage/media/{$provider}/{$slug}.mp4";

            if (!str_starts_with($localFilePath, '/var/www/html') && str_starts_with($localFilePath, '/storage/')) {
                $localFilePath = '/var/www/html' . $localFilePath;
            }

            $relativeLocalPath = '/storage/media/' . $provider . '/' . $slug . '.mp4';
            $poster = $item['poster'] ?? ($item['poster_url'] ?? "/storage/media/{$provider}/{$slug}.jpg");

            $normalized[] = array_merge($item, [
                'id' => $uniqueId,
                'unique_id' => $uniqueId,
                'title' => $title,
                'poster' => $poster,
                'poster_url' => $item['poster_url'] ?? $poster,
                'year' => (int) ($item['year'] ?? ($item['release_year'] ?? 2024)),
                'type' => $item['type'] ?? (($item['media_type'] ?? '') === 'series' ? 'Serie' : 'Película'),
                'media_type' => ($item['type'] ?? '') === 'Serie' ? 'series' : ($item['media_type'] ?? 'movie'),
                'platform' => strtoupper($item['platform'] ?? $provider),
                'provider' => $provider,
                'quality' => $item['quality'] ?? '4K HDR',
                'quality_available' => $item['quality_available'] ?? ($item['quality'] ?? '4K HDR'),
                'localFilePath' => $localFilePath,
                'local_file_path' => $relativeLocalPath,
                'filePath' => $localFilePath,
                'file_path' => $localFilePath,
                'status' => $item['status'] ?? 'Available',
                'available' => true,
            ]);
        }

        return $normalized;
    }
}
