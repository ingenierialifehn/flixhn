<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledTask extends Model
{
    use HasFactory;

    protected $table = 'scheduled_tasks';

    protected $fillable = [
        'key',
        'name',
        'category',
        'description',
        'last_run_at',
        'last_duration_seconds',
        'status',
        'last_error_message',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'last_duration_seconds' => 'float',
    ];

    /**
     * Categorías oficiales de tareas con metadatos descriptivos e íconos.
     */
    public static function categories(): array
    {
        return [
            'application' => [
                'name' => 'Application',
                'label' => 'Aplicación / Sistema',
                'description' => 'Mantenimiento del servidor central FlixHN, respaldos y retención de logs.',
                'icon' => 'Server',
            ],
            'database' => [
                'name' => 'Database',
                'label' => 'Base de Datos',
                'description' => 'Desfragmentación, análisis de índices y optimización del motor MySQL On-Net.',
                'icon' => 'Database',
            ],
            'conversions' => [
                'name' => 'Downloads & Conversions',
                'label' => 'Descargas y Transcodificación',
                'description' => 'Procesamiento de video HLS adaptativo, caché temporal y limpieza de fragmentos.',
                'icon' => 'Cpu',
            ],
            'library' => [
                'name' => 'Library',
                'label' => 'Biblioteca de Medios',
                'description' => 'Escaneo de almacenamiento local, descarga de subtítulos, metadatos y miniaturas.',
                'icon' => 'Film',
            ],
            'livetv' => [
                'name' => 'Live TV',
                'label' => 'Televisión en Vivo',
                'description' => 'Sincronización de listas M3U activas, guía electrónica EPG y logos de canales.',
                'icon' => 'Tv',
            ],
            'statistics' => [
                'name' => 'Statistics',
                'label' => 'Estadísticas y Métricas',
                'description' => 'Consolidación de métricas de reproducción, telemetría y perfiles de consumo.',
                'icon' => 'BarChart3',
            ],
        ];
    }

    /**
     * Definición de tareas oficiales iniciales para FlixHN.
     */
    public static function defaultTasks(): array
    {
        return [
            // 1. Application
            [
                'key' => 'backup_server',
                'name' => 'FlixHN Server Backup',
                'category' => 'application',
                'description' => 'Genera respaldo comprimido de la configuración y base de datos de FlixHN.',
                'status' => 'idle',
            ],
            [
                'key' => 'rotate_logs',
                'name' => 'Rotate log file',
                'category' => 'application',
                'description' => 'Rota y archiva los archivos en storage/logs/ para evitar saturación de disco.',
                'status' => 'idle',
            ],

            // 2. Database
            [
                'key' => 'optimize_database',
                'name' => 'Vacuum / Optimize Database',
                'category' => 'database',
                'description' => 'Ejecuta optimización y análisis de índices en el clúster MySQL.',
                'status' => 'idle',
            ],

            // 3. Downloads & Conversions
            [
                'key' => 'transcode_media',
                'name' => 'Convert / Transcode media',
                'category' => 'conversions',
                'description' => 'Procesa la cola de transcodificación y verifica perfiles de video HLS adaptativo.',
                'status' => 'idle',
            ],
            [
                'key' => 'clean_temp_media',
                'name' => 'Transfer / Clean temporary media',
                'category' => 'conversions',
                'description' => 'Limpia segmentos temporales de streaming (.ts) y descargas huérfanas en caché.',
                'status' => 'idle',
            ],

            // 4. Library
            [
                'key' => 'download_subtitles',
                'name' => 'Download subtitles',
                'category' => 'library',
                'description' => 'Escaneo y vinculación de subtítulos pendientes para los títulos del catálogo.',
                'status' => 'idle',
            ],
            [
                'key' => 'scan_metadata',
                'name' => 'Scan Metadata',
                'category' => 'library',
                'description' => 'Actualiza carátulas, backdrops y metadatos de películas y series.',
                'status' => 'idle',
            ],
            [
                'key' => 'scan_media',
                'name' => 'Scan media library',
                'category' => 'library',
                'description' => 'Ejecuta el escaneo del almacenamiento local de medios (flixhn:sync-media).',
                'status' => 'idle',
            ],
            [
                'key' => 'extract_thumbnails',
                'name' => 'Thumbnail image extraction',
                'category' => 'library',
                'description' => 'Genera miniaturas y fotogramas de previsualización para los archivos de video.',
                'status' => 'idle',
            ],

            // 5. Live TV
            [
                'key' => 'sync_iptv',
                'name' => 'Refresh Guide & Channels',
                'category' => 'livetv',
                'description' => 'Sincroniza listas M3U, guía EPG activa y logotipos locales de canales.',
                'status' => 'idle',
            ],

            // 6. Statistics
            [
                'key' => 'calculate_statistics',
                'name' => 'Calculate statistics for all users',
                'category' => 'statistics',
                'description' => 'Procesa métricas de consumo de streaming, usuarios activos y ancho de banda utilizado.',
                'status' => 'idle',
            ],
        ];
    }
}
