<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Flix Engine Integration (Port de plugin.video.flix para FlixHN)
    |--------------------------------------------------------------------------
    |
    | Configuración central del motor de metadatos TMDB, arquitectura de
    | proveedores en dos fases (search + lazy resolve), sincronización de
    | biblioteca (películas/series/episodios), historial de búsqueda y
    | subtítulos vía OpenSubtitles REST API v1.
    |
    */

    'tmdb' => [
        'base_url' => env('FLIX_TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
        'image_base_url' => env('FLIX_TMDB_IMAGE_URL', 'https://image.tmdb.org/t/p/'),
        'api_key' => env('TMDB_API_KEY', env('FLIX_TMDB_API_KEY', '')),
        'language' => env('FLIX_TMDB_LANGUAGE', 'es-ES'),
        'fallback_language' => env('FLIX_TMDB_FALLBACK_LANGUAGE', 'en-US'),
        'include_adult' => (bool) env('FLIX_TMDB_INCLUDE_ADULT', false),
        'prefer_original_titles' => (bool) env('FLIX_TMDB_PREFER_ORIGINAL', false),
        'cache_enabled' => (bool) env('FLIX_TMDB_CACHE_ENABLED', true),
        'cache_ttl_days' => (int) env('FLIX_TMDB_CACHE_DAYS', 7),
        'timeout' => (int) env('FLIX_TMDB_TIMEOUT', 10),
    ],

    'providers' => [
        'search_timeout' => (int) env('FLIX_PROVIDERS_TIMEOUT', 10),
        'resolve_timeout' => (int) env('FLIX_RESOLVE_TIMEOUT', 10),
        'auto_choose_media' => (bool) env('FLIX_AUTO_CHOOSE_MEDIA', false),
        'save_last_result' => (bool) env('FLIX_SAVE_LAST_RESULT', true),
        'replay_ttl_minutes' => (int) env('FLIX_REPLAY_TTL_MINUTES', 180),
    ],

    'library' => [
        'include_specials' => (bool) env('FLIX_LIBRARY_INCLUDE_SPECIALS', false),
        'include_unaired_episodes' => (bool) env('FLIX_LIBRARY_INCLUDE_UNAIRED', false),
        'auto_update_enabled' => (bool) env('FLIX_LIBRARY_AUTO_UPDATE', true),
    ],

    'opensubtitles' => [
        'base_url' => env('FLIX_OS_BASE_URL', 'https://api.opensubtitles.com/api/v1'),
        'api_key' => env('OPENSUBTITLES_API_KEY', env('FLIX_OS_API_KEY', '')),
        'username' => env('OPENSUBTITLES_USERNAME', env('FLIX_OS_USERNAME', '')),
        'password' => env('OPENSUBTITLES_PASSWORD', env('FLIX_OS_PASSWORD', '')),
        'default_languages' => explode(',', env('FLIX_OS_LANGUAGES', 'es,en')),
        'storage_dir' => env('FLIX_OS_STORAGE_DIR', storage_path('app/subtitles')),
        'cache_ttl_minutes' => 30,
        'app_user_agent' => env('FLIX_OS_USER_AGENT', 'FlixHN-FlixEngine v1.0.0'),
    ],

    'search_history' => [
        'enabled' => (bool) env('FLIX_SEARCH_HISTORY_ENABLED', true),
        'page_size' => (int) env('FLIX_SEARCH_HISTORY_PAGE_SIZE', 20),
    ],
];
