<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FilesystemAdminController extends Controller
{
    /**
     * Directorios y prefijos del sistema que están terminantemente prohibidos
     * para navegación por razones de seguridad (evita lectura de carpetas raíz críticas).
     */
    protected array $forbiddenPrefixes = [
        '/etc',
        '/root',
        '/bin',
        '/sbin',
        '/usr',
        '/boot',
        '/dev',
        '/proc',
        '/sys',
        '/run',
        '/var/run',
        '/var/lib/mysql',
        'c:/windows',
        'c:/program files',
        'c:/program files (x86)',
    ];

    /**
     * Raíces de almacenamiento base autorizadas para navegación de medios e ingesta OTT.
     */
    protected function getAllowedRoots(): array
    {
        $roots = [
            '/storage',
            '/var/media',
            '/media',
            '/mnt',
            '/var/www/html/storage',
            '/var/www/html/media',
        ];

        // Añadir storage_path y media base del proyecto actual
        try {
            $storage = $this->normalizePath(storage_path());
            $roots[] = $storage;

            $media = $this->normalizePath(base_path('media'));
            $roots[] = $media;

            $parentMedia = $this->normalizePath(base_path('../media'));
            $roots[] = $parentMedia;
        } catch (\Throwable $e) {
            // Ignorar en entornos reducidos
        }

        return array_values(array_unique($roots));
    }

    /**
     * Normaliza rutas de archivo a formato Unix con barras hacia adelante sin trailing slash.
     */
    protected function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $isAbsoluteUnix = str_starts_with($path, '/');
        $hasDriveLetter = preg_match('/^[a-zA-Z]:/', $path);

        $parts = array_filter(explode('/', $path), function ($p) {
            return $p !== '' && $p !== '.';
        });

        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        $res = implode('/', $resolved);
        if ($isAbsoluteUnix && !$hasDriveLetter) {
            $res = '/' . $res;
        }

        return $res === '' ? '/' : rtrim($res, '/');
    }

    /**
     * Determina la ruta por defecto cuando no se envía ninguna.
     */
    protected function getDefaultStoragePath(): string
    {
        // 1. Priorizar /storage/media si existe o si /storage existe
        if (is_dir('/storage/media')) {
            return '/storage/media';
        }

        if (is_dir('/storage')) {
            if (is_writable('/storage')) {
                @File::makeDirectory('/storage/media', 0775, true);
                if (is_dir('/storage/media')) {
                    return '/storage/media';
                }
            }
            return '/storage';
        }

        // 2. Si /var/media está montado (Docker standard)
        if (is_dir('/var/media')) {
            return '/var/media';
        }

        // 3. Carpeta media del storage de Laravel
        $laravelMedia = $this->normalizePath(storage_path('media'));
        if (!is_dir($laravelMedia)) {
            @File::makeDirectory($laravelMedia, 0775, true);
        }
        if (is_dir($laravelMedia)) {
            return $laravelMedia;
        }

        // 4. Intentar crear /storage/media si hay permisos en raíz
        if (@File::makeDirectory('/storage/media', 0775, true)) {
            return '/storage/media';
        }

        return $this->normalizePath(storage_path());
    }

    /**
     * Valida que una ruta no apunte a directorios protegidos del sistema.
     */
    protected function isPathSafe(string $path): bool
    {
        $lower = strtolower($path);

        // Bloquear inyección o secuencias maliciosas
        if (str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        foreach ($this->forbiddenPrefixes as $forbidden) {
            if ($lower === $forbidden || str_starts_with($lower, $forbidden . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Endpoint Backend: Explorador de Directorios del Servidor
     * POST o GET /api/admin/filesystem/browse-directories
     *
     * Params:
     * - current_path: string (opcional)
     */
    public function browseDirectories(Request $request): JsonResponse
    {
        try {
            $rawPath = $request->input('current_path', $request->query('current_path'));
            $currentPath = is_string($rawPath) && trim($rawPath) !== ''
                ? $this->normalizePath(trim($rawPath))
                : $this->getDefaultStoragePath();

            // Seguridad: Validar que no sea una ruta prohibida
            if (!$this->isPathSafe($currentPath)) {
                $currentPath = $this->getDefaultStoragePath();
            }

            // Si el directorio no existe físicamente, intentar crearlo si está bajo /storage o storage_path
            if (!is_dir($currentPath)) {
                $isAllowedToAutoCreate = str_starts_with($currentPath, '/storage')
                    || str_starts_with($currentPath, '/var/media')
                    || str_starts_with($currentPath, $this->normalizePath(storage_path()));

                if ($isAllowedToAutoCreate) {
                    @File::makeDirectory($currentPath, 0775, true);
                }

                if (!is_dir($currentPath)) {
                    // Fallback al default si aún no existe
                    $currentPath = $this->getDefaultStoragePath();
                }
            }

            // Calcular parent_path
            $parentPath = null;
            if ($currentPath !== '/' && $currentPath !== '') {
                $parentCandidate = dirname($currentPath);
                $parentCandidate = $this->normalizePath($parentCandidate);

                if ($this->isPathSafe($parentCandidate) && $parentCandidate !== $currentPath) {
                    $parentPath = $parentCandidate;
                }
            }

            // Si estamos en /storage o /var/media, permitir que parent_path sea '/' o null
            if ($currentPath === '/') {
                $parentPath = null;
            }

            // Obtener subdirectorios
            $directories = [];
            $canCreateFolder = is_dir($currentPath) && is_writable($currentPath);

            if (is_dir($currentPath) && is_readable($currentPath)) {
                $items = @scandir($currentPath);
                if ($items !== false) {
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }

                        // Ocultar carpetas internas de sistema o dotfiles (ej. .git)
                        if (str_starts_with($item, '.') || $item === 'vendor' || $item === 'node_modules') {
                            continue;
                        }

                        $fullItemPath = rtrim($currentPath, '/') . '/' . $item;

                        if (is_dir($fullItemPath)) {
                            // Conteo de elementos dentro de la subcarpeta
                            $itemCount = 0;
                            $subScan = @scandir($fullItemPath);
                            if ($subScan !== false) {
                                $itemCount = count(array_filter($subScan, function ($sub) {
                                    return $sub !== '.' && $sub !== '..';
                                }));
                            }

                            $directories[] = [
                                'name' => $item,
                                'path' => $fullItemPath,
                                'item_count' => $itemCount,
                                'is_writable' => is_writable($fullItemPath),
                            ];
                        }
                    }
                }
            }

            // Ordenar alfabéticamente
            usort($directories, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });

            return response()->json([
                'current_path' => $currentPath,
                'parent_path' => $parentPath,
                'directories' => $directories,
                'can_create_folder' => $canCreateFolder,
            ]);

        } catch (\Throwable $e) {
            Log::error('FilesystemAdminController::browseDirectories Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al explorar el directorio del servidor: ' . $e->getMessage(),
                'current_path' => $this->getDefaultStoragePath(),
                'parent_path' => null,
                'directories' => [],
                'can_create_folder' => false,
            ], 500);
        }
    }

    /**
     * Endpoint Auxiliar: Crear subcarpeta directamente en el servidor.
     * POST /api/admin/filesystem/make-directory
     *
     * Params:
     * - path: string (directorio padre donde se creará la carpeta)
     * - name: string (nombre de la nueva subcarpeta)
     */
    public function makeDirectory(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'path' => 'required|string',
                'name' => 'required|string|max:120',
            ]);

            $parentPath = $this->normalizePath($request->input('path'));
            $folderName = trim($request->input('name'));

            // Validar seguridad de nombre de carpeta (sin barras ni caracteres ilegales)
            if (!preg_match('/^[a-zA-Z0-9_\-\. ]+$/', $folderName) || $folderName === '.' || $folderName === '..') {
                return response()->json([
                    'success' => false,
                    'message' => 'El nombre de carpeta contiene caracteres no permitidos. Usa letras, números, guiones y espacios.',
                ], 422);
            }

            // Validar que la ruta padre sea segura y exista
            if (!$this->isPathSafe($parentPath) || !is_dir($parentPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La ruta especificada no es accesible o no existe en el servidor.',
                ], 422);
            }

            if (!is_writable($parentPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El servidor no tiene permisos de escritura en la carpeta destino.',
                ], 403);
            }

            $newPath = rtrim($parentPath, '/') . '/' . $folderName;

            if (is_dir($newPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un directorio con ese nombre.',
                    'directory' => [
                        'name' => $folderName,
                        'path' => $newPath,
                    ],
                ], 409);
            }

            $created = @File::makeDirectory($newPath, 0775, true);
            if (!$created && !is_dir($newPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No fue posible crear la carpeta en el sistema de archivos del servidor.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => "Carpeta '{$folderName}' creada exitosamente en el servidor.",
                'directory' => [
                    'name' => $folderName,
                    'path' => $newPath,
                    'item_count' => 0,
                    'is_writable' => true,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('FilesystemAdminController::makeDirectory Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el directorio: ' . $e->getMessage(),
            ], 500);
        }
    }
}
