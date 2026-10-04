<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
    ];

    /**
     * Devuelve el valor con su tipo correspondiente.
     */
    public function getCastedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean', 'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $this->value,
            'float', 'double' => (float) $this->value,
            'json', 'array' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Obtiene una configuración por su clave con un valor por defecto.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        return $setting->getCastedValue();
    }

    /**
     * Guarda o actualiza una configuración individual.
     */
    public static function set(string $key, mixed $value, string $group = 'general', ?string $type = null): self
    {
        if ($type === null) {
            $type = match (gettype($value)) {
                'boolean' => 'boolean',
                'integer' => 'integer',
                'double' => 'float',
                'array' => 'json',
                default => 'string',
            };
        }

        $serializedValue = match ($type) {
            'boolean', 'bool' => $value ? '1' : '0',
            'json', 'array' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };

        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serializedValue,
                'group' => $group,
                'type' => $type,
            ]
        );
    }

    /**
     * Obtiene todas las configuraciones de un grupo, combinando con valores por defecto.
     */
    public static function getGroup(string $group, array $defaults = []): array
    {
        $settings = self::where('group', $group)->get();
        $result = $defaults;

        foreach ($settings as $setting) {
            $result[$setting->key] = $setting->getCastedValue();
        }

        return $result;
    }

    /**
     * Guarda en lote las configuraciones de un grupo.
     */
    public static function setGroup(string $group, array $values, array $types = []): void
    {
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;
            self::set($key, $value, $group, $type);
        }
    }
}
