<?php

namespace App\Http\Controllers;

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\DeviceDetectorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceSessionController extends Controller
{
    protected DeviceDetectorService $detector;

    public function __construct(DeviceDetectorService $detector)
    {
        $this->detector = $detector;
    }

    /**
     * Listar todos los dispositivos y sesiones reales con filtrado y estadísticas en tiempo real.
     * Consulta estrictamente la tabla device_sessions de la base de datos real.
     * Si no hay sesiones, devuelve array vacío [].
     * GET /api/admin/devices
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Detección y transición de sesiones inactivas / expiradas (> 45s sin ping pasan a stopped)
        DeviceSession::where('playback_state', '!=', 'stopped')
            ->where(function ($q) {
                $q->whereNull('last_heartbeat_at')
                  ->orWhere('last_heartbeat_at', '<', now()->subSeconds(45));
            })
            ->update([
                'playback_state' => 'stopped',
            ]);

        $statusFilter = $request->query('status', 'all'); // all, playing, paused, blocked, idle
        $search = trim($request->query('search', ''));

        $query = DeviceSession::with('user')
            ->orderBy('is_blocked', 'asc')
            ->orderByRaw("CASE WHEN playback_state = 'playing' THEN 1 WHEN playback_state = 'paused' THEN 2 ELSE 3 END")
            ->orderBy('last_heartbeat_at', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('custom_name', 'like', "%{$search}%")
                    ->orWhere('device_id', 'like', "%{$search}%")
                    ->orWhere('client_identifier', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('current_media_title', 'like', "%{$search}%")
                    ->orWhere('os_name', 'like', "%{$search}%")
                    ->orWhere('browser_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        if ($statusFilter === 'playing') {
            $query->where('playback_state', 'playing')
                ->where('is_blocked', false)
                ->where('last_heartbeat_at', '>=', now()->subSeconds(35));
        } elseif ($statusFilter === 'paused') {
            $query->where('playback_state', 'paused')
                ->where('is_blocked', false)
                ->where('last_heartbeat_at', '>=', now()->subSeconds(35));
        } elseif ($statusFilter === 'blocked') {
            $query->where('is_blocked', true);
        } elseif ($statusFilter === 'idle') {
            $query->where(function ($q) {
                $q->where('playback_state', 'stopped')
                  ->orWhere('last_heartbeat_at', '<', now()->subSeconds(35));
            })->where('is_blocked', false);
        }

        $devices = $query->get();

        // 2. Calcular resumen de telemetría global estrictamente con datos reales
        $allDevices = DeviceSession::all();
        $total = $allDevices->count();
        $online = $allDevices->filter(fn($d) => $d->is_online)->count();
        $playing = $allDevices->where('playback_state', 'playing')->where('is_blocked', false)->filter(fn($d) => $d->is_online)->count();
        $paused = $allDevices->where('playback_state', 'paused')->where('is_blocked', false)->filter(fn($d) => $d->is_online)->count();
        $blocked = $allDevices->where('is_blocked', true)->count();
        $idle = $allDevices->where('is_blocked', false)->filter(fn($d) => !$d->is_online)->count();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total' => $total,
                'online' => $online,
                'playing' => $playing,
                'paused' => $paused,
                'blocked' => $blocked,
                'idle' => $idle,
            ],
            'devices' => $devices,
        ]);
    }

    /**
     * Detalle completo de telemetría de un dispositivo real.
     * GET /api/admin/devices/{id}
     */
    public function show(int $id): JsonResponse
    {
        $device = DeviceSession::with('user')->find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no encontrado en el inventario.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'device' => $device,
        ]);
    }

    /**
     * Actualizar alias/nombre asignado al dispositivo.
     * PUT /api/admin/devices/{id}/name
     */
    public function updateName(Request $request, int $id): JsonResponse
    {
        $device = DeviceSession::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no encontrado.',
            ], 404);
        }

        $validated = $request->validate([
            'custom_name' => 'required|string|max:120',
        ]);

        $device->update([
            'custom_name' => trim($validated['custom_name']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Alias actualizado correctamente a '{$device->custom_name}'.",
            'device' => $device->fresh('user'),
        ]);
    }

    /**
     * Control remoto de reproducción (Pausar, Reanudar, Detener).
     * POST /api/admin/devices/{id}/playback-control
     */
    public function playbackControl(Request $request, int $id): JsonResponse
    {
        $device = DeviceSession::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no encontrado.',
            ], 404);
        }

        $validated = $request->validate([
            'action' => 'required|in:pause,resume,stop',
        ]);

        $action = $validated['action'];

        if ($action === 'pause') {
            $device->playback_state = 'paused';
            $device->remote_command = 'pause';
            $msg = 'Comando de pausa enviado al dispositivo.';
        } elseif ($action === 'resume') {
            $device->playback_state = 'playing';
            $device->remote_command = 'resume';
            $msg = 'Comando de reproducción reanudada enviado.';
        } elseif ($action === 'stop') {
            $device->playback_state = 'stopped';
            $device->remote_command = 'stop';
            $device->current_media_type = 'idle';
            $msg = 'Reproducción detenida remotamente.';
        }

        $device->command_issued_at = now();
        $device->save();

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'device' => $device->fresh('user'),
        ]);
    }

    /**
     * Alternar estado de bloqueo de acceso para el equipo.
     * POST /api/admin/devices/{id}/toggle-block
     */
    public function toggleBlock(Request $request, int $id): JsonResponse
    {
        $device = DeviceSession::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no encontrado.',
            ], 404);
        }

        $isBlocked = $request->has('is_blocked')
            ? (bool) $request->input('is_blocked')
            : !$device->is_blocked;

        $device->is_blocked = $isBlocked;

        if ($isBlocked) {
            $device->playback_state = 'stopped';
            $device->remote_command = 'blocked';
            $device->command_issued_at = now();
            $msg = "El dispositivo '{$device->custom_name}' ha sido BLOQUEADO del nodo FlixHN.";
        } else {
            $device->remote_command = null;
            $msg = "El dispositivo '{$device->custom_name}' ha sido DESBLOQUEADO con éxito.";
        }

        $device->save();

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'device' => $device->fresh('user'),
        ]);
    }

    /**
     * Terminar/expulsar la sesión activa del dispositivo.
     * DELETE /api/admin/devices/{id}/session
     */
    public function terminateSession(int $id): JsonResponse
    {
        $device = DeviceSession::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no encontrado.',
            ], 404);
        }

        $device->update([
            'playback_state' => 'stopped',
            'current_media_type' => 'idle',
            'remote_command' => 'kick',
            'command_issued_at' => now(),
            'session_started_at' => null,
            'last_heartbeat_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Sesión de '{$device->custom_name}' finalizada y expulsada exitosamente.",
            'device' => $device->fresh('user'),
        ]);
    }

    /**
     * Endpoint de reporte de latido / heartbeat en tiempo real.
     * Soporta POST /api/telemetry/heartbeat y POST /api/devices/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            return response()->json(['status' => 'error', 'message' => 'device_id requerido'], 422);
        }

        $user = $request->user();
        $ip = $request->ip() ?: ($user?->assigned_ip ?: '127.0.0.1');

        if (!$user && $ip) {
            $user = User::where('assigned_ip', $ip)->first();
        }

        $ua = $request->header('User-Agent');
        $detected = $this->detector->detect($ua, $ip);

        $device = DeviceSession::firstOrNew(['device_id' => $deviceId]);

        if (!$device->exists) {
            $ownerName = $user?->customer_name ?: ($user?->username ?: 'Abonado');
            $device->custom_name = $request->input('custom_name')
                ?: ($detected['browser_name'] . ' (' . $ownerName . ')');
            $device->device_type = $detected['device_type'];
            $device->os_name = $detected['os_name'];
            $device->browser_name = $detected['browser_name'];
            $device->session_started_at = now();
        } else {
            if ($device->playback_state === 'stopped' || !$device->session_started_at) {
                $device->session_started_at = now();
            }
            if (!$device->os_name || $device->os_name === 'Desconocido') {
                $device->os_name = $detected['os_name'];
                $device->browser_name = $detected['browser_name'];
                $device->device_type = $detected['device_type'];
            }
        }

        if ($user) {
            $device->user_id = $user->id;
            $device->client_identifier = $user->username ?: ('CONTRATO-' . $user->id);
        }

        $device->ip_address = $ip;
        $device->user_agent = $ua;
        $device->last_heartbeat_at = now();

        // Parámetros de telemetría enviados por los reproductores
        $mediaType = $request->input('media_type', $request->input('current_media_type', 'vod'));
        $title = $request->input('title', $request->input('media_title', $request->input('current_media_title')));
        $poster = $request->input('poster', $request->input('media_poster', $request->input('current_media_poster')));
        $currentTime = (int) $request->input('current_time', $request->input('position_seconds', $request->input('playback_position_seconds', 0)));
        $duration = (int) $request->input('duration', $request->input('duration_seconds', $request->input('media_duration_seconds', 0)));
        $playbackState = $request->input('playback_state', $request->input('state', 'playing'));

        $device->current_media_type = $mediaType;
        $device->current_media_title = $title;
        $device->current_media_poster = $poster;
        $device->playback_position_seconds = $currentTime;
        $device->media_duration_seconds = $duration;
        $device->playback_state = in_array($playbackState, ['playing', 'paused', 'stopped']) ? $playbackState : 'playing';

        if ($request->has('resolution') || $request->has('screen_resolution')) {
            $device->screen_resolution = $request->input('resolution', $request->input('screen_resolution'));
        }
        if ($request->has('bitrate')) {
            $device->bitrate = $request->input('bitrate');
        }
        if ($request->has('connection_type')) {
            $device->connection_type = $request->input('connection_type');
        }

        $device->save();

        // Si hay comando remoto pendiente (pause, resume, stop), entregarlo al cliente y limpiarlo
        $pendingCommand = $device->remote_command;
        if ($pendingCommand && $pendingCommand !== 'blocked') {
            $device->remote_command = null;
            $device->save();
        }

        return response()->json([
            'status' => 'success',
            'is_blocked' => (bool) $device->is_blocked,
            'remote_command' => $pendingCommand,
        ]);
    }

    /**
     * Endpoint de detención inmediata de reproducción al desmontar el reproductor o cerrar pestaña.
     * POST /api/telemetry/stop
     */
    public function stopPlayback(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            return response()->json(['status' => 'error', 'message' => 'device_id requerido'], 422);
        }

        $device = DeviceSession::where('device_id', $deviceId)->first();
        if ($device) {
            $device->update([
                'playback_state' => 'stopped',
                'last_heartbeat_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sesión detenida correctamente.',
        ]);
    }
}
