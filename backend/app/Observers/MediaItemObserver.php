<?php

namespace App\Observers;

use App\Models\MediaItem;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Log;

class MediaItemObserver
{
    protected MediaIntegrityService $integrityService;

    public function __construct(MediaIntegrityService $integrityService)
    {
        $this->integrityService = $integrityService;
    }

    /**
     * Maneja el evento de eliminación ('deleting') de MediaItem.
     */
    public function deleting(MediaItem $item): void
    {
        Log::info("MediaItemObserver@deleting: Purgando registros asociados de MediaItem [ID {$item->id}: {$item->title}]");
        if ($item->title_id) {
            $this->integrityService->purgeAssociatedData('title', $item->title_id, $item->title);
        }
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de MediaItem.
     */
    public function deleted(MediaItem $item): void
    {
        Log::info("MediaItemObserver@deleted: Limpiando caché tras eliminación de MediaItem [ID {$item->id}].");
        $this->integrityService->clearCatalogCache();
    }
}

