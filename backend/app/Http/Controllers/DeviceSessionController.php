<?php

namespace App\Http\Controllers;

use App\Models\DeviceSession;
use App\Models\Title;
use App\Models\TvChannel;
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
     * Listar todos los dispositivos y sesiones con filtrado y estadísticas en tiempo real.
     * GET /api/admin/devices
     */
    public function index(Request $request): JsonResponse
    {
        // Auto-sembrar dispositivos de demostración si la base de datos está vacía
        if (DeviceSession::count() === 0) {
            $this->seedDemoDevices();
        }

        $statusFilter = $request->query('status', 'all'); // all, playing, paused, blocked, idle
        $search = trim($request->query('search', ''));

        $query = DeviceSession::with('user')->orderBy('is_blocked', 'asc')
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
            $query->where('playback_state', 'playing')->where('is_blocked', false);
        } elseif ($statusFilter === 'paused') {
            $query->where('playback_state', 'paused')->where('is_blocked', false);
        } elseif ($statusFilter === 'blocked') {
            $query->where('is_blocked', true);
        } elseif ($statusFilter === 'idle') {
            $query->where('playback_state', 'stopped')->where('is_blocked', false);
        }

        $devices = $query->get();

        // Calcular resumen de telemetría global
        $allDevices = DeviceSession::all();
        $total = $allDevices->count();
        $online = $allDevices->filter(fn($d) => $d->is_online)->count();
        $playing = $allDevices->where('playback_state', 'playing')->where('is_blocked', false)->count();
        $paused = $allDevices->where('playback_state', 'paused')->where('is_blocked', false)->count();
        $blocked = $allDevices->where('is_blocked', true)->count();
        $idle = $allDevices->where('playback_state', 'stopped')->where('is_blocked', false)->count();

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
     * Detalle completo de telemetría de un dispositivo.
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
     * Endpoint de reporte de latido / heartbeat desde clientes FlixHN.
     * POST /api/devices/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            return response()->json(['status' => 'error', 'message' => 'device_id requerido'], 422);
        }

        $user = $request->user();
        $ip = $request->ip() ?: ($user?->assigned_ip ?: '127.0.0.1');
        $ua = $request->header('User-Agent');

        $detected = $this->detector->detect($ua, $ip);

        $device = DeviceSession::firstOrNew(['device_id' => $deviceId]);

        if (!$device->exists) {
            $device->custom_name = $request->input('custom_name') ?: ($detected['browser_name'] . ' (' . ($user?->customer_name ?: 'Abonado') . ')');
            $device->device_type = $detected['device_type'];
            $device->os_name = $detected['os_name'];
            $device->browser_name = $detected['browser_name'];
            $device->session_started_at = now();
        }

        if ($user) {
            $device->user_id = $user->id;
            $device->client_identifier = $user->username ?: 'CLI-' . $user->id;
        }

        $device->ip_address = $ip;
        $device->user_agent = $ua;
        $device->last_heartbeat_at = now();

        if ($request->has('media_type')) {
            $device->current_media_type = $request->input('media_type', 'idle');
            $device->current_media_title = $request->input('media_title');
            $device->current_media_poster = $request->input('media_poster');
            $device->playback_position_seconds = (int) $request->input('position_seconds', 0);
            $device->media_duration_seconds = (int) $request->input('duration_seconds', 0);
            $device->playback_state = $request->input('state', 'playing');
        }

        if ($request->has('resolution')) {
            $device->screen_resolution = $request->input('resolution');
        }
        if ($request->has('bitrate')) {
            $device->bitrate = $request->input('bitrate');
        }

        $device->save();

        $pendingCommand = $device->remote_command;
        // Si el cliente leyó el comando, podemos limpiarlo para que no se reejecute
        if ($pendingCommand && $pendingCommand !== 'blocked') {
            $device->remote_command = null;
            $device->save();
        }

        return response()->json([
            'status' => 'success',
            'is_blocked' => $device->is_blocked,
            'remote_command' => $pendingCommand,
        ]);
    }

    /**
     * Re-sembrar catálogo de dispositivos de demostración con telemetría realista On-Net.
     * POST /api/admin/devices/seed-demo
     */
    public function seedDemo(): JsonResponse
    {
        $this->seedDemoDevices(true);

        return response()->json([
            'status' => 'success',
            'message' => 'Dispositivos de demostración restablecidos con éxito.',
        ]);
    }

    /**
     * Generador de datos simulados realistas basados en el catálogo local de FlixHN.
     */
    protected function seedDemoDevices(bool $force = false): void
    {
        if ($force) {
            DeviceSession::truncate();
        }

        $userAdmin = User::where('role', 'admin')->first();
        $userSubscriber = User::where('role', 'subscriber')->first();

        // Obtener títulos reales de la base de datos
        $sampleTitles = Title::take(6)->get();
        $title1 = $sampleTitles->get(0);
        $title2 = $sampleTitles->get(1);
        $title3 = $sampleTitles->get(2);
        $title4 = $sampleTitles->get(3);
        $title5 = $sampleTitles->get(4);

        $demoDevices = [
            [
                'device_id' => 'dev-samsung-tv-sala-4k',
                'custom_name' => 'Smart TV 65" Sala - Familia Pérez',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-8921-HN',
                'device_type' => 'smart_tv',
                'os_name' => 'Samsung Tizen OS 7.0',
                'browser_name' => 'Samsung Smart TV v5.2',
                'ip_address' => '192.168.10.45',
                'current_media_type' => 'movie',
                'current_media_title' => $title5 ? $title5->name : 'Soy leyenda (2007)',
                'current_media_poster' => $title5 ? $title5->poster_url : null,
                'playback_position_seconds' => 4210,
                'media_duration_seconds' => 6060,
                'playback_state' => 'playing',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subMinutes(70),
                'last_heartbeat_at' => Carbon::now()->subSeconds(15),
                'user_agent' => 'Mozilla/5.0 (SMART-TV; Linux; Tizen 7.0) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/5.2 TV Safari/537.36',
                'screen_resolution' => '3840x2160 (4K UHD HDR)',
                'connection_type' => 'Fibra Óptica GPON (On-Net 300 Mbps)',
                'bitrate' => '14.2 Mbps (HEVC 4K)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-apple-tv-dormitorio',
                'custom_name' => 'Apple TV 4K - Dormitorio Principal',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-8921-HN',
                'device_type' => 'smart_tv',
                'os_name' => 'Apple tvOS 18.1',
                'browser_name' => 'FlixHN tvOS Player',
                'ip_address' => '192.168.10.60',
                'current_media_type' => 'livetv',
                'current_media_title' => 'CH 12 - ESPN Deportes HD',
                'current_media_poster' => null,
                'playback_position_seconds' => 5400,
                'media_duration_seconds' => 7200,
                'playback_state' => 'playing',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subMinutes(90),
                'last_heartbeat_at' => Carbon::now()->subSeconds(8),
                'user_agent' => 'FlixHN/2.4.0 (AppleTV; tvOS 18.1; Scale/2.00)',
                'screen_resolution' => '1920x1080 @ 60fps',
                'connection_type' => 'Ethernet LAN 1 Gbps',
                'bitrate' => '8.5 Mbps (HLS Direct Play)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-xiaomi-mibox-kids',
                'custom_name' => 'Android TV Xiaomi Box - Habitación Niños',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-7110-HN',
                'device_type' => 'smart_tv',
                'os_name' => 'Android TV 12',
                'browser_name' => 'FlixHN Android TV App',
                'ip_address' => '192.168.10.88',
                'current_media_type' => 'movie',
                'current_media_title' => $title1 ? $title1->name : 'Cainui huye',
                'current_media_poster' => $title1 ? $title1->poster_url : null,
                'playback_position_seconds' => 1950,
                'media_duration_seconds' => 5400,
                'playback_state' => 'paused',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subMinutes(35),
                'last_heartbeat_at' => Carbon::now()->subMinutes(2),
                'user_agent' => 'Mozilla/5.0 (Linux; U; Android TV 12; MiBOX S) AppleWebKit/537.36',
                'screen_resolution' => '1920x1080 (FHD)',
                'connection_type' => 'WiFi 5GHz (Señal Excelente -48dBm)',
                'bitrate' => '5.4 Mbps (1080p H.264)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-iphone-16-pro-caceres',
                'custom_name' => 'iPhone 16 Pro Max - Fernando Cáceres',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-8921-HN',
                'device_type' => 'ios',
                'os_name' => 'iOS 18.2',
                'browser_name' => 'Mobile Safari 18',
                'ip_address' => '192.168.10.105',
                'current_media_type' => 'movie',
                'current_media_title' => $title4 ? $title4->name : 'Human Vol. 3',
                'current_media_poster' => $title4 ? $title4->poster_url : null,
                'playback_position_seconds' => 2800,
                'media_duration_seconds' => 6400,
                'playback_state' => 'playing',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subMinutes(46),
                'last_heartbeat_at' => Carbon::now()->subSeconds(22),
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
                'screen_resolution' => '2868x1320 (Retina Display)',
                'connection_type' => 'WiFi 6 On-Net (MIMO 2x2)',
                'bitrate' => '6.8 Mbps (HLS Adaptativo)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-pc-gamer-estudio',
                'custom_name' => 'PC Gamer Estudio / Master - Windows 11',
                'user_id' => $userAdmin?->id,
                'client_identifier' => 'ADMIN-NOC-01',
                'device_type' => 'desktop',
                'os_name' => 'Windows 11 Pro 64-bit',
                'browser_name' => 'Google Chrome 130',
                'ip_address' => '10.20.1.15',
                'current_media_type' => 'movie',
                'current_media_title' => $title3 ? $title3->name : 'Human Vol. 2',
                'current_media_poster' => $title3 ? $title3->poster_url : null,
                'playback_position_seconds' => 5120,
                'media_duration_seconds' => 7200,
                'playback_state' => 'playing',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subHours(2),
                'last_heartbeat_at' => Carbon::now()->subSeconds(5),
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'screen_resolution' => '2560x1440 (2K QHD 144Hz)',
                'connection_type' => 'Fibra Óptica Dedicada (Ethernet 10GbE)',
                'bitrate' => '18.5 Mbps (Direct Play Remux)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-macbook-pro-m3',
                'custom_name' => 'MacBook Pro M3 Max - Terraza Social',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-8921-HN',
                'device_type' => 'desktop',
                'os_name' => 'macOS Sonoma 14.5',
                'browser_name' => 'Apple Safari 17.5',
                'ip_address' => '192.168.10.142',
                'current_media_type' => 'livetv',
                'current_media_title' => 'CH 05 - Canal 5 El Líder HD',
                'current_media_poster' => null,
                'playback_position_seconds' => 1200,
                'media_duration_seconds' => 3600,
                'playback_state' => 'paused',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subMinutes(20),
                'last_heartbeat_at' => Carbon::now()->subMinutes(4),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
                'screen_resolution' => '3024x1964 (Liquid Retina XDR)',
                'connection_type' => 'WiFi 6E 6GHz (866 Mbps)',
                'bitrate' => '6.0 Mbps (HLS 1080p)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-ipad-pro-m2',
                'custom_name' => 'iPad Pro 12.9" - Sala de Estar',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-7110-HN',
                'device_type' => 'ios',
                'os_name' => 'iPadOS 18.0',
                'browser_name' => 'Mobile Safari',
                'ip_address' => '192.168.10.155',
                'current_media_type' => 'idle',
                'current_media_title' => 'En espera (Menú Principal)',
                'current_media_poster' => null,
                'playback_position_seconds' => 0,
                'media_duration_seconds' => 0,
                'playback_state' => 'stopped',
                'is_blocked' => false,
                'session_started_at' => Carbon::now()->subHours(5),
                'last_heartbeat_at' => Carbon::now()->subMinutes(12),
                'user_agent' => 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15',
                'screen_resolution' => '2732x2048',
                'connection_type' => 'WiFi 5GHz',
                'bitrate' => 'Inactivo (0 Kbps)',
                'remote_command' => null,
            ],
            [
                'device_id' => 'dev-tv-lg-oled-huespedes',
                'custom_name' => 'Smart TV LG OLED 55" - Cuarto Huéspedes',
                'user_id' => $userSubscriber?->id,
                'client_identifier' => 'CONTRATO-9934-HN',
                'device_type' => 'smart_tv',
                'os_name' => 'LG webOS 23',
                'browser_name' => 'LG Content Player',
                'ip_address' => '192.168.10.199',
                'current_media_type' => 'idle',
                'current_media_title' => 'Acceso Denegado por Administrador',
                'current_media_poster' => null,
                'playback_position_seconds' => 0,
                'media_duration_seconds' => 0,
                'playback_state' => 'stopped',
                'is_blocked' => true,
                'session_started_at' => Carbon::now()->subDays(1),
                'last_heartbeat_at' => Carbon::now()->subMinutes(30),
                'user_agent' => 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.5359.215 Safari/537.36 WebAppManager',
                'screen_resolution' => '3840x2160 (4K)',
                'connection_type' => 'WiFi 2.4GHz (Bloqueado por firewall MAC/IP)',
                'bitrate' => '0 Kbps (Tráfico suspendido)',
                'remote_command' => 'blocked',
            ],
        ];

        foreach ($demoDevices as $data) {
            DeviceSession::updateOrCreate(['device_id' => $data['device_id']], $data);
        }
    }
}
