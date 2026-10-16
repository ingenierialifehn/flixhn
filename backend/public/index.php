<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Sincronizar caches en tmpfs de Linux para eliminar latencia y bloqueos de VirtioFS
if (!file_exists('/tmp/config.php') && file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    @copy(__DIR__.'/../bootstrap/cache/config.php', '/tmp/config.php');
}
if (!file_exists('/tmp/packages.php') && file_exists(__DIR__.'/../bootstrap/cache/packages.php')) {
    @copy(__DIR__.'/../bootstrap/cache/packages.php', '/tmp/packages.php');
}
if (!file_exists('/tmp/services.php') && file_exists(__DIR__.'/../bootstrap/cache/services.php')) {
    @copy(__DIR__.'/../bootstrap/cache/services.php', '/tmp/services.php');
}

// Comprobar modo mantenimiento
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Cargar el autoloader de Composer con reintento seguro
$autoloader = __DIR__.'/../vendor/autoload.php';
for ($i = 0; $i < 10; $i++) {
    $loaded = @include $autoloader;
    if ($loaded !== false) break;
    usleep(25000);
}
if (!isset($loaded) || $loaded === false) {
    require $autoloader;
}

// Arrancar la aplicación Laravel con tolerancia a VirtioFS
$appFile = __DIR__.'/../bootstrap/app.php';
$app = null;
for ($i = 0; $i < 10; $i++) {
    $app = @include $appFile;
    if ($app instanceof \Illuminate\Foundation\Application) break;
    usleep(25000);
}
if (!$app) {
    $app = require_once $appFile;
}

$app->handleRequest(Request::capture());
