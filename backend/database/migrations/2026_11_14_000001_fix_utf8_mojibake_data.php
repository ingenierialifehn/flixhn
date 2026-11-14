<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $cleanMojibake = function (?string $str): ?string {
            if (empty($str)) return $str;

            // Check if contains CP437 box drawing characters (├, ┤, ┬, ┴, etc.)
            if (preg_match('/[\x{2500}-\x{257F}]/u', $str)) {
                $conv = @iconv('UTF-8', 'CP437//IGNORE', $str);
                if ($conv && mb_check_encoding($conv, 'UTF-8') && !preg_match('/[\x{2500}-\x{257F}]/u', $conv)) {
                    $str = $conv;
                }
            }

            // Check if contains Ã or Â (Latin1 / Windows-1252 double encoding)
            if (str_contains($str, 'Ã') || str_contains($str, 'Â')) {
                $conv = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $str);
                if ($conv && mb_check_encoding($conv, 'UTF-8') && !str_contains($conv, 'Ã')) {
                    $str = $conv;
                }
            }

            return $str;
        };

        // 1. Fix Categories
        $categories = DB::table('categories')->get();
        foreach ($categories as $cat) {
            $fixedName = $cleanMojibake($cat->name);
            if ($fixedName !== $cat->name) {
                DB::table('categories')->where('id', $cat->id)->update(['name' => $fixedName]);
            }
        }

        // 2. Fix TV Channels
        $channels = DB::table('tv_channels')->get();
        foreach ($channels as $ch) {
            $fixedName = $cleanMojibake($ch->name);
            $fixedGroup = $cleanMojibake($ch->group_title ?? '');
            if ($fixedName !== $ch->name || $fixedGroup !== ($ch->group_title ?? '')) {
                DB::table('tv_channels')->where('id', $ch->id)->update([
                    'name' => $fixedName,
                    'group_title' => $fixedGroup,
                ]);
            }
        }

        // 3. Fix any corrupted titles or genres in titles table
        $titles = DB::table('titles')
            ->whereRaw("name LIKE '%Ã%' OR genre LIKE '%Ã%' OR name LIKE '%├%' OR genre LIKE '%├%'")
            ->get();
        foreach ($titles as $t) {
            $fixedName = $cleanMojibake($t->name);
            $fixedGenre = $cleanMojibake($t->genre);
            $fixedDesc = $cleanMojibake($t->description);
            DB::table('titles')->where('id', $t->id)->update([
                'name' => $fixedName,
                'genre' => $fixedGenre,
                'description' => $fixedDesc,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversible
    }
};

