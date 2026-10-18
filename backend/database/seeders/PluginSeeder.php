<?php

namespace Database\Seeders;

use App\Models\Plugin;
use Illuminate\Database\Seeder;

class PluginSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalog = Plugin::defaultCatalog();

        foreach ($catalog as $item) {
            $existing = Plugin::where('slug', $item['slug'])->first();

            if (!$existing) {
                Plugin::create([
                    'slug' => $item['slug'],
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'version' => $item['version'],
                    'description' => $item['description'],
                    'icon_name' => $item['icon_name'],
                    'icon_url' => $item['icon_url'] ?? null,
                    'author' => $item['author'] ?? 'FlixHN Core Team',
                    'is_installed' => $item['is_installed'],
                    'is_enabled' => $item['is_enabled'],
                    'default_settings' => $item['default_settings'],
                    'settings' => $item['default_settings'],
                ]);
            } else {
                // Actualizar metadatos del catálogo respetando si el usuario ya modificó settings o estado
                $existing->update([
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'version' => $item['version'],
                    'description' => $item['description'],
                    'icon_name' => $item['icon_name'],
                    'author' => $item['author'] ?? 'FlixHN Core Team',
                    'default_settings' => $item['default_settings'],
                ]);
            }
        }
    }
}
