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
        if (!Schema::hasTable('device_sessions')) {
            Schema::create('device_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('device_id')->unique();
                $table->string('custom_name')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('client_identifier')->nullable();
                $table->string('device_type')->default('browser'); // android, ios, smart_tv, browser, desktop
                $table->string('os_name')->nullable();
                $table->string('browser_name')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('current_media_type')->default('idle'); // movie, episode, livetv, idle
                $table->string('current_media_title')->nullable();
                $table->text('current_media_poster')->nullable();
                $table->unsignedInteger('playback_position_seconds')->default(0);
                $table->unsignedInteger('media_duration_seconds')->default(0);
                $table->string('playback_state')->default('stopped'); // playing, paused, stopped
                $table->boolean('is_blocked')->default(false);
                $table->timestamp('session_started_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();

                // Metadatos de conexión y telemetría avanzada
                $table->text('user_agent')->nullable();
                $table->string('screen_resolution')->nullable();
                $table->string('connection_type')->nullable();
                $table->string('bitrate')->nullable();
                $table->string('remote_command')->nullable(); // pause, resume, stop, blocked
                $table->timestamp('command_issued_at')->nullable();

                $table->timestamps();

                $table->index('device_id');
                $table->index('device_type');
                $table->index('playback_state');
                $table->index('is_blocked');
                $table->index('last_heartbeat_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
