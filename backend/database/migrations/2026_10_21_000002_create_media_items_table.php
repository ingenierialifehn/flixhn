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
        if (!Schema::hasTable('media_items')) {
            Schema::create('media_items', function (Blueprint $table) {
                $table->id();
                $table->string('title', 255);
                $table->string('original_title', 255)->nullable();
                $table->string('type', 30)->default('movie'); // movie, series, episode
                $table->string('provider', 50)->nullable(); // netflix, disney, hbo_max, local
                $table->string('external_id', 100)->nullable();
                $table->text('overview')->nullable();
                $table->text('poster_url')->nullable();
                $table->text('backdrop_url')->nullable();
                $table->unsignedSmallInteger('release_year')->nullable();
                $table->string('genre', 100)->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->string('file_path', 500)->nullable();
                $table->text('stream_url')->nullable();
                $table->string('quality', 30)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('provider');
                $table->index('type');
                $table->index('external_id');
                $table->index('title_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
