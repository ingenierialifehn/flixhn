<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsAdminController extends Controller
{
    protected AnalyticsService $analytics;

    public function __construct(AnalyticsService $analytics)
    {
        $this->analytics = $analytics;
    }

    /**
     * Resumen de KPIs y métricas clave de telemetría y catálogo.
     * GET /api/admin/stats/overview?range=7d
     */
    public function overview(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getOverview($range, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Serie temporal de volumen de reproducciones y concurrencia.
     * GET /api/admin/stats/playback-chart?range=7d
     */
    public function playbackChart(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getPlaybackChart($range, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Top 10 Contenido Más Visto (Películas, Series, TV en Vivo).
     * GET /api/admin/stats/top-content?range=7d&limit=10
     */
    public function topContent(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getTopContent($range, $limit, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Top Usuarios / Contratos ISP Más Activos.
     * GET /api/admin/stats/top-users?range=7d&limit=10
     */
    public function topUsers(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getTopUsers($range, $limit, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Distribución de dispositivos clientes por OS, navegador y tipo.
     * GET /api/admin/stats/device-distribution?range=7d
     */
    public function deviceDistribution(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getDeviceDistribution($range, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Canales de TV en Vivo más sintonizados con duración promedio.
     * GET /api/admin/stats/live-tv?range=7d&limit=10
     */
    public function liveTvRanking(Request $request): JsonResponse
    {
        $range = $request->query('range', '7d');
        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $fresh = $request->boolean('fresh') || $request->boolean('refresh');

        $data = $this->analytics->getLiveTvRanking($range, $limit, $fresh);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Generar y descargar reporte consolidado en CSV o JSON.
     * GET /api/admin/stats/export?range=7d&format=csv
     */
    public function export(Request $request): Response
    {
        $range = $request->query('range', '7d');
        $format = strtolower($request->query('format', 'csv'));
        $dateStr = now()->format('Ymd_His');

        if ($format === 'json') {
            $overview = $this->analytics->getOverview($range, true);
            $topContent = $this->analytics->getTopContent($range, 20, true);
            $topUsers = $this->analytics->getTopUsers($range, 20, true);
            $devices = $this->analytics->getDeviceDistribution($range, true);
            $liveTv = $this->analytics->getLiveTvRanking($range, 15, true);

            $payload = [
                'generated_at' => now()->toIso8601String(),
                'server' => gethostname() ?: 'FlixHN-Node',
                'overview' => $overview,
                'top_content' => $topContent,
                'top_users' => $topUsers,
                'devices' => $devices,
                'live_tv' => $liveTv,
            ];

            return response()->json($payload, 200, [
                'Content-Disposition' => "attachment; filename=\"flixhn_stats_{$range}_{$dateStr}.json\"",
                'Content-Type' => 'application/json; charset=UTF-8',
            ]);
        }

        // CSV por defecto
        $csvContent = $this->analytics->exportCsv($range);

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"flixhn_stats_{$range}_{$dateStr}.csv\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
