<?php

namespace App\Observers;

use App\Models\Title;
use App\Models\MediaItem;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Log;

class MediaObserver
{
    protected MediaIntegrityService $integrityService;

    public function __construct(MediaIntegrityService $integrityService)
    {
        $this->integrityService = $integrityService;
    }

    /**
     * Maneja el evento de eliminación ('deleting') de Title.
     */
    public function deleting($media): void
    {
        if ($media instanceof Title) {
            Log::info("MediaObserver@deleting: Purgando registros asociados de título [ID {$media->id}: {$media->name}]");
            $this->integrityService->purgeAssociatedData('title', $media->id, $media->name);

            // Si es serie, eliminar temporadas y episodios asociados
            if ($media->type === 'series') {
                foreach ($media->episodes as $episode) {
                    $episode->delete();
                }
                $media->seasons()->delete();
            }
        } elseif ($media instanceof MediaItem) {
            if ($media->title_id) {
                $this->integrityService->purgeAssociatedData('title', $media->title_id, $media->title);
            }
        }
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de Title.
     */
    public function deleted($media): void
    {
        Log::info("MediaObserver@deleted: Limpiando caché de catálogo tras eliminación de medio.");
        $this->integrityService->clearCatalogCache();
    }
}

