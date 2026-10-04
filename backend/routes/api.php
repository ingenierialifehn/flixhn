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
use App\Http\Middleware\EnsureAdmin;

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
    });
});
