<?php

namespace App\Services\Ott\Security;

class StreamTokenSigner
{
    /**
     * Clave secreta para la firma criptográfica de enlaces HLS/DASH.
     */
    protected static function getSecretKey(): string
    {
        return config('services.ott.stream_secret')
            ?: (config('app.key') ?: 'flixhn-streaming-hmac-key-default-salt-32bytes');
    }

    /**
     * Genera un token firmado HMAC-SHA256 para autorizar la reproducción de un flujo.
     *
     * @param string $contentId Identificador único del contenido
     * @param int $ttlSeconds Tiempo de validez del enlace en segundos (default 2 horas)
     * @param string|null $clientIp IP del cliente para vinculación estricta On-Net
     * @return array ['token' => string, 'expires_at' => int, 'expires_iso' => string]
     */
    public static function generateToken(string $contentId, int $ttlSeconds = 7200, ?string $clientIp = null): array
    {
        $expiresAt = time() + max(60, $ttlSeconds);
        $nonce = bin2hex(random_bytes(8));

        $payload = [
            'cid' => $contentId,
            'exp' => $expiresAt,
            'nonce' => $nonce,
        ];

        if (!empty($clientIp)) {
            $payload['ip'] = $clientIp;
        }

        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, self::getSecretKey());

        $token = "{$encodedPayload}.{$signature}";

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_iso' => date('c', $expiresAt),
        ];
    }

    /**
     * Valida un token de reproducción firmado.
     *
     * @param string $token Token en formato {payload}.{signature}
     * @param string|null $expectedContentId Identificador del contenido esperado
     * @param string|null $clientIp IP del cliente si se requiere validación de enlace IP
     * @return array ['valid' => bool, 'payload' => ?array, 'error' => ?string]
     */
    public static function verifyToken(string $token, ?string $expectedContentId = null, ?string $clientIp = null): array
    {
        if (empty($token) || !str_contains($token, '.')) {
            return ['valid' => false, 'payload' => null, 'error' => 'Formato de token inválido'];
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);

        $expectedSignature = hash_hmac('sha256', $encodedPayload, self::getSecretKey());
        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'payload' => null, 'error' => 'Firma digital de reproducción no válida'];
        }

        $decoded = base64_decode(strtr($encodedPayload, '-_', '+/'));
        if (!$decoded) {
            return ['valid' => false, 'payload' => null, 'error' => 'Payload no decodificable'];
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'payload' => null, 'error' => 'Estructura de payload corrupta'];
        }

        // Verificar expiración
        if (isset($payload['exp']) && time() > (int)$payload['exp']) {
            return ['valid' => false, 'payload' => $payload, 'error' => 'Token de reproducción expirado'];
        }

        // Verificar coincidencia de contenido
        if ($expectedContentId !== null && isset($payload['cid']) && (string)$payload['cid'] !== (string)$expectedContentId) {
            return ['valid' => false, 'payload' => $payload, 'error' => 'Token no corresponde al contenido solicitado'];
        }

        // Verificar vinculación de IP
        if ($clientIp !== null && !empty($payload['ip']) && $payload['ip'] !== $clientIp) {
            return ['valid' => false, 'payload' => $payload, 'error' => 'Discrepancia de dirección IP On-Net'];
        }

        return ['valid' => true, 'payload' => $payload, 'error' => null];
    }

    /**
     * Adjunta el token de autorización firmado a una URL de streaming.
     */
    public static function signUrl(string $url, string $contentId, int $ttlSeconds = 7200, ?string $clientIp = null): string
    {
        $tokenData = self::generateToken($contentId, $ttlSeconds, $clientIp);
        $glue = str_contains($url, '?') ? '&' : '?';
        return "{$url}{$glue}ott_token={$tokenData['token']}";
    }
}
