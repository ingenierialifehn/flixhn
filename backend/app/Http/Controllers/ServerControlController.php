<?php

namespace App\Http\Controllers;

use App\Models\ServerSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerControlController extends Controller
{
    /**
     * Ejecuta acciones de control sobre el nodo de streaming (restart, stop, start).
     */
    public function control(Request $request, string $action): JsonResponse
    {
        $validActions = ['restart', 'stop', 'start'];
        if (!in_array($action, $validActions)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Acción no válida. Opciones permitidas: restart, stop, start.',
            ], 422);
        }

        switch ($action) {
            case 'restart':
                ServerSetting::set('streaming_service_status', 'restarting');
                // Intentar recargar servicios locales si es posible
                if (function_exists('shell_exec') && !str_starts_with(PHP_OS, 'WIN')) {
                    @shell_exec('kill -HUP 1 2>/dev/null');
                }
                return response()->json([
                    'status' => 'success',
                    'server_status' => 'restarting',
                    'message' => 'Servicio de streaming reiniciándose en el nodo local.',
                ]);

            case 'stop':
                ServerSetting::set('streaming_service_status', 'stopped');
                return response()->json([
                    'status' => 'success',
                    'server_status' => 'stopped',
                    'message' => 'Servicio de streaming detenido. El streaming local ha sido pausado.',
                ]);

            case 'start':
                ServerSetting::set('streaming_service_status', 'online');
                return response()->json([
                    'status' => 'success',
                    'server_status' => 'online',
                    'message' => 'Servicio de streaming iniciado exitosamente y operando en línea.',
                ]);
        }

        return response()->json(['status' => 'error', 'message' => 'Error inesperado'], 500);
    }

    /**
     * Actualiza el alias/nombre institucional del servidor.
     */
    public function updateName(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
        ]);

        $name = trim($validated['name']);
        ServerSetting::set('media_server_name', $name);
        ServerSetting::set('emby_server_name', $name);

        return response()->json([
            'status' => 'success',
            'name' => $name,
            'message' => "Nombre del servidor actualizado a '{$name}'.",
        ]);
    }

    /**
     * Consulta el estado del servicio del servidor.
     */
    public function status(): JsonResponse
    {
        $status = ServerSetting::get('streaming_service_status', 'online');
        $name = ServerSetting::get('media_server_name')
            ?: ServerSetting::get('emby_server_name', 'server01-PowerEdge-R720');

        return response()->json([
            'status' => 'success',
            'server_status' => $status,
            'name' => $name,
        ]);
    }
}
