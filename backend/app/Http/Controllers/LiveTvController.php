<?php

namespace App\Http\Controllers;

use App\Models\TvChannel;
use App\Models\TvSource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveTvController extends Controller
{
    /**
     * Lista canales agrupados por categoría para el Portal de Usuarios (/livetv).
     */
    public function channels(Request $request): JsonResponse
    {
        $query = TvChannel::where('is_active', true)
            ->whereHas('tvSource', function ($q) {
                $q->where('is_active', true);
            });

        // Búsqueda por término
        if ($request->filled('q')) {
            $search = '%' . trim($request->q) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('group_title', 'like', $search)
                  ->orWhere('channel_number', 'like', $search);
            });
        }

        // Filtro específico por categoría
        if ($request->filled('category') && strtolower($request->category) !== 'todos' && strtolower($request->category) !== 'all') {
            $query->where('group_title', $request->category);
        }

        // Lista de canales
        $channels = $query->orderBy('group_title')
            ->orderByRaw('CAST(channel_number AS UNSIGNED) ASC, name ASC')
            ->get();

        // Categorías disponibles con conteos
        $categoriesQuery = TvChannel::where('is_active', true)
            ->whereHas('tvSource', function ($q) {
                $q->where('is_active', true);
            })
            ->selectRaw('group_title, count(*) as count')
            ->groupBy('group_title')
            ->orderBy('group_title')
            ->get();

        $totalActive = $categoriesQuery->sum('count');

        $categories = [
            [
                'id' => 'all',
                'name' => 'Todos los Canales',
                'count' => $totalActive,
            ],
        ];

        foreach ($categoriesQuery as $cat) {
            $categories[] = [
                'id' => $cat->group_title,
                'name' => $cat->group_title,
                'count' => $cat->count,
            ];
        }

        return response()->json([
            'status' => 'success',
            'categories' => $categories,
            'channels' => $channels,
            'total_channels' => $totalActive,
        ]);
    }

    /**
     * Proxy de streaming HLS/TS para evitar problemas de CORS o contenido mixto en el navegador.
     */
    public function proxy(Request $request)
    {
        $targetUrl = $request->query('url');
        if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'URL no válida o vacía'], 400);
        }

        try {
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => '*/*',
            ];

            // Realizar solicitud con timeout
            $client = Http::withHeaders($headers)->timeout(15);
            $response = $client->get($targetUrl);

            $contentType = $response->header('Content-Type') ?: 'application/vnd.apple.mpegurl';
            $body = $response->body();

            // Si es un playlist M3U8, reescribir URLs relativas a absolutas si es necesario
            if (str_contains($contentType, 'mpegurl') || str_ends_with($targetUrl, '.m3u8')) {
                $baseUrl = substr($targetUrl, 0, strrpos($targetUrl, '/') + 1);
                $lines = explode("\n", $body);
                $rewritten = [];
                foreach ($lines as $line) {
                    $trim = trim($line);
                    if (!empty($trim) && !str_starts_with($trim, '#')) {
                        if (!filter_var($trim, FILTER_VALIDATE_URL)) {
                            $trim = $baseUrl . $trim;
                        }
                    }
                    $rewritten[] = $trim;
                }
                $body = implode("\n", $rewritten);
            }

            return response($body, $response->status(), [
                'Content-Type' => $contentType,
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => '*',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al conectar con la fuente de transmisión',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}
