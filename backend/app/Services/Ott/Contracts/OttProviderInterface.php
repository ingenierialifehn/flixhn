<?php

namespace App\Services\Ott\Contracts;

interface OttProviderInterface
{
    /**
     * Identificador único (slug) del proveedor (ej. 'local', 'netflix', 'disney', 'max', 'partner_hls').
     */
    public function getSlug(): string;

    /**
     * Nombre legible del proveedor.
     */
    public function getName(): string;

    /**
     * Tipo de integración ('internal_vod', 'authorized_hls', 'deep_link', 'partner_api', 'oauth2').
     */
    public function getIntegrationType(): string;

    /**
     * Búsqueda unificada en el catálogo del proveedor.
     *
     * @param string $query Término de búsqueda
     * @param array $options Opciones adicionales (tipo, límite, etc.)
     * @return array Lista normalizada de resultados
     */
    public function search(string $query, array $options = []): array;

    /**
     * Obtiene el enlace o mecanismo oficial de reproducción para un contenido.
     *
     * @param string $contentId Identificador del contenido
     * @param array $context Contexto adicional (perfil, IP, dispositivo)
     * @return array Información estructurada de reproducción (playback_type, stream_url, app_url, web_url, etc.)
     */
    public function getStreamLink(string $contentId, array $context = []): array;

    /**
     * Verifica la disponibilidad, salud y estado de conexión del proveedor.
     *
     * @return array ['success' => bool, 'status' => string, 'latency_ms' => float, 'message' => string]
     */
    public function checkHealth(): array;
}
