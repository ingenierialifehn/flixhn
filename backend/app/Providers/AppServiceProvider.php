<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Title;
use App\Models\Episode;
use App\Models\TvChannel;
use App\Models\MediaItem;
use App\Observers\TitleObserver;
use App\Observers\EpisodeObserver;
use App\Observers\MediaItemObserver;
use App\Observers\TvChannelObserver;
use App\Observers\MediaObserver;
use App\Observers\ChannelObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Observers para auto-purga en cascada e invalidación de caché
        Title::observe(TitleObserver::class);
        Episode::observe(EpisodeObserver::class);
        MediaItem::observe(MediaItemObserver::class);
        TvChannel::observe(TvChannelObserver::class);

        // Alias retrocompatibles
        Title::observe(MediaObserver::class);
        MediaItem::observe(MediaObserver::class);
        TvChannel::observe(ChannelObserver::class);
    }
}
