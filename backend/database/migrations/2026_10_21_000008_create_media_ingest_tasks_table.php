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
        if (!Schema::hasTable('media_ingest_tasks')) {
            Schema::create('media_ingest_tasks', function (Blueprint $table) {
                $table->id();
                $table->text('source_url');
                $table->string('provider', 50)->default('external'); // external, hls, vod, youtube, direct
                $table->string('title', 255)->nullable();
                $table->string('target_folder', 500);
                $table->enum('status', ['pending', 'downloading', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
                $table->unsignedTinyInteger('progress')->default(0); // 0-100%
                $table->string('download_speed', 50)->nullable(); // ej: "12.4 MB/s"
                $table->string('eta', 50)->nullable(); // ej: "03:15 min"
                $table->string('quality', 30)->default('1080p');
                $table->boolean('auto_index')->default(true);
                $table->string('output_file', 500)->nullable(); // ruta final del archivo de video .mp4/.mkv
                $table->text('error_message')->nullable();
                $table->integer('pid')->nullable();
                $table->foreignId('media_item_id')->nullable()->constrained('media_items')->nullOnDelete();
                $table->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('provider');
                $table->index('progress');
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_ingest_tasks');
    }
};
