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
        // 1. Fuentes de TV (M3U, HDHomerun)
        Schema::create('tv_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('M3U');
            $table->string('type')->default('m3u'); // 'm3u', 'hdhomerun'
            $table->text('url');
            $table->string('user_agent')->nullable();
            $table->string('referer_mode')->default('Ninguno'); // 'Ninguno', 'Referer', 'Referrer', 'Ambos'
            $table->string('referrer_header')->nullable();
            $table->integer('stream_limit')->default(0); // 0 = sin límite
            $table->string('group_filter')->nullable(); // grupos separados por ;
            $table->boolean('import_guide_from_m3u')->default(true);
            $table->string('preferred_image_source')->default('Sintonizador / M3U');
            $table->boolean('allow_channel_number_mapping')->default(false);
            $table->string('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('channels_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // 2. Canales de TV en Vivo
        Schema::create('tv_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tv_source_id')->constrained('tv_sources')->cascadeOnDelete();
            $table->string('name');
            $table->text('stream_url');
            $table->text('logo_url')->nullable();
            $table->string('group_title')->default('General'); // Deportes, Noticias, Cine, etc.
            $table->string('channel_number')->nullable();
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tv_source_id', 'group_title']);
            $table->index(['is_active']);
        });

        // 3. Fuentes de Datos de Guía (EPG / XMLTV)
        Schema::create('tv_guide_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('XMLTV Guía');
            $table->string('type')->default('xmltv');
            $table->text('url');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tv_channels');
        Schema::dropIfExists('tv_sources');
        Schema::dropIfExists('tv_guide_sources');
    }
};
