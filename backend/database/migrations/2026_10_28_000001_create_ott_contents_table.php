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
        if (!Schema::hasTable('ott_contents')) {
            Schema::create('ott_contents', function (Blueprint $table) {
                $table->id();
                $table->string('title', 255);
                $table->string('original_title', 255)->nullable();
                $table->string('poster_path', 500)->nullable();
                $table->string('backdrop_path', 500)->nullable();
                $table->string('platform_id', 50)->default('netflix');
                $table->string('quality_available', 30)->default('4K HDR');
                $table->enum('download_status', ['pending', 'downloading', 'completed', 'failed'])->default('pending');
                $table->string('local_file_path', 500)->nullable();
                $table->timestamp('last_sync_date')->nullable();
                $table->timestamps();

                $table->index('platform_id');
                $table->index('download_status');
                $table->index('title');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ott_contents');
    }
};
