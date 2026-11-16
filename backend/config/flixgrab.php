<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FlixGrab Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Parámetros centrales del motor de descargas y streaming progresivo FlixGrab.
    |
    */

    'daemon_url' => env('FLIXGRAB_DAEMON_URL', 'http://127.0.0.1:8001'),
    'timeout' => (int) env('FLIXGRAB_TIMEOUT', 15),
    'max_concurrent_downloads' => (int) env('FLIXGRAB_MAX_CONCURRENT', 3),
    'min_free_space_mb' => (int) env('FLIXGRAB_MIN_FREE_SPACE_MB', 500),

    // Rutas de almacenamiento local para archivos completos y segmentos HLS
    'storage_path' => env('FLIXGRAB_STORAGE_PATH', storage_path('media')),
    'hls_storage_path' => env('FLIXGRAB_HLS_PATH', storage_path('media/hls_live')),

    // Parámetros de reproducción progresiva HLS
    'hls_segment_duration' => (int) env('FLIXGRAB_HLS_SEGMENT_DURATION', 4),
    'min_segments_for_playback' => (int) env('FLIXGRAB_MIN_SEGMENTS_PLAY', 2),

    // Binarios multimedia
    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
];
