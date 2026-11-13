<?php

namespace App\Http\Controllers;

use App\Models\MediaNode;
use App\Models\ServerSetting;
use App\Services\MediaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaNodeController extends Controller
{
    /**
     * Lista todos los nodos configurados.
     */
    public function index(): JsonResponse
    {
        $sourceMode = ServerSetting::get('media_source_mode', 'local');

        // Si no existen nodos, inicializar el nodo maestro local PowerEdge
        if (MediaNode::count() === 0) {
            MediaNode::create([
                'name' => 'server01-PowerEdge-R720',
                'ip_address' => '/mnt/media_library',
                'port' => 8789,
                'api_key' => '',
                'is_active' => true,
                'is_master' => true,
            ]);
        } else {
            // Si el modo configurado es almacenamiento local directo, asegurar que el nodo maestro no dependa de Emby
            if ($sourceMode === 'local') {
                $masterNode = MediaNode::where('is_master', true)->first();
                if ($masterNode && (str_contains($masterNode->ip_address, '45.4.87.126') || str_contains(strtolower($masterNode->name), 'poweredge'))) {
                    $masterNode->update([
                        'ip_address' => '/mnt/media_library',
                        'api_key' => '',
                    ]);
                }
            }
        }

        $nodes = MediaNode::orderBy('is_master', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'nodes' => $nodes,
        ]);
    }

    /**
     * Registra un nuevo nodo de medios remoto.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'ip_address' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'api_key' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_master' => 'nullable|boolean',
        ]);

        $isMaster = !empty($validated['is_master']);
        if ($isMaster) {
            MediaNode::where('is_master', true)->update(['is_master' => false]);
        }

        $node = MediaNode::create([
            'name' => trim($validated['name']),
            'ip_address' => trim($validated['ip_address']),
            'port' => (int) ($validated['port'] ?? 6789),
            'api_key' => trim($validated['api_key'] ?? ''),
            'is_active' => $validated['is_active'] ?? true,
            'is_master' => $isMaster,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Nodo '{$node->name}' registrado exitosamente.",
            'node' => $node,
        ], 201);
    }

    /**
     * Activa un nodo como maestro / nodo activo de reproducción.
     */
    public function activate(int $id): JsonResponse
    {
        $node = MediaNode::findOrFail($id);

        MediaNode::where('id', '!=', $node->id)->update(['is_master' => false]);
        $node->update(['is_active' => true, 'is_master' => true]);

        // Actualizar ServerSettings con los datos del nodo activado
        if (str_starts_with($node->ip_address, '/mnt') || str_contains(strtolower($node->name), 'poweredge')) {
            ServerSetting::set('media_source_mode', 'local', 'media');
            ServerSetting::set('media_server_name', $node->name, 'media');
        } else {
            $baseUrl = "http://{$node->ip_address}:{$node->port}";
            ServerSetting::set('media_server_url', $baseUrl, 'media');
            ServerSetting::set('media_server_api_key', $node->api_key ?: '', 'media');
            ServerSetting::set('media_server_name', $node->name, 'media');
        }

        return response()->json([
            'status' => 'success',
            'message' => "Nodo '{$node->name}' establecido como activo principal.",
            'node' => $node,
        ]);
    }

    /**
     * Sincroniza el catálogo multimedia de un nodo específico.
     */
    public function sync(int $id, \App\Services\RemoteMediaConnectorService $remoteService, MediaSyncService $syncService): JsonResponse
    {
        $node = MediaNode::findOrFail($id);
        $sourceMode = ServerSetting::get('media_source_mode', 'local');

        // Si el sistema está en modo local o el nodo es el servidor físico local PowerEdge
        if ($sourceMode === 'local' || str_contains(strtolower($node->name), 'poweredge') || str_contains(strtolower($node->name), 'server01')) {
            try {
                $scanner = app(\App\Services\LocalMediaScannerService::class);
                $stats = $scanner->scan();
                $totalIndexed = ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0) + ($stats['total_episodes'] ?? 0);

                return response()->json([
                    'status' => 'success',
                    'success' => true,
                    'message' => "Nodo '{$node->name}' sincronizado con almacenamiento local directo: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios indexados.",
                    'total_synced' => $totalIndexed,
                    'metrics' => [
                        'total_titles' => ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0),
                        'total_movies' => $stats['total_movies'] ?? 0,
                        'total_series' => $stats['total_series'] ?? 0,
                        'total_episodes' => $stats['total_episodes'] ?? 0,
                        'total_files_scanned' => $stats['total_files_scanned'] ?? 0,
                    ],
                    'stats' => $stats,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => "Error al escanear almacenamiento local en '{$node->name}': " . $e->getMessage(),
                ], 500);
            }
        }

        $baseUrl = "http://{$node->ip_address}:{$node->port}";

        try {
            $resp = $remoteService->syncCatalog($baseUrl, $node->api_key);
            ServerSetting::set('last_sync_at', now()->toIso8601String());

            if ($resp->getStatusCode() === 200) {
                return $resp;
            }

            // Fallback a escáner local
            $scanner = app(\App\Services\LocalMediaScannerService::class);
            $stats = $scanner->scan();
            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => "Catálogo de '{$node->name}' sincronizado con biblioteca local: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios.",
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => "Error al sincronizar con el nodo '{$node->name}': " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Elimina un nodo.
     */
    public function destroy(int $id): JsonResponse
    {
        $node = MediaNode::findOrFail($id);
        if ($node->is_master && MediaNode::count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'No puedes eliminar el nodo maestro actual. Asigna otro como maestro primero.',
            ], 422);
        }

        $node->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Nodo eliminado correctamente.',
        ]);
    }
}
