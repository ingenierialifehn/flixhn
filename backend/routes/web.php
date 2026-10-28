<?php

use App\Http\Controllers\MediaStreamController;
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

// Módulo FlixGrab OTT Sync (Vista Blade integrada + API Web)
Route::get('/admin/ott-sync', [\App\Http\Controllers\OttSyncController::class, 'index'])->name('admin.ott-sync');
Route::get('/ott-sync', [\App\Http\Controllers\OttSyncController::class, 'index'])->name('ott-sync');

