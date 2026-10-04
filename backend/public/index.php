<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Comprobar modo mantenimiento
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Cargar el autoloader de Composer
require __DIR__.'/../vendor/autoload.php';

// Arrancar la aplicación Laravel
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
