<?php

namespace App\Observers;

use App\Models\TvChannel;
use App\Models\DeviceSession;
use App\Services\MediaIntegrityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChannelObserver
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
        Log::info("ChannelObserver@deleting: Purgando sesiones de canal [ID {$channel->id}: {$channel->name}]");
        if (Schema::hasTable('device_sessions')) {
            try {
                if (!empty($channel->name)) {
                    DeviceSession::where('current_media_title', $channel->name)->delete();
                }
            } catch (\Throwable $e) {
                Log::debug("ChannelObserver@deleting: Error al limpiar device_sessions: " . $e->getMessage());
            }
        }
    }

    /**
     * Maneja el evento post-eliminación ('deleted') de TvChannel.
     */
    public function deleted(TvChannel $channel): void
    {
        Log::info("ChannelObserver@deleted: Limpiando caché de canales de TV en vivo.");
        Cache::forget('livetv_channels_list');
        Cache::forget('emby_livetv_channels_map');
        $this->integrityService->clearCatalogCache();
    }
}

