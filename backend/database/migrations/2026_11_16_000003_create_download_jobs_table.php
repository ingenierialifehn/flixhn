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
        if (!Schema::hasTable('download_jobs')) {
            Schema::create('download_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('job_id', 64)->nullable()->unique();
                $table->string('content_id', 100)->index();
                $table->foreignId('media_item_id')->nullable()->constrained('media_items')->nullOnDelete();
                $table->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
                $table->foreignId('media_source_id')->nullable()->constrained('media_sources')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 255);
                $table->string('provider', 50)->default('flixgrab');
                $table->text('source_url');
                $table->string('storage_path', 500);
                $table->string('hls_path', 500)->nullable();
                $table->string('hls_playlist_url', 500)->nullable();
                $table->unsignedBigInteger('total_bytes')->default(0);
                $table->unsignedBigInteger('downloaded_bytes')->default(0);
                $table->decimal('progress', 5, 2)->default(0.00);
                $table->string('download_speed', 50)->default('0.0 MB/s');
                $table->string('eta', 50)->nullable();
                $table->enum('status', ['QUEUED', 'DOWNLOADING', 'PROCESSING', 'READY', 'ERROR', 'CANCELLED'])->default('QUEUED');
                $table->unsignedInteger('priority')->default(5); // 1: Alta, 5: Normal, 10: Baja
                $table->unsignedInteger('retry_count')->default(0);
                $table->unsignedInteger('max_retries')->default(3);
                $table->text('error_message')->nullable();
                $table->unsignedInteger('segments_ready')->default(0);
                $table->boolean('can_play_progressive')->default(false);
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->string('quality', 30)->default('1080p');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('priority');
                $table->index('can_play_progressive');
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_jobs');
    }
};
