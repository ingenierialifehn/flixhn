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
        if (!Schema::hasTable('ott_download_tasks')) {
            Schema::create('ott_download_tasks', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 50); // netflix, disney, hbo_max
                $table->foreignId('ott_account_id')->nullable()->constrained('ott_provider_accounts')->nullOnDelete();
                $table->string('title', 255);
                $table->string('media_type', 30)->default('movie'); // movie, series, episode
                $table->string('external_id', 100)->nullable();
                $table->unsignedInteger('season_number')->nullable();
                $table->unsignedInteger('episode_number')->nullable();
                $table->string('quality', 30)->default('1080p');
                $table->enum('status', ['pending', 'downloading', 'completed', 'failed', 'cancelled'])->default('pending');
                $table->unsignedTinyInteger('progress')->default(0); // 0-100%
                $table->string('download_speed', 50)->nullable(); // ej: "45.2 MB/s"
                $table->string('eta', 50)->nullable(); // ej: "02:15 min"
                $table->unsignedBigInteger('total_bytes')->nullable();
                $table->unsignedBigInteger('downloaded_bytes')->nullable();
                $table->string('file_path', 500)->nullable(); // ej: /storage/media/movies/Inception.2010.mkv
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable(); // poster_url, backdrop_url, overview, etc.
                $table->foreignId('media_item_id')->nullable()->constrained('media_items')->nullOnDelete();
                $table->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('provider');
                $table->index('status');
                $table->index('progress');
                $table->index('media_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ott_download_tasks');
    }
};
