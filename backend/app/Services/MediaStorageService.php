<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MediaStorageService
{
    /**
     * Lista de rutas candidatas a discos de almacenamiento en el servidor.
     */
    protected static array $candidateDisks = [
        '/mnt/media_library/Disk5', // Mayor capacidad histórica (11 TB)
        '/mnt/media_library/Disk2', // 4.2 TB
        '/mnt/media_library/Disk3', // 1.4 TB
        '/mnt/media_library/Disk7', // 170 GB
        '/mnt/media_library/Disk6', // 145 GB
        '/mnt/media_library/Disk4',
        '/home/server01/Disk5',
        '/home/server01/Disk2',
        '/home/server01/Disk3',
        '/home/server01/Disk7',
        '/home/server01/Disk6',
        '/home/server01/Disk4',
    ];

    /**
     * Localiza el disco con mayor espacio disponible en el servidor.
     */
    public static function getEmptiestDiskPath(): string
    {
        $allCandidates = [];

        // 1. Escanear discos estándar en /mnt/media_library
        $globMnt = @glob('/mnt/media_library/Disk*') ?: [];
        $globHome = @glob('/home/server01/Disk*') ?: [];

        $allCandidates = array_values(array_unique(array_merge(
            $globMnt,
            $globHome,
            self::$candidateDisks
        )));

        $maxFreeBytes = -1;
        $bestDisk = null;

        foreach ($allCandidates as $diskPath) {
            $normalized = str_replace('\\', '/', $diskPath);
            if (@is_dir($normalized)) {
                $free = @disk_free_space($normalized);
                if ($free !== false && $free > $maxFreeBytes) {
                    $maxFreeBytes = $free;
                    $bestDisk = $normalized;
                }
            }
        }

        // Si se encontró un disco válido montado
        if ($bestDisk && $maxFreeBytes > 1073741824) { // Más de 1 GB libre
            return $bestDisk;
        }

        // Fallback local seguro
        return storage_path('media');
    }

    /**
     * Obtiene y asegura la existencia de la carpeta 'FlixHN' dentro del disco más vacío.
     *
     * @param string|null $subpath Subcarpeta opcional (ej: 'netflix', 'movies', 'series')
     * @return string Ruta física absoluta asegurada con permisos
     */
    public static function getEmptiestDiskFlixHnFolder(?string $subpath = null): string
    {
        $emptiestDisk = self::getEmptiestDiskPath();
        
        // Si el disco es el almacenamiento local fallback (storage/media), usar directamente FlixHN
        $baseFolder = rtrim($emptiestDisk, '/\\') . '/FlixHN';

        if (!empty($subpath)) {
            $cleanSub = trim(str_replace('\\', '/', $subpath), '/');
            // Evitar duplicar /FlixHN si el subpath ya lo incluye
            $cleanSub = preg_replace('#^FlixHN/?#i', '', $cleanSub);
            $targetFolder = !empty($cleanSub) ? "{$baseFolder}/{$cleanSub}" : $baseFolder;
        } else {
            $targetFolder = $baseFolder;
        }

        try {
            if (!File::isDirectory($targetFolder)) {
                File::makeDirectory($targetFolder, 0777, true, true);
            }
            @chmod($targetFolder, 0777);
            // Asegurar también permisos en la carpeta raíz FlixHN
            if (File::isDirectory($baseFolder)) {
                @chmod($baseFolder, 0777);
            }
        } catch (\Throwable $e) {
            Log::warning("MediaStorageService: No se pudo crear '{$targetFolder}', aplicando fallback: " . $e->getMessage());
            $targetFolder = storage_path('media/FlixHN' . ($subpath ? '/' . trim($subpath, '/') : ''));
            try {
                if (!File::isDirectory($targetFolder)) {
                    File::makeDirectory($targetFolder, 0777, true, true);
                }
                @chmod($targetFolder, 0777);
            } catch (\Throwable $f) {}
        }

        return str_replace('\\', '/', $targetFolder);
    }

    /**
     * Construye la ruta de salida para un archivo de video en la carpeta FlixHN del disco más vacío.
     */
    public static function resolveFlixHnOutputFile(string $slugTitle, ?string $provider = null, string $ext = 'mp4'): string
    {
        $folder = self::getEmptiestDiskFlixHnFolder($provider);
        $cleanExt = ltrim($ext, '.');
        return rtrim($folder, '/\\') . "/{$slugTitle}.{$cleanExt}";
    }

    /**
     * Retorna diagnóstico y métricas de todos los discos disponibles.
     */
    public static function getAllDisksInfo(): array
    {
        $globMnt = @glob('/mnt/media_library/Disk*') ?: [];
        $globHome = @glob('/home/server01/Disk*') ?: [];
        $candidates = array_values(array_unique(array_merge($globMnt, $globHome, self::$candidateDisks)));

        $results = [];
        foreach ($candidates as $disk) {
            if (@is_dir($disk)) {
                $free = @disk_free_space($disk) ?: 0;
                $total = @disk_total_space($disk) ?: 0;
                $used = max(0, $total - $free);
                $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;

                $results[] = [
                    'path' => $disk,
                    'free_bytes' => $free,
                    'total_bytes' => $total,
                    'used_bytes' => $used,
                    'free_readable' => self::formatBytes($free),
                    'total_readable' => self::formatBytes($total),
                    'used_percent' => $percent,
                    'has_flixhn_folder' => @is_dir($disk . '/FlixHN'),
                ];
            }
        }

        usort($results, fn($a, $b) => $b['free_bytes'] <=> $a['free_bytes']);
        return $results;
    }

    protected static function formatBytes(float $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}

