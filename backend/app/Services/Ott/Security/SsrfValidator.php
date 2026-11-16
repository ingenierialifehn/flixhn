<?php

namespace App\Services\Ott\Security;

use Illuminate\Support\Facades\Log;

class SsrfValidator
{
    /**
     * Rangos IPv4 privados y reservados según RFC 1918, RFC 3927, RFC 5735, RFC 1122.
     */
    protected static array $privateRanges = [
        '0.0.0.0/8',       // Red actual ("esta" red)
        '10.0.0.0/8',      // Red privada clase A
        '127.0.0.0/8',     // Loopback
        '169.254.0.0/16',  // Link-local / APIPA (crítico para metadatos de Cloud)
        '172.16.0.0/12',   // Red privada clase B
        '192.168.0.0/16',  // Red privada clase C
        '224.0.0.0/4',     // Multicast
        '240.0.0.0/4',     // Reservado para uso futuro
        '255.255.255.255/32', // Broadcast
    ];

    /**
     * Valida si una URL es segura contra Server-Side Request Forgery (SSRF).
     *
     * @param string $url URL a validar
     * @param bool $allowInternalAllowlist Si se permiten los hosts expresamente configurados para orígenes locales
     * @return array ['safe' => bool, 'url' => string, 'reason' => ?string, 'resolved_ip' => ?string]
     */
    public static function validate(string $url, bool $allowInternalAllowlist = true): array
    {
        $trimmed = trim($url);
        if (empty($trimmed)) {
            return ['safe' => false, 'url' => $url, 'reason' => 'URL vacía', 'resolved_ip' => null];
        }

        $parts = parse_url($trimmed);
        if (!$parts || !isset($parts['scheme']) || !isset($parts['host'])) {
            return ['safe' => false, 'url' => $url, 'reason' => 'Formato de URL no válido o incompleto', 'resolved_ip' => null];
        }

        // 1. Validar esquema: solo HTTP y HTTPS
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['safe' => false, 'url' => $url, 'reason' => "Esquema no permitido: {$scheme}", 'resolved_ip' => null];
        }

        $host = strtolower($parts['host']);

        // 2. Comprobar lista de permitidos internos expresos (ej. CDN interno o media server local)
        if ($allowInternalAllowlist && self::isHostInAllowlist($host)) {
            return ['safe' => true, 'url' => $url, 'reason' => 'Permitido por lista de confianza interna', 'resolved_ip' => null];
        }

        // 3. Bloqueo de nombres de host peligrosos o loopback explícitos
        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', '127.0.0.1', '::1'], true)) {
            return ['safe' => false, 'url' => $url, 'reason' => "Destino loopback bloqueado: {$host}", 'resolved_ip' => '127.0.0.1'];
        }

        // 4. Resolución DNS de direcciones IP
        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
        $resolvedIps = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $resolvedIps[] = $host;
        } elseif (is_array($ips) && !empty($ips)) {
            foreach ($ips as $record) {
                if (!empty($record['ip'])) {
                    $resolvedIps[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        } else {
            // Intento con gethostbyname
            $ghbn = @gethostbyname($host);
            if ($ghbn && $ghbn !== $host && filter_var($ghbn, FILTER_VALIDATE_IP)) {
                $resolvedIps[] = $ghbn;
            }
        }

        if (empty($resolvedIps)) {
            // Si no se puede resolver en DNS, considerar potencialmente peligroso o inalcanzable
            return ['safe' => false, 'url' => $url, 'reason' => "No se pudo resolver el host: {$host}", 'resolved_ip' => null];
        }

        // 5. Validar cada IP resuelta contra rangos privados
        foreach ($resolvedIps as $ip) {
            if (self::isPrivateIp($ip)) {
                Log::warning("SSRF bloqueado hacia host {$host} con IP privada resuelta {$ip}");
                return [
                    'safe' => false,
                    'url' => $url,
                    'reason' => "La dirección IP resuelta ({$ip}) pertenece a un rango privado o reservado",
                    'resolved_ip' => $ip,
                ];
            }
        }

        return [
            'safe' => true,
            'url' => $url,
            'reason' => null,
            'resolved_ip' => $resolvedIps[0] ?? null,
        ];
    }

    /**
     * Determina si una IP es privada, loopback o reservada.
     */
    public static function isPrivateIp(string $ip): bool
    {
        // Validación con flags estándar de PHP
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            foreach (self::$privateRanges as $range) {
                [$net, $mask] = explode('/', $range);
                $netLong = ip2long($net);
                $maskLong = ~((1 << (32 - (int)$mask)) - 1);
                if (($long & $maskLong) === ($netLong & $maskLong)) {
                    return true;
                }
            }
        }

        // Verificación IPv6 loopback y local
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc00:') || str_starts_with($lower, 'fd00:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si el host está en la lista de permitidos internos para el ISP.
     */
    protected static function isHostInAllowlist(string $host): bool
    {
        $allowed = config('services.ott.ssrf_allowlist', [
            'localhost:7000',
            'flixhn_backend',
            'flixhn_nginx',
            'flixhn_media',
            'api.netflix.com',
            'api.disneyplus.com',
            'api.max.com',
            'api.primevideo.com',
            'image.tmdb.org',
            'api.themoviedb.org',
        ]);

        $envAllowed = env('OTT_SSRF_ALLOWED_HOSTS');
        if (!empty($envAllowed)) {
            $extra = array_map('trim', explode(',', $envAllowed));
            $allowed = array_merge($allowed, $extra);
        }

        foreach ($allowed as $item) {
            $item = strtolower(trim($item));
            if ($host === $item || str_ends_with($host, '.' . $item)) {
                return true;
            }
        }

        return false;
    }
}
