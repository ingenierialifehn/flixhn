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
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category'); // metadata, livetv, subtitles, network, system
            $table->string('version')->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('icon_name')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('author')->default('FlixHN Core Team');
            $table->boolean('is_installed')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->json('default_settings')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_installed');
            $table->index('is_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
