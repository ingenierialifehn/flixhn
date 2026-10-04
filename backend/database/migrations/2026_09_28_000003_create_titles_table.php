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
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['movie', 'series'])->default('movie');
            $table->text('description');
            $table->string('poster_url');
            $table->string('backdrop_url');
            $table->unsignedSmallInteger('release_year');
            $table->string('genre');
            $table->boolean('is_featured')->default(false);
            $table->string('stream_path')->nullable(); // Para películas: ruta local HLS/MP4 o URL
            $table->unsignedInteger('duration_seconds')->nullable(); // Para películas
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
