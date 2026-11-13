<?php

namespace App\Observers;

use App\Models\Title;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Log;

class TitleObserver
{
    protected MediaIntegrityService $integrityService;

    public function __construct(MediaIntegrityService $integrityService)
    {
        $this->integrityService = $integrityService;
    }

    /**
     * Maneja el evento de eliminación ('deleting') de Title.
     * Elimina en cascada todos los registros en playback_progress, watch_history,
     * favoritos, tareas de descarga y temporadas/episodios dependientes.
     */
    public function deleting(Title $title): void
    {
        Log::info("TitleObserver@deleting: Purgando registros asociados y progreso del título [ID {$title->id}: {$title->name}]");
        $this->integrityService->purgeAssociatedData('title', $title->id, $title->name);

        // Si es serie, eliminar temporadas y episodios asociados
        if ($title->type === 'series') {
            foreach ($title->episodes as $episode) {
                $episode->delete();
            }
            $title->seasons()->delete();
        }
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de Title.
     * Invalida inmediatamente la caché de catálogo.
     */
    public function deleted(Title $title): void
    {
        Log::info("TitleObserver@deleted: Limpiando caché de catálogo tras eliminación de título [ID {$title->id}].");
        $this->integrityService->clearCatalogCache();
    }
}

