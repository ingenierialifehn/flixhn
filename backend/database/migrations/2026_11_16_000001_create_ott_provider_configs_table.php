<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ott_provider_configs')) {
            Schema::create('ott_provider_configs', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 50)->unique();
                $table->string('integration_type', 50)->default('partner_api'); // partner_api, deep_link, authorized_hls, internal_vod, oauth2
                $table->string('api_endpoint', 500)->nullable();
                $table->text('api_credentials')->nullable(); // Cifrado
                $table->text('oauth_config')->nullable(); // Cifrado
                $table->string('connection_status', 50)->default('disconnected'); // connected, degraded, error, disconnected
                $table->boolean('is_active')->default(true);
                $table->json('playback_config')->nullable(); // supported_types, direct_playback, app_scheme, web_url_template, etc.
                $table->integer('timeout_seconds')->default(10);
                $table->timestamp('last_sync_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index('slug');
                $table->index('is_active');
                $table->index('integration_type');
            });

            // Semillas de proveedores base autorizados y mecanismos de interoperabilidad
            $initialProviders = [
                [
                    'name' => 'FlixHN Catálogo Local',
                    'slug' => 'local',
                    'integration_type' => 'internal_vod',
                    'api_endpoint' => null,
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => true,
                        'supported_types' => ['hls', 'mp4'],
                        'protocol' => 'hls',
                        'requires_external_app' => false,
                        'description' => 'Servidor de contenido On-Net local del ISP con distribución HLS optimizada.',
                    ]),
                    'timeout_seconds' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Transmisión HLS Autorizada ISP',
                    'slug' => 'partner_hls',
                    'integration_type' => 'authorized_hls',
                    'api_endpoint' => null,
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => true,
                        'supported_types' => ['hls', 'dash'],
                        'protocol' => 'hls',
                        'token_signature_required' => true,
                        'token_expiry_seconds' => 7200,
                        'requires_external_app' => false,
                        'description' => 'CDN y fuentes de streaming autorizadas con tokens criptográficos de reproducción.',
                    ]),
                    'timeout_seconds' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Netflix',
                    'slug' => 'netflix',
                    'integration_type' => 'deep_link',
                    'api_endpoint' => 'https://api.netflix.com/v1',
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => false,
                        'supported_types' => ['external_app', 'redirect'],
                        'requires_external_app' => true,
                        'app_scheme' => 'netflix://title/{id}',
                        'web_url_template' => 'https://www.netflix.com/title/{id}',
                        'brand_color' => '#E50914',
                        'notice' => 'Reproducción mediante aplicación oficial o portal web del titular.',
                    ]),
                    'timeout_seconds' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Disney+',
                    'slug' => 'disney',
                    'integration_type' => 'deep_link',
                    'api_endpoint' => 'https://api.disneyplus.com/v1',
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => false,
                        'supported_types' => ['external_app', 'redirect'],
                        'requires_external_app' => true,
                        'app_scheme' => 'disneyplus://play/{id}',
                        'web_url_template' => 'https://www.disneyplus.com/video/{id}',
                        'brand_color' => '#0063E5',
                        'notice' => 'Reproducción mediante aplicación oficial o portal web del titular.',
                    ]),
                    'timeout_seconds' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Max',
                    'slug' => 'max',
                    'integration_type' => 'deep_link',
                    'api_endpoint' => 'https://api.max.com/v1',
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => false,
                        'supported_types' => ['external_app', 'redirect'],
                        'requires_external_app' => true,
                        'app_scheme' => 'max://player/{id}',
                        'web_url_template' => 'https://play.max.com/video/watch/{id}',
                        'brand_color' => '#5822b4',
                        'notice' => 'Reproducción mediante aplicación oficial o portal web del titular.',
                    ]),
                    'timeout_seconds' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Amazon Prime Video',
                    'slug' => 'prime',
                    'integration_type' => 'deep_link',
                    'api_endpoint' => 'https://api.primevideo.com/v1',
                    'connection_status' => 'connected',
                    'is_active' => true,
                    'playback_config' => json_encode([
                        'direct_playback' => false,
                        'supported_types' => ['external_app', 'redirect'],
                        'requires_external_app' => true,
                        'app_scheme' => 'primevideo://watch/{id}',
                        'web_url_template' => 'https://www.primevideo.com/detail/{id}',
                        'brand_color' => '#00A8E1',
                        'notice' => 'Reproducción mediante aplicación oficial o portal web del titular.',
                    ]),
                    'timeout_seconds' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            DB::table('ott_provider_configs')->insert($initialProviders);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ott_provider_configs');
    }
};
