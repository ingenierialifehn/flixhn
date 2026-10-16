<?php

namespace Illuminate\Routing;

class RouteFileRegistrar
{
    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * Create a new route file registrar instance.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Require the given routes file.
     *
     * @param  string  $routes
     * @return void
     */
    public function register($routes)
    {
        $router = $this->router;

        for ($i = 0; $i < 10; $i++) {
            try {
                $included = @(fn () => include $routes)();
                if ($included !== false) {
                    return;
                }
            } catch (\Throwable $e) {}
            clearstatcache(true, $routes);
            usleep(25000);
        }

        $content = @file_get_contents($routes);
        if ($content === false || $content === '') {
            $content = @shell_exec('cat ' . escapeshellarg($routes));
        }
        if ($content) {
            eval('?>' . $content);
            return;
        }

        require $routes;
    }
}
