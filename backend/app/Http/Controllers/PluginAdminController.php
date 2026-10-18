<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use Database\Seeders\PluginSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PluginAdminController extends Controller
{
    /**
     * Asegurar catálogo base en la base de datos si está vacía.
     */
    protected function ensureCatalogPopulated(): void
    {
        if (Plugin::count() === 0) {
            (new PluginSeeder())->run();
        }
    }

    /**
     * Listar plugins instalados en el sistema.
     * GET /api/admin/plugins/installed
     */
    public function installed(Request $request): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $query = Plugin::where('is_installed', true);

            if ($request->filled('category')) {
                $query->where('category', $request->query('category'));
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            $installed = $query->orderBy('category')->orderBy('name')->get();
            $allPlugins = Plugin::all();

            $summary = [
                'total_installed' => $allPlugins->where('is_installed', true)->count(),
                'total_enabled' => $allPlugins->where('is_installed', true)->where('is_enabled', true)->count(),
                'total_disabled' => $allPlugins->where('is_installed', true)->where('is_enabled', false)->count(),
                'total_catalog' => $allPlugins->count(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => [
                    'plugins' => $installed,
                    'categories' => Plugin::categories(),
                    'summary' => $summary,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('PluginAdminController::installed Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener plugins instalados: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar todo el catálogo de plugins disponibles con filtros.
     * GET /api/admin/plugins/catalog
     */
    public function catalog(Request $request): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $query = Plugin::query();

            if ($request->filled('category') && $request->query('category') !== 'all') {
                $query->where('category', $request->query('category'));
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            $plugins = $query->orderBy('is_installed', 'desc')->orderBy('name')->get();
            $allPlugins = Plugin::all();

            $summary = [
                'total_catalog' => $allPlugins->count(),
                'total_installed' => $allPlugins->where('is_installed', true)->count(),
                'total_enabled' => $allPlugins->where('is_installed', true)->where('is_enabled', true)->count(),
                'total_available' => $allPlugins->where('is_installed', false)->count(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => [
                    'plugins' => $plugins,
                    'categories' => Plugin::categories(),
                    'summary' => $summary,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('PluginAdminController::catalog Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener catálogo de plugins: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Instalar un plugin e inicializar configuración por defecto.
     * POST /api/admin/plugins/{slug}/install
     */
    public function install(string $slug): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $plugin = Plugin::where('slug', $slug)->first();

            if (!$plugin) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$slug}' no existe en el catálogo.",
                ], 404);
            }

            $plugin->is_installed = true;
            $plugin->is_enabled = true;

            // Inicializar configuración por defecto si está vacía
            if (empty($plugin->settings)) {
                $plugin->settings = $plugin->default_settings ?? [];
            }

            $plugin->save();

            return response()->json([
                'status' => 'success',
                'message' => "Plugin '{$plugin->name}' instalado exitosamente.",
                'data' => [
                    'plugin' => $plugin,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("PluginAdminController::install ({$slug}) Error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al instalar el plugin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Desinstalar un plugin y restaurar configuración por defecto.
     * POST /api/admin/plugins/{slug}/uninstall
     */
    public function uninstall(string $slug): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $plugin = Plugin::where('slug', $slug)->first();

            if (!$plugin) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$slug}' no existe en el catálogo.",
                ], 404);
            }

            $plugin->is_installed = false;
            $plugin->is_enabled = false;
            // Restaurar configuración predeterminada limpia
            $plugin->settings = $plugin->default_settings ?? [];
            $plugin->save();

            return response()->json([
                'status' => 'success',
                'message' => "Plugin '{$plugin->name}' desinstalado exitosamente.",
                'data' => [
                    'plugin' => $plugin,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("PluginAdminController::uninstall ({$slug}) Error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al desinstalar el plugin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activar o pausar la ejecución de un plugin.
     * POST /api/admin/plugins/{slug}/toggle
     */
    public function toggle(Request $request, string $slug): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $plugin = Plugin::where('slug', $slug)->first();

            if (!$plugin) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$slug}' no existe en el catálogo.",
                ], 404);
            }

            if (!$plugin->is_installed) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$plugin->name}' debe estar instalado antes de activarlo.",
                ], 422);
            }

            if ($request->has('is_enabled')) {
                $plugin->is_enabled = (bool) $request->input('is_enabled');
            } else {
                $plugin->is_enabled = !$plugin->is_enabled;
            }

            $plugin->save();

            $actionText = $plugin->is_enabled ? 'activado' : 'desactivado';

            return response()->json([
                'status' => 'success',
                'message' => "Plugin '{$plugin->name}' {$actionText} exitosamente.",
                'data' => [
                    'plugin' => $plugin,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("PluginAdminController::toggle ({$slug}) Error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al alternar estado del plugin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener configuración actual y esquema por defecto de un plugin.
     * GET /api/admin/plugins/{slug}/settings
     */
    public function settings(string $slug): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $plugin = Plugin::where('slug', $slug)->first();

            if (!$plugin) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$slug}' no fue encontrado.",
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'plugin' => $plugin,
                    'settings' => $plugin->settings ?? $plugin->default_settings ?? [],
                    'default_settings' => $plugin->default_settings ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("PluginAdminController::settings ({$slug}) Error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al recuperar configuración del plugin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Guardar configuración personalizada de un plugin.
     * PUT /api/admin/plugins/{slug}/settings
     */
    public function updateSettings(Request $request, string $slug): JsonResponse
    {
        try {
            $this->ensureCatalogPopulated();

            $plugin = Plugin::where('slug', $slug)->first();

            if (!$plugin) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El plugin '{$slug}' no fue encontrado.",
                ], 404);
            }

            $newSettings = $request->input('settings', []);

            if (!is_array($newSettings)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El formato de configuración debe ser un objeto o arreglo válido.',
                ], 422);
            }

            // Preservar estructura existente mezclando con nuevos valores
            $current = $plugin->settings ?? $plugin->default_settings ?? [];
            $merged = array_merge($current, $newSettings);

            $plugin->settings = $merged;
            $plugin->save();

            return response()->json([
                'status' => 'success',
                'message' => "Configuración de '{$plugin->name}' guardada correctamente.",
                'data' => [
                    'plugin' => $plugin,
                    'settings' => $plugin->settings,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("PluginAdminController::updateSettings ({$slug}) Error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al guardar configuración del plugin: ' . $e->getMessage(),
            ], 500);
        }
    }
}
