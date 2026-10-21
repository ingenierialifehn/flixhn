<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('titles') && !Schema::hasColumn('titles', 'provider')) {
            Schema::table('titles', function (Blueprint $table) {
                $table->string('provider', 50)->default('local')->nullable()->after('type');
                $table->index('provider', 'titles_provider_idx');
            });
        }

        // Backfill provider from media_items where available
        if (Schema::hasTable('titles') && Schema::hasTable('media_items')) {
            DB::statement("
                UPDATE titles t
                INNER JOIN media_items m ON m.title_id = t.id
                SET t.provider = m.provider
                WHERE m.provider IS NOT NULL AND m.provider != ''
            ");
            DB::statement("UPDATE titles SET provider = 'local' WHERE provider IS NULL OR provider = ''");
            DB::statement("UPDATE media_items SET provider = 'local' WHERE provider IS NULL OR provider = ''");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('titles') && Schema::hasColumn('titles', 'provider')) {
            Schema::table('titles', function (Blueprint $table) {
                $table->dropIndex('titles_provider_idx');
                $table->dropColumn('provider');
            });
        }
    }
};
