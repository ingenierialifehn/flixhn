<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\PlaybackController;
use App\Http\Controllers\AdminTitleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\NetworkSettingController;
use App\Http\Controllers\ServerControlController;
use App\Http\Controllers\MediaNodeController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\TvSourceController;
use App\Http\Controllers\TvGuideSourceController;
use App\Http\Controllers\LiveTvController;
use App\Http\Controllers\TranscodingSettingController;
use App\Http\Controllers\DatabaseAdminController;
use App\Http\Controllers\ScheduledTaskController;
use App\Http\Controllers\LogAdminController;
use App\Http\Controllers\DeviceSessionController;
use App\Http\Controllers\PluginAdminController;
use App\Http\Controllers\ApiKeyAdminController;
use App\Http\Controllers\MetadataManagerController;
use App\Http\Controllers\AnalyticsAdminController;
use App\Http\Controllers\OttProviderController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\AuthenticateApiKey;

/*
|--------------------------------------------------------------------------
| Rutas Públicas (Sin Autenticación)
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'status' => 'online',
        'service' => 'FlixHN Streaming API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/media/poster', function (\Illuminate\Http\Request $request) {
    $title = $request->query('title', 'FlixHN');
    $svg = app(\App\Services\MediaSyncService::class)->getSvgPosterContent($title);
    return response($svg, 200, [
        'Content-Type' => 'image/svg+xml; charset=utf-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

Route::get('/media/backdrop', function (\Illuminate\Http\Request $request) {
    $title = $request->query('title', 'FlixHN');
    $svg = app(\App\Services\MediaSyncService::class)->getSvgBackdropContent($title);
    return response($svg, 200, [
        'Content-Type' => 'image/svg+xml; charset=utf-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/login', [AuthController::class, 'login'])->name('login');

// Acceso público al portal Live TV y proxy de streaming
Route::get('/livetv/channels', [LiveTvController::class, 'getChannels']);
Route::get('/livetv/proxy', [LiveTvController::class, 'proxy']);

/*
|--------------------------------------------------------------------------
| Rutas Protegidas por Token Sanctum (Abonados y Administradores)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Autenticación y Perfiles
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/profiles', [AuthController::class, 'createProfile']);

    // Catálogo de Video y Streaming
    Route::get('/titles', [TitleController::class, 'index']);
    Route::get('/home', [TitleController::class, 'index']);
    Route::get('/browse', [TitleController::class, 'index']);
    Route::get('/titles/{id}', [TitleController::class, 'show']);

    // Sincronización de Reproducción (Continuar Viendo y Sesiones de Streaming)
    Route::get('/playback/info', [PlaybackController::class, 'getPlaybackInfo']);
    Route::post('/playback/progress', [PlaybackController::class, 'updateProgress']);
    Route::get('/playback/progress', [PlaybackController::class, 'getProgress']);
    Route::delete('/watch-history/{titleId}', [PlaybackController::class, 'destroy']);
    Route::delete('/playback/progress/{titleId}', [PlaybackController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Módulo Administrativo (/admin) - Solo Rol Admin
    |--------------------------------------------------------------------------
    */
    Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
        // Métricas en tiempo real y monitoreo de telemetría
        Route::get('/dashboard-stats', [AdminTitleController::class, 'dashboardStats']);
        Route::get('/stats', [AdminTitleController::class, 'dashboardStats']);
        Route::post('/sessions/{id}/stop', [AdminTitleController::class, 'stopSession']);

        // Gestión del Catálogo y Sincronización de Medios Remotos
        Route::post('/sync-media', [AdminTitleController::class, 'syncMedia']);
        Route::post('/media/sync', [AdminTitleController::class, 'syncRemoteMedia']);
        Route::post('/sync-emby', [AdminTitleController::class, 'syncEmby']);
        Route::get('/titles', [AdminTitleController::class, 'index']);
        Route::post('/titles', [AdminTitleController::class, 'store']);
        Route::put('/titles/{id}', [AdminTitleController::class, 'update']);
        Route::delete('/titles/{id}', [AdminTitleController::class, 'destroy']);
        Route::post('/titles/{id}/seasons', [AdminTitleController::class, 'addSeason']);
        Route::post('/titles/{id}/episodes', [AdminTitleController::class, 'addEpisode']);

        // Gestión de Suscriptores del ISP
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::post('/users/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);
        Route::post('/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);

        // Parámetros y Configuración de Red / Conexiones
        Route::get('/network', [NetworkSettingController::class, 'index']);
        Route::post('/network', [NetworkSettingController::class, 'updateSettings']);
        Route::get('/network-settings', [NetworkSettingController::class, 'index']);
        Route::post('/network-settings', [NetworkSettingController::class, 'updateSettings']);

        // Parámetros y Motor de Transcodificación / Mapeo de Tonos
        Route::get('/transcoding', [TranscodingSettingController::class, 'index']);
        Route::post('/transcoding', [TranscodingSettingController::class, 'update']);
        Route::post('/transcoding/validate-path', [TranscodingSettingController::class, 'validatePath']);

        // Administración y Mantenimiento de Base de Datos
        Route::get('/database/settings', [DatabaseAdminController::class, 'settings']);
        Route::post('/database/settings', [DatabaseAdminController::class, 'updateSettings']);
        Route::get('/database/backups', [DatabaseAdminController::class, 'backups']);
        Route::post('/database/backups/create', [DatabaseAdminController::class, 'createBackup']);
        Route::get('/database/backups/download/{filename}', [DatabaseAdminController::class, 'downloadBackup']);
        Route::post('/database/backups/restore/{filename}', [DatabaseAdminController::class, 'restoreBackup']);
        Route::delete('/database/backups/{filename}', [DatabaseAdminController::class, 'deleteBackup']);
        Route::post('/database/optimize', [DatabaseAdminController::class, 'optimize']);

        // Control de Servidor (Reiniciar, Detener, Iniciar y Cambio de Nombre)
        Route::post('/server/{action}', [ServerControlController::class, 'control']);
        Route::patch('/server/name', [ServerControlController::class, 'updateName']);
        Route::get('/server/status', [ServerControlController::class, 'status']);

        // Soporte Multi-Servidor (Nodos de Medios)
        Route::get('/nodes', [MediaNodeController::class, 'index']);
        Route::post('/nodes', [MediaNodeController::class, 'store']);
        Route::post('/nodes/{id}/activate', [MediaNodeController::class, 'activate']);
        Route::post('/nodes/{id}/sync', [MediaNodeController::class, 'sync']);
        Route::delete('/nodes/{id}', [MediaNodeController::class, 'destroy']);

        // Alertas del Sistema en Tiempo Real
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alerts/{id}/dismiss', [AlertController::class, 'dismiss']);
        Route::post('/alerts/dismiss-all', [AlertController::class, 'dismissAll']);

        // Gestión de TV en Vivo (Fuentes IPTV, Canales y Sincronización)
        Route::get('/tv-sources', [TvSourceController::class, 'index']);
        Route::post('/tv-sources', [TvSourceController::class, 'store']);
        Route::get('/tv-sources/{id}', [TvSourceController::class, 'show']);
        Route::put('/tv-sources/{id}', [TvSourceController::class, 'update']);
        Route::post('/tv-sources/{id}/refresh', [TvSourceController::class, 'refresh']);
        Route::post('/tv-sources/{id}/sync', [TvSourceController::class, 'refresh']);
        Route::post('/tv-sources/{id}/seed-demo', [TvSourceController::class, 'seedDemo']);
        Route::delete('/tv-sources/{id}', [TvSourceController::class, 'destroy']);
        Route::get('/tv-channels', [TvSourceController::class, 'channels']);

        // Fuentes de Datos de Guía (EPG / XMLTV)
        Route::get('/tv-guide-sources', [TvGuideSourceController::class, 'index']);
        Route::post('/tv-guide-sources', [TvGuideSourceController::class, 'store']);
        Route::post('/tv-guide-sources/{id}/refresh', [TvGuideSourceController::class, 'refresh']);
        Route::delete('/tv-guide-sources/{id}', [TvGuideSourceController::class, 'destroy']);

        // Gestión de Tareas Programadas del Servidor (Scheduled Tasks)
        Route::get('/tasks', [ScheduledTaskController::class, 'index']);
        Route::post('/tasks/{key}/run', [ScheduledTaskController::class, 'run']);
        Route::get('/tasks/{key}/status', [ScheduledTaskController::class, 'status']);

        // Gestión y Visualización de Logs del Servidor
        Route::get('/logs/settings', [LogAdminController::class, 'settings']);
        Route::post('/logs/settings', [LogAdminController::class, 'updateSettings']);
        Route::get('/logs/files', [LogAdminController::class, 'files']);
        Route::get('/logs/content', [LogAdminController::class, 'content']);
        Route::get('/logs/download/{filename}', [LogAdminController::class, 'download']);
        Route::post('/logs/clear', [LogAdminController::class, 'clear']);

        // Gestión y Monitoreo de Dispositivos Conectados en Tiempo Real
        Route::get('/devices', [DeviceSessionController::class, 'index']);
        Route::get('/devices/{id}', [DeviceSessionController::class, 'show']);
        Route::put('/devices/{id}/name', [DeviceSessionController::class, 'updateName']);
        Route::post('/devices/{id}/playback-control', [DeviceSessionController::class, 'playbackControl']);
        Route::post('/devices/{id}/toggle-block', [DeviceSessionController::class, 'toggleBlock']);
        Route::delete('/devices/{id}/session', [DeviceSessionController::class, 'terminateSession']);

        // Gestión de Extensiones y Complementos del Servidor (Plugins)
        Route::get('/plugins/installed', [PluginAdminController::class, 'installed']);
        Route::get('/plugins/catalog', [PluginAdminController::class, 'catalog']);
        Route::post('/plugins/{slug}/install', [PluginAdminController::class, 'install']);
        Route::post('/plugins/{slug}/uninstall', [PluginAdminController::class, 'uninstall']);
        Route::post('/plugins/{slug}/toggle', [PluginAdminController::class, 'toggle']);
        Route::get('/plugins/{slug}/settings', [PluginAdminController::class, 'settings']);
        Route::put('/plugins/{slug}/settings', [PluginAdminController::class, 'updateSettings']);

        // Gestión de Claves API y Tokens de Integración
        Route::get('/api-keys', [ApiKeyAdminController::class, 'index']);
        Route::post('/api-keys', [ApiKeyAdminController::class, 'store']);
        Route::put('/api-keys/{id}', [ApiKeyAdminController::class, 'update']);
        Route::post('/api-keys/{id}/toggle', [ApiKeyAdminController::class, 'toggle']);
        Route::delete('/api-keys/{id}', [ApiKeyAdminController::class, 'destroy']);

        // Gestor de Metadatos de Medios y Árbol Explorer
        Route::get('/metadata/tree', [MetadataManagerController::class, 'tree']);
        Route::get('/metadata/item/{type}/{id}', [MetadataManagerController::class, 'showItem']);
        Route::put('/metadata/item/{type}/{id}', [MetadataManagerController::class, 'updateItem']);
        Route::post('/metadata/item/{type}/{id}/refresh', [MetadataManagerController::class, 'refresh']);
        Route::post('/metadata/item/{type}/{id}/toggle-lock', [MetadataManagerController::class, 'toggleLock']);
        Route::delete('/metadata/item/{type}/{id}', [MetadataManagerController::class, 'destroy']);
        Route::get('/metadata/search-external', [MetadataManagerController::class, 'searchExternal']);

        // Estadísticas y Analíticas Avanzadas del Servidor (On-Net & Telemetría)
        Route::prefix('stats')->group(function () {
            Route::get('/overview', [AnalyticsAdminController::class, 'overview']);
            Route::get('/playback-chart', [AnalyticsAdminController::class, 'playbackChart']);
            Route::get('/top-content', [AnalyticsAdminController::class, 'topContent']);
            Route::get('/top-users', [AnalyticsAdminController::class, 'topUsers']);
            Route::get('/device-distribution', [AnalyticsAdminController::class, 'deviceDistribution']);
            Route::get('/live-tv', [AnalyticsAdminController::class, 'liveTvRanking']);
            Route::get('/export', [AnalyticsAdminController::class, 'export']);
        });

        // Cuentas y Sincronización OTT (Netflix, Disney+, HBO Max, Prime Video, Apple TV+, Paramount+)
        Route::prefix('ott-providers')->group(function () {
            Route::get('/', [OttProviderController::class, 'index']);
            Route::get('/tasks', [OttProviderController::class, 'tasks']);
            Route::post('/tasks/clear-completed', [OttProviderController::class, 'clearCompleted']);
            Route::post('/tasks/{id}/cancel', [OttProviderController::class, 'cancelTask']);
            Route::post('/tasks/{id}/retry', [OttProviderController::class, 'retryTask']);
            Route::delete('/tasks/{id}', [OttProviderController::class, 'deleteTask']);
            Route::post('/connect', [OttProviderController::class, 'connect']);
            Route::post('/{provider}/connect', [OttProviderController::class, 'connect']);
            Route::post('/{provider}/disconnect', [OttProviderController::class, 'disconnect']);
            Route::post('/{provider}/toggle-auto-download', [OttProviderController::class, 'toggleAutoDownload']);
            Route::post('/{provider}/sync', [OttProviderController::class, 'sync']);
            Route::delete('/{provider}', [OttProviderController::class, 'destroy']);
        });

        // Endpoint canónico directo para tareas de ingesta OTT
        Route::get('/ott-tasks', [OttProviderController::class, 'tasks']);
    });

    // Telemetría y Latido de Reproducción Real en Vivo
    Route::post('/telemetry/heartbeat', [DeviceSessionController::class, 'heartbeat']);
    Route::post('/telemetry/stop', [DeviceSessionController::class, 'stopPlayback']);
    Route::post('/devices/heartbeat', [DeviceSessionController::class, 'heartbeat']);
});

// Detención de telemetría de dispositivo (Compatible con sendBeacon y keepalive al cerrar pestaña)
Route::post('/telemetry/stop', [DeviceSessionController::class, 'stopPlayback']);

/*
|--------------------------------------------------------------------------
| API v1 - Integración de Terceros mediante API Key (Radarr, Sonarr, Home Assistant, etc.)
|--------------------------------------------------------------------------
*/
Route::middleware(AuthenticateApiKey::class)->prefix('v1')->group(function () {
    Route::get('/ping', [ApiKeyAdminController::class, 'ping']);
    Route::get('/titles', [TitleController::class, 'index']);
});
