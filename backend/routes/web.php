<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'FlixHN Streaming Platform',
        'status' => 'operational',
        'version' => '1.0.0'
    ]);
});
