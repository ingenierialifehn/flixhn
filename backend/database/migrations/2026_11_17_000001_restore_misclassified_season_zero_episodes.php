<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Restaura episodios que fueron incorrectamente clasificados como trailers (Temporada 0)
     * debido al filtro anterior por números con cero inicial (01, 02, etc.).
     */
    public function up(): void
    {
        try {
            $seasonZeros = DB::table('seasons')->where('season_number', 0)->get();

            foreach ($seasonZeros as $s0) {
                $episodes = DB::table('episodes')->where('season_id', $s0->id)->get();

                foreach ($episodes as $ep) {
                    $titleLower = mb_strtolower(trim($ep->title ?? ''));

                    // Si es un trailer o preview auténtico, conservarlo en temporada 0
                    if (preg_match('/^(trailer|tráiler|preview|teaser|promo|detr[aá]s de c[aá]maras)/i', $titleLower)) {
                        continue;
                    }

                    // Obtener o crear Temporada 1 para el título
                    $season1 = DB::table('seasons')
                        ->where('title_id', $ep->title_id)
                        ->where('season_number', 1)
                        ->first();

                    if (!$season1) {
                        $s1Id = DB::table('seasons')->insertGetId([
                            'title_id' => $ep->title_id,
                            'season_number' => 1,
                            'title' => 'Temporada 1',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $s1Id = $season1->id;
                    }

                    // Determinar el número de episodio real
                    $epNum = 1;
                    $filename = basename($ep->file_path ?? $ep->stream_path ?? '');
                    if (preg_match('/[sS]\d+[eE](\d+)/i', $filename, $m)) {
                        $epNum = (int) $m[1];
                    } elseif (preg_match('/(?:cap[ií]tulo|episodio)\s*(\d+)/i', $titleLower, $m)) {
                        $epNum = (int) $m[1];
                    } elseif (preg_match('/^(\d+)/', $filename, $m)) {
                        $epNum = (int) $m[1];
                    } else {
                        $maxEp = DB::table('episodes')
                            ->where('season_id', $s1Id)
                            ->max('episode_number') ?? 0;
                        $epNum = $maxEp + 1;
                    }

                    // Limpiar título de posibles prefijos como "Trailer / Previa: "
                    $cleanTitle = preg_replace('/^(?:trailer\s*\/\s*previa:\s*)/i', '', $ep->title);
                    if (empty(trim($cleanTitle))) {
                        $cleanTitle = "Episodio {$epNum}";
                    }

                    DB::table('episodes')
                        ->where('id', $ep->id)
                        ->update([
                            'season_id' => $s1Id,
                            'episode_number' => max(1, $epNum),
                            'title' => $cleanTitle,
                            'updated_at' => now(),
                        ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Migration restore_misclassified_season_zero_episodes: " . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
