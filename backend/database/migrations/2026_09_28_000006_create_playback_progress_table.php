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
        if (!Schema::hasTable('playback_progress')) {
            Schema::create('playback_progress', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('profiles')->onDelete('cascade');
                $table->foreignId('title_id')->constrained('titles')->onDelete('cascade');
                $table->foreignId('episode_id')->nullable()->constrained('episodes')->onDelete('cascade');
                $table->unsignedInteger('current_seconds')->default(0);
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->timestamp('last_watched_at')->useCurrent();
                $table->timestamps();

                // Evitar duplicados por perfil y título/episodio
                $table->unique(['profile_id', 'title_id', 'episode_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playback_progress');
    }
};
