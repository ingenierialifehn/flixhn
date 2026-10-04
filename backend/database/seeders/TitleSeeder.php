<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Title;
use App\Models\Season;
use App\Models\Episode;
use App\Services\MediaSyncService;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Sincroniza exclusivamente el contenido y bibliotecas reales del servidor de medios.
     * Se eliminan completamente los datos ficticios/hardcodeados.
     */
    public function run(): void
    {
        // 1. Limpieza total de títulos ficticios huérfanos previos
        Episode::query()->delete();
        Season::query()->delete();
        Title::query()->delete();

        // 2. Ejecutar la sincronización real con el almacenamiento del servidor (/var/media)
        $syncService = app(MediaSyncService::class);
        $syncService->sync();
    }
}
