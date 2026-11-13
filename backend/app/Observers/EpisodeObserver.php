<?php

namespace App\Observers;

use App\Models\Episode;
use App\Models\Title;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Log;

class EpisodeObserver
{
    protected MediaIntegrityService $integrityService;

    public function __construct(MediaIntegrityService $integrityService)
    {
        $this->integrityService = $integrityService;
    }

    /**
     * Maneja el evento de eliminación ('deleting') de Episode.
     */
    public function deleting(Episode $episode): void
    {
        Log::info("EpisodeObserver@deleting: Purgando registros asociados de episodio [ID {$episode->id}: {$episode->title}]");
        $this->integrityService->purgeAssociatedData('episode', $episode->id);
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de Episode.
     * Si la serie padre se queda sin episodios válidos, purga la serie automáticamente.
     */
    public function deleted(Episode $episode): void
    {
        $titleId = $episode->title_id;
        if ($titleId) {
            $remainingEpisodes = Episode::where('title_id', $titleId)->count();
            if ($remainingEpisodes === 0) {
                $parentSeries = Title::find($titleId);
                if ($parentSeries && $parentSeries->type === 'series') {
                    Log::warning("EpisodeObserver@deleted: Serie padre [ID {$titleId}: {$parentSeries->name}] sin episodios. Purgando serie huérfana.");
                    $this->integrityService->purgeTitle($parentSeries);
                }
            }
        }

        $this->integrityService->clearCatalogCache();
    }
}

