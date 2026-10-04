<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            // Índices de alta concurrencia requeridos para ISP
            if (Schema::hasColumn('titles', 'category_id')) {
                $table->index('category_id', 'titles_category_id_idx');
            }
            if (Schema::hasColumn('titles', 'type')) {
                $table->index('type', 'titles_type_idx');
            }
            if (Schema::hasColumn('titles', 'release_year')) {
                $table->index('release_year', 'titles_release_year_idx');
            }
            if (Schema::hasColumn('titles', 'created_at')) {
                $table->index('created_at', 'titles_created_at_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->dropIndex('titles_category_id_idx');
            $table->dropIndex('titles_type_idx');
            $table->dropIndex('titles_release_year_idx');
            $table->dropIndex('titles_created_at_idx');
        });
    }
};
