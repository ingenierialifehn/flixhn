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
        if (!Schema::hasTable('media_ingestion_tasks')) {
            Schema::create('media_ingestion_tasks', function (Blueprint $table) {
                $table->id();
                $table->enum('content_type', ['movie', 'series'])->default('movie');
                $table->string('title', 255);
                $table->unsignedSmallInteger('release_year')->nullable();
                $table->string('imdb_id', 32)->nullable();
                $table->string('tmdb_id', 32)->nullable();
                $table->text('overview')->nullable();
                $table->unsignedSmallInteger('season_number')->nullable();
                $table->unsignedSmallInteger('episode_number')->nullable();
                $table->string('episode_title', 255)->nullable();
                $table->text('source_url');
                $table->string('source_type', 40)->default('direct_http');
                $table->string('target_disk', 255);
                $table->string('target_folder', 500);
                $table->string('output_file', 500)->nullable();
                $table->string('quality', 30)->default('1080p');
                $table->enum('status', ['pending', 'downloading', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->string('download_speed', 50)->nullable();
                $table->string('eta', 50)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->integer('pid')->nullable();
                $table->text('error_log')->nullable();
                $table->longText('logs')->nullable();
                $table->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
                $table->foreignId('episode_id')->nullable()->constrained('episodes')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('content_type');
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_ingestion_tasks');
    }
};