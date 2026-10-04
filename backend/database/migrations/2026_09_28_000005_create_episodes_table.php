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
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('titles')->onDelete('cascade');
            $table->foreignId('season_id')->nullable()->constrained('seasons')->onDelete('cascade');
            $table->unsignedSmallInteger('episode_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('stream_path'); // Ruta relativa en /media o URL HLS
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('thumbnail_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
