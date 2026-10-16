<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranscodingSetting extends Model
{
    use HasFactory;

    protected $table = 'transcoding_settings';

    protected $fillable = [
        'enable_hardware_acceleration',
        'h264_encoder_settings',
        'h265_encoder_settings',
        'temp_path',
        'enable_throttling',
        'audio_boost_downmix',
        'allow_subtitle_extraction_on_the_fly',
        'enable_software_tone_mapping',
        'tone_mapping_resolution_limit',
        'tone_mapping_algorithm',
    ];

    protected $casts = [
        'h264_encoder_settings' => 'array',
        'h265_encoder_settings' => 'array',
        'enable_throttling' => 'boolean',
        'audio_boost_downmix' => 'integer',
        'allow_subtitle_extraction_on_the_fly' => 'boolean',
        'enable_software_tone_mapping' => 'boolean',
    ];

    /**
     * Valores predeterminados del sistema de transcodificación FlixHN.
     */
    public static function defaults(): array
    {
        return [
            'enable_hardware_acceleration' => 'no',
            'h264_encoder_settings' => [
                'preset' => 'veryfast',
                'crf' => 23,
                'profile' => 'high',
            ],
            'h265_encoder_settings' => [
                'preset' => 'medium',
                'crf' => 28,
                'profile' => 'main',
            ],
            'temp_path' => null,
            'enable_throttling' => false,
            'audio_boost_downmix' => 2,
            'allow_subtitle_extraction_on_the_fly' => false,
            'enable_software_tone_mapping' => true,
            'tone_mapping_resolution_limit' => 'no_limit',
            'tone_mapping_algorithm' => 'hable',
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
