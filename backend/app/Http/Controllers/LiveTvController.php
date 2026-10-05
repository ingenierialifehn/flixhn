<?php

namespace App\Http\Controllers;

use App\Models\TvChannel;
use App\Models\TvSource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

use App\Services\M3uParserService;

class LiveTvController extends Controller
{
    /**
     * Lista canales para la vista de TV en Vivo (/livetv).
     * Devuelve únicamente los canales persistidos en la tabla tv_channels.
     */
    public function getChannels(Request $request): JsonResponse
    {
        return response()->json(
            TvChannel::where('is_active', true)
                ->orderByRaw('CAST(channel_number AS UNSIGNED) ASC')
                ->orderBy('name', 'asc')
                ->get()
        );
    }

    /**
     * Alias para retrocompatibilidad
     */
    public function channels(Request $request): JsonResponse
    {
        return $this->getChannels($request);
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
