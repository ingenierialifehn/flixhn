<?php

namespace App\Http\Controllers;

use App\Models\ServerSetting;
use App\Models\Title;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * Retorna el listado de alertas reales del sistema basadas en telemetría y eventos.
     */
    public function index(): JsonResponse
    {
        $dismissed = ServerSetting::get('dismissed_alert_ids', []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        $alerts = [];

        // 1. Verificación en tiempo real del enlace con el Servidor de Medios Remoto
        $mediaUrl = ServerSetting::get('media_server_url', 'http://45.4.87.126:6789');
        $host = parse_url($mediaUrl, PHP_URL_HOST) ?: '45.4.87.126';
        $port = parse_url($mediaUrl, PHP_URL_PORT) ?: 6789;

        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 1.2);
        if (!$fp) {
            $alertId = 'alert-media-down';
            if (!in_array($alertId, $dismissed)) {
                $alerts[] = [
                    'id' => $alertId,
                    'level' => 'error',
                    'title' => 'Pérdida de Enlace con Servidor de Medios',
                    'message' => "No se pudo establecer conexión con el host de medios {$host}:{$port}. Compruebe la conectividad de red o la IP configurada.",
                    'time_ago' => 'En vivo',
                    'timestamp' => now()->toIso8601String(),
                ];
            }
        } else {
            @fclose($fp);
        }

        // 2. Notificación de sincronizaciones completadas
        $lastSync = ServerSetting::get('last_sync_at');
        if ($lastSync) {
            try {
                $syncDate = \Carbon\Carbon::parse($lastSync);
                $alertId = 'alert-sync-' . $syncDate->format('YmdH');
                if (!in_array($alertId, $dismissed)) {
                    $titlesCount = Title::count();
                    $alerts[] = [
                        'id' => $alertId,
                        'level' => 'success',
                        'title' => 'Sincronización de Catálogo Completada',
                        'message' => "La biblioteca multimedia fue sincronizada exitosamente. Total de títulos disponibles en el nodo: {$titlesCount}.",
                        'time_ago' => $syncDate->diffForHumans(),
                        'timestamp' => $syncDate->toIso8601String(),
                    ];
                }
            } catch (\Exception $e) {}
        }

        // 3. Consumo elevado de recursos (CPU > 85% o RAM > 90%)
        if (@file_exists('/proc/meminfo') && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $total);
                preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $avail);
                if (!empty($total[1]) && !empty($avail[1])) {
                    $ramPct = round((($total[1] - $avail[1]) / $total[1]) * 100);
                    if ($ramPct >= 90) {
                        $alertId = 'alert-high-ram';
                        if (!in_array($alertId, $dismissed)) {
                            $alerts[] = [
                                'id' => $alertId,
                                'level' => 'warning',
                                'title' => 'Consumo Elevado de Memoria RAM',
                                'message' => "El uso de memoria del nodo ha alcanzado el {$ramPct}%. Se recomienda optimizar procesos.",
                                'time_ago' => 'En vivo',
                                'timestamp' => now()->toIso8601String(),
                            ];
                        }
                    }
                }
            }
        }

        // Carga de CPU
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (isset($load[0]) && $load[0] > 6.0) { // Umbral de sobrecarga
                $alertId = 'alert-high-cpu';
                if (!in_array($alertId, $dismissed)) {
                    $alerts[] = [
                        'id' => $alertId,
                        'level' => 'warning',
                        'title' => 'Carga Elevada de CPU',
                        'message' => "La carga promedio de CPU se encuentra en {$load[0]}. Supera el umbral nominal.",
                        'time_ago' => 'En vivo',
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }
        }

        // 4. Nuevos abonados registrados recientemente
        $recentUsers = User::where('role', 'subscriber')
            ->where('created_at', '>=', now()->subHours(48))
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get();

        foreach ($recentUsers as $sub) {
            $alertId = 'alert-user-' . $sub->id;
            if (!in_array($alertId, $dismissed)) {
                $alerts[] = [
                    'id' => $alertId,
                    'level' => 'info',
                    'title' => 'Nuevo Suscriptor Conectado',
                    'message' => "Abonado '{$sub->customer_name}' ({$sub->username}) vinculado a la red local. IP asignada: " . ($sub->assigned_ip ?: 'Automática') . ".",
                    'time_ago' => $sub->created_at->diffForHumans(),
                    'timestamp' => $sub->created_at->toIso8601String(),
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'alerts' => $alerts,
            'total' => count($alerts),
        ]);
    }

    /**
     * Descarta / marca como leída una alerta específica.
     */
    public function dismiss(string $id): JsonResponse
    {
        $dismissed = ServerSetting::get('dismissed_alert_ids', []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        if (!in_array($id, $dismissed)) {
            $dismissed[] = $id;
            ServerSetting::set('dismissed_alert_ids', $dismissed, 'system', 'json');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Alerta descartada exitosamente.',
            'dismissed_id' => $id,
        ]);
    }

    /**
     * Descarta todas las alertas activas.
     */
    public function dismissAll(): JsonResponse
    {
        $res = $this->index();
        $data = $res->getData(true);
        $ids = array_column($data['alerts'] ?? [], 'id');

        $dismissed = ServerSetting::get('dismissed_alert_ids', []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        $merged = array_unique(array_merge($dismissed, $ids));
        ServerSetting::set('dismissed_alert_ids', $merged, 'system', 'json');

        return response()->json([
            'status' => 'success',
            'message' => 'Todas las alertas han sido descartadas.',
        ]);
    }
}
