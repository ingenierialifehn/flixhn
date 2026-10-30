<?php

use App\Http\Controllers\MediaStreamController;
use App\Http\Controllers\OttProviderController;
use App\Http\Controllers\OttSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'FlixHN Streaming Platform',
        'status' => 'operational',
        'version' => '1.0.0'
    ]);
});

// Entrega directa de carátulas y assets de storage con fallback transparente
Route::match(['get', 'head'], '/storage/{path}', [MediaStreamController::class, 'serveStorage'])->where('path', '.*');

// Streaming directo a nivel web para clientes
Route::match(['get', 'head'], '/media/{id}/stream', [MediaStreamController::class, 'stream']);
Route::match(['get', 'head'], '/stream/{id}', [MediaStreamController::class, 'stream']);

// Módulo FlixGrab OTT Sync (Clean Slate Endpoints)
Route::prefix('admin/ott-sync')->name('admin.ott-sync.')->group(function () {
    Route::get('/', [OttSyncController::class, 'index'])->name('index');
    Route::get('/search', [OttSyncController::class, 'search'])->name('search');
    Route::get('/queue', [OttSyncController::class, 'queue'])->name('queue');
    Route::post('/download', [OttProviderController::class, 'download'])->name('download');
});

// Rutas de compatibilidad directa
Route::get('/admin/ott-sync', [OttSyncController::class, 'index'])->name('admin.ott-sync');
Route::post('/admin/ott-sync/download', [OttProviderController::class, 'download']);
Route::get('/ott-sync', [OttSyncController::class, 'index'])->name('ott-sync');
Route::get('/ott-sync/search', [OttSyncController::class, 'search'])->name('ott-sync.search');
Route::get('/ott-sync/queue', [OttSyncController::class, 'queue'])->name('ott-sync.queue');
Route::post('/ott-sync/download', [OttProviderController::class, 'download'])->name('ott-sync.download');


