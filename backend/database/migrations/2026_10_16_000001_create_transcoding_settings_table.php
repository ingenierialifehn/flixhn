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
        if (!Schema::hasTable('transcoding_settings')) {
            Schema::create('transcoding_settings', function (Blueprint $table) {
                $table->id();
                $table->string('enable_hardware_acceleration')->default('no');
                $table->json('h264_encoder_settings')->nullable();
                $table->json('h265_encoder_settings')->nullable();
                $table->string('temp_path')->nullable();
                $table->boolean('enable_throttling')->default(false);
                $table->integer('audio_boost_downmix')->default(2);
                $table->boolean('allow_subtitle_extraction_on_the_fly')->default(false);
                $table->boolean('enable_software_tone_mapping')->default(true);
                $table->string('tone_mapping_resolution_limit')->default('no_limit');
                $table->string('tone_mapping_algorithm')->default('hable');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcoding_settings');
    }
};
