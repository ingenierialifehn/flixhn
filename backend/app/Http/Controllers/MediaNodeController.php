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
        // Si no existen nodos, inicializar el nodo primario existente
        if (MediaNode::count() === 0) {
            $defaultUrl = ServerSetting::get('media_server_url', 'http://45.4.87.126:6789');
            $defaultKey = ServerSetting::get('media_server_api_key', '');
            $defaultName = ServerSetting::get('media_server_name')
                ?: ServerSetting::get('emby_server_name', 'server01-PowerEdge-R720');

            $parsedHost = parse_url($defaultUrl, PHP_URL_HOST) ?: '45.4.87.126';
            $parsedPort = parse_url($defaultUrl, PHP_URL_PORT) ?: 6789;

            MediaNode::create([
                'name' => $defaultName,
                'ip_address' => $parsedHost,
                'port' => (int) $parsedPort,
                'api_key' => $defaultKey,
                'is_active' => true,
                'is_master' => true,
            ]);
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
        $baseUrl = "http://{$node->ip_address}:{$node->port}";
        ServerSetting::set('media_server_url', $baseUrl);
        ServerSetting::set('media_server_api_key', $node->api_key);
        ServerSetting::set('media_server_name', $node->name);

        return response()->json([
            'status' => 'success',
            'message' => "Nodo '{$node->name}' establecido como activo principal.",
            'node' => $node,
        ]);
    }

    /**
     * Sincroniza el catálogo multimedia de un nodo específico.
     */
    public function sync(int $id, MediaSyncService $syncService): JsonResponse
    {
        $node = MediaNode::findOrFail($id);
        $baseUrl = "http://{$node->ip_address}:{$node->port}";

        try {
            $stats = $syncService->syncFromEmby($baseUrl, $node->api_key);
            ServerSetting::set('last_sync_at', now()->toIso8601String());

            return response()->json([
                'status' => 'success',
                'message' => "Catálogo de '{$node->name}' sincronizado exitosamente.",
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
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
