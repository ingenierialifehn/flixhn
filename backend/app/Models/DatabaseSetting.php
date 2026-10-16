<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatabaseSetting extends Model
{
    use HasFactory;

    protected $table = 'database_settings';

    protected $fillable = [
        'db_cache_size_mb',
        'optimize_on_shutdown',
        'analysis_row_limit',
        'vacuum_on_next_startup',
        'auto_backup_enabled',
        'auto_backup_frequency',
        'auto_backup_retention_days',
    ];

    protected $casts = [
        'db_cache_size_mb' => 'integer',
        'optimize_on_shutdown' => 'boolean',
        'analysis_row_limit' => 'integer',
        'vacuum_on_next_startup' => 'boolean',
        'auto_backup_enabled' => 'boolean',
        'auto_backup_frequency' => 'string',
        'auto_backup_retention_days' => 'integer',
    ];

    /**
     * Valores predeterminados para la configuración de base de datos de FlixHN.
     */
    public static function defaults(): array
    {
        return [
            'db_cache_size_mb' => 96,
            'optimize_on_shutdown' => false,
            'analysis_row_limit' => 400,
            'vacuum_on_next_startup' => false,
            'auto_backup_enabled' => true,
            'auto_backup_frequency' => 'daily',
            'auto_backup_retention_days' => 7,
        ];
    }

    /**
     * Obtiene el registro activo o crea uno con valores por defecto.
     */
    public static function current(): self
    {
        $setting = self::first();

        if (!$setting) {
            $setting = self::create(self::defaults());
        }

        return $setting;
    }
}
