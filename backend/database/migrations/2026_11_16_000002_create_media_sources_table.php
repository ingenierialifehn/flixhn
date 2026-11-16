<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('media_sources')) {
            Schema::create('media_sources', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 50)->unique();
                $table->string('type', 50)->default('authorized_cdn'); // local, authorized_cdn, external_api, direct_stream
                $table->string('base_url', 500)->nullable();
                $table->string('api_key', 255)->nullable();
                $table->json('auth_headers')->nullable();
                $table->unsignedInteger('priority')->default(1);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_downloadable')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->index('is_active');
                $table->index('priority');
                $table->index('type');
            });

            // Sembrar fuentes autorizadas por defecto para FlixHN
            DB::table('media_sources')->insertOrIgnore([
                [
                    'name' => 'Almacenamiento Local FlixHN',
                    'slug' => 'local',
                    'type' => 'local',
                    'base_url' => '/storage/media',
                    'auth_headers' => null,
                    'priority' => 1,
                    'is_active' => true,
                    'is_downloadable' => false,
                    'settings' => json_encode(['folder' => 'storage/media', 'protocol' => 'file']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Internet Archive (Dominio Público)',
                    'slug' => 'archive_org',
                    'type' => 'authorized_cdn',
                    'base_url' => 'https://archive.org',
                    'auth_headers' => null,
                    'priority' => 2,
                    'is_active' => true,
                    'is_downloadable' => true,
                    'settings' => json_encode(['rate_limit' => 50, 'concurrent_chunks' => 3]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Blender Foundation Open Movies',
                    'slug' => 'blender_open',
                    'type' => 'authorized_cdn',
                    'base_url' => 'https://download.blender.org',
                    'auth_headers' => null,
                    'priority' => 3,
                    'is_active' => true,
                    'is_downloadable' => true,
                    'settings' => json_encode(['rate_limit' => 100]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Proveedor Streaming Asociado (HLS On-Net)',
                    'slug' => 'partner_hls',
                    'type' => 'direct_stream',
                    'base_url' => 'http://localhost:7000/media',
                    'auth_headers' => null,
                    'priority' => 4,
                    'is_active' => true,
                    'is_downloadable' => true,
                    'settings' => json_encode(['protocol' => 'hls', 'progressive_buffer_sec' => 10]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_sources');
    }
};
