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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Películas", "Series y Temporadas", "Infantiles", "Documentales", "Crimen", "Ciencia Ficción"
            $table->string('slug')->unique(); // "peliculas", "series", "infantiles", "documentales", "crimen", "ficcion"
            $table->string('type')->default('movie'); // 'movie', 'series', 'mixed'
            $table->string('folder_path')->nullable(); // 'movies', 'series', 'infantiles', etc.
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Agregar category_id y source_path a titles si no existen
        if (Schema::hasTable('titles') && !Schema::hasColumn('titles', 'category_id')) {
            Schema::table('titles', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('type')->constrained('categories')->nullOnDelete();
                $table->string('source_path')->nullable()->after('stream_path');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('titles') && Schema::hasColumn('titles', 'category_id')) {
            Schema::table('titles', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropColumn(['category_id', 'source_path']);
            });
        }
        Schema::dropIfExists('categories');
    }
};
