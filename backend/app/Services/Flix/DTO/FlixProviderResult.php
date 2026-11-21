<?php

namespace AppServicesFlixDTO;

/**
 * DTO portado de plugin.video.flix (lib/api/flix/provider.py::ProviderResult).
 */
class FlixProviderResult
{
    public function __construct(
        public string $provider,
        public string $label,
        public string $label2 = '',
        public ?string $icon = null,
        public ?string $url = null,
        public mixed $providerData = null,
        public string $playbackType = 'LOCAL-HLS',
        public bool $requiresExternalApp = false,
        public ?string $appUrl = null,
        public ?string $webUrl = null,
        public array $metadata = [],
    ) {}

    public function isValid(): bool
    {
        return !empty($this->url) || $this->providerData !== null;
    }

    public function needsResolve(): bool
    {
        return empty($this->url) && $this->providerData !== null;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'label2' => $this->label2,
            'icon' => $this->icon,
            'url' => $this->url,
            'provider_data' => $this->providerData,
            'needs_resolve' => $this->needsResolve(),
            'playback_type' => $this->playbackType,
            'requires_external_app' => $this->requiresExternalApp,
            'app_url' => $this->appUrl,
            'web_url' => $this->webUrl,
            'metadata' => $this->metadata,
        ];
    }
}
