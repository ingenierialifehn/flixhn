<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * Maneja una solicitud entrante y verifica la autenticidad de la clave API.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$requiredScopes
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$requiredScopes): Response
    {
        // 1. Extraer la clave API desde encabezados o query parameters
        $rawKey = $this->extractKey($request);

        if (empty($rawKey)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Acceso denegado. Se requiere una clave API válida vía header "X-Api-Key" o parámetro "?api_key=".',
                'error_code' => 'API_KEY_MISSING',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Localizar la clave en base de datos
        $apiKey = ApiKey::where('key', $rawKey)->first();

        if (!$apiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Clave API no válida o inexistente en FlixHN.',
                'error_code' => 'API_KEY_INVALID',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 3. Validar estado de activación
        if (!$apiKey->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'La clave API ha sido revocada o suspendida por el administrador.',
                'error_code' => 'API_KEY_REVOKED',
            ], Response::HTTP_FORBIDDEN);
        }

        // 4. Validar fecha de expiración
        if ($apiKey->isExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'La clave API ha expirado.',
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'error_code' => 'API_KEY_EXPIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        // 5. Validar alcances requeridos si se especifican en la ruta
        if (!empty($requiredScopes)) {
            foreach ($requiredScopes as $scope) {
                if (!$apiKey->hasScope($scope)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La clave API no posee los permisos requeridos para este endpoint (requiere: {$scope}).",
                        'error_code' => 'INSUFFICIENT_SCOPES',
                    ], Response::HTTP_FORBIDDEN);
                }
            }
        }

        // 6. Actualizar fecha de último uso sin alterar marcas de tiempo estándar
        try {
            $apiKey->timestamps = false;
            $apiKey->last_used_at = now();
            $apiKey->save();
            $apiKey->timestamps = true;
        } catch (\Throwable $e) {
            // No interrumpir la petición si falla la actualización del timestamp
        }

        // 7. Inyectar el modelo en la petición
        $request->attributes->set('api_key', $apiKey);

        if ($apiKey->user_id && $apiKey->user) {
            $request->setUserResolver(fn () => $apiKey->user);
        }

        return $next($request);
    }

    /**
     * Extrae el token de la solicitud por orden de prioridad
     */
    protected function extractKey(Request $request): ?string
    {
        // 1. Header estándar X-Api-Key
        $header = $request->header('X-Api-Key');
        if (!empty($header)) {
            return trim($header);
        }

        // 2. Header alternativo minúsculas
        $headerLower = $request->header('x-api-key');
        if (!empty($headerLower)) {
            return trim($headerLower);
        }

        // 3. Header de Autorización: ApiKey <token> o Bearer <token>
        $authHeader = $request->header('Authorization');
        if (!empty($authHeader)) {
            if (preg_match('/^ApiKey\s+(.+)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }

        // 4. Parámetro query ?api_key= o ?apiKey=
        $queryKey = $request->query('api_key') ?? $request->query('apiKey');
        if (!empty($queryKey)) {
            return trim($queryKey);
        }

        // 5. Cuerpo JSON o Formulario
        $bodyKey = $request->input('api_key') ?? $request->input('apiKey');
        if (!empty($bodyKey) && is_string($bodyKey)) {
            return trim($bodyKey);
        }

        return null;
    }
}
