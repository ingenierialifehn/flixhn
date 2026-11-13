<?php

namespace App\Observers;

use App\Models\TvChannel;
use App\Models\DeviceSession;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TvChannelObserver
{
    protected MediaIntegrityService $integrityService;

    public function __construct(MediaIntegrityService $integrityService)
    {
        $this->integrityService = $integrityService;
    }

    /**
     * Maneja el evento de eliminación ('deleting') de TvChannel.
     */
    public function deleting(TvChannel $channel): void
    {
        Log::info("TvChannelObserver@deleting: Purgando sesiones activas de canal [ID {$channel->id}: {$channel->name}]");
        if (Schema::hasTable('device_sessions')) {
            DeviceSession::where('channel_id', $channel->id)
                ->orWhere('media_id', $channel->id)
                ->delete();
        }
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de TvChannel.
     */
    public function deleted(TvChannel $channel): void
    {
        Log::info("TvChannelObserver@deleted: Limpiando caché de canales de TV en vivo tras eliminación de [ID {$channel->id}].");
        Cache::forget('livetv_channels_list');
        Cache::forget('emby_livetv_channels_map');
        $this->integrityService->clearCatalogCache();
    }
}

