<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ott_content_cache')) {
            Schema::create('ott_content_cache', function (Blueprint $table) {
                $table->id();
                $table->string('external_id', 150)->nullable()->index();
                $table->string('title', 255)->index();
                $table->string('original_title', 255)->nullable();
                $table->enum('type', ['Serie', 'Película'])->default('Serie')->index();
                $table->unsignedSmallInteger('year')->nullable();
                $table->string('poster_path', 512)->nullable();
                $table->string('backdrop_path', 512)->nullable();
                $table->string('platform', 50)->default('NETFLIX')->index();
                $table->string('quality_available', 20)->default('HD');
                $table->text('synopsis')->nullable();
                $table->string('local_file_path', 512)->nullable();
                $table->enum('status', ['Available', 'En Catálogo', 'Descargando'])->default('Available');
                $table->timestamps();

                $table->unique(['external_id', 'platform']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ott_content_cache');
    }
};
