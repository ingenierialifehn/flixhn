<?php

namespace Tests\Feature;

use App\Models\OttProviderConfig;
use App\Models\Title;
use App\Models\TvChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveOttControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Configurar proveedores de prueba
        OttProviderConfig::create([
            'name' => 'FlixHN Catálogo Local',
            'slug' => 'local',
            'integration_type' => 'internal_vod',
            'is_active' => true,
            'connection_status' => 'connected',
            'playback_config' => ['direct_playback' => true, 'supported_types' => ['hls']],
        ]);

        OttProviderConfig::create([
            'name' => 'Netflix',
            'slug' => 'netflix',
            'integration_type' => 'deep_link',
            'is_active' => true,
            'connection_status' => 'connected',
            'playback_config' => [
                'direct_playback' => false,
                'supported_types' => ['external_app', 'redirect'],
                'requires_external_app' => true,
                'app_scheme' => 'netflix://title/{id}',
                'web_url_template' => 'https://www.netflix.com/title/{id}',
            ],
        ]);

        OttProviderConfig::create([
            'name' => 'Disney+',
            'slug' => 'disney',
            'integration_type' => 'deep_link',
            'is_active' => true,
            'connection_status' => 'connected',
            'playback_config' => [
                'direct_playback' => false,
                'supported_types' => ['external_app', 'redirect'],
                'requires_external_app' => true,
                'app_scheme' => 'disneyplus://play/{id}',
                'web_url_template' => 'https://www.disneyplus.com/video/{id}',
            ],
        ]);

        OttProviderConfig::create([
            'name' => 'Max',
            'slug' => 'max',
            'integration_type' => 'deep_link',
            'is_active' => true,
            'connection_status' => 'connected',
            'playback_config' => [
                'direct_playback' => false,
                'supported_types' => ['external_app', 'redirect'],
                'requires_external_app' => true,
                'app_scheme' => 'max://player/{id}',
                'web_url_template' => 'https://play.max.com/video/watch/{id}',
            ],
        ]);

        OttProviderConfig::create([
            'name' => 'Transmisión HLS Autorizada ISP',
            'slug' => 'partner_hls',
            'integration_type' => 'authorized_hls',
            'is_active' => true,
            'connection_status' => 'connected',
            'playback_config' => [
                'direct_playback' => true,
                'supported_types' => ['hls'],
                'token_expiry_seconds' => 3600,
            ],
        ]);
    }

    public function test_search_endpoint_returns_json_structure(): void
    {
        Title::create([
            'name' => 'Inception',
            'slug' => 'inception',
            'type' => 'movie',
            'release_year' => 2010,
            'provider' => 'local',
            'stream_path' => '/api/stream/title/1',
        ]);

        $response = $this->postJson('/api/ott/search', [
            'q' => 'Inception',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'query' => 'Inception',
            ])
            ->assertJsonStructure([
                'success',
                'query',
                'total',
                'results' => [
                    '*' => [
                        'id',
                        'title',
                        'provider',
                        'playback_type',
                    ],
                ],
            ]);
    }

    public function test_stream_link_for_netflix_external(): void
    {
        $response = $this->postJson('/api/ott/stream-link', [
            'content_id' => 'nflx_812345',
            'provider' => 'netflix',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'content_id' => 'nflx_812345',
                'provider' => 'netflix',
                'playback_type' => 'OTT-EXTERNAL',
                'action' => 'open_app',
                'direct_playback' => false,
                'requires_external_app' => true,
                'app_url' => 'netflix://title/812345',
                'web_url' => 'https://www.netflix.com/title/812345',
            ]);
    }

    public function test_stream_link_for_disney_external(): void
    {
        $response = $this->postJson('/api/ott/stream-link', [
            'content_id' => 'disney_999',
            'provider' => 'disney',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'playback_type' => 'OTT-EXTERNAL',
                'action' => 'open_app',
                'app_url' => 'disneyplus://play/999',
                'web_url' => 'https://www.disneyplus.com/video/999',
            ]);
    }

    public function test_stream_link_for_max_external(): void
    {
        $response = $this->postJson('/api/ott/stream-link', [
            'content_id' => 'max_777',
            'provider' => 'max',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'playback_type' => 'OTT-EXTERNAL',
                'action' => 'open_app',
                'app_url' => 'max://player/777',
                'web_url' => 'https://play.max.com/video/watch/777',
            ]);
    }

    public function test_stream_link_for_local_content(): void
    {
        $title = Title::create([
            'name' => 'El Hobbit',
            'slug' => 'el-hobbit',
            'type' => 'movie',
            'release_year' => 2012,
            'provider' => 'local',
            'stream_path' => '/api/stream/title/50',
        ]);

        $response = $this->postJson('/api/ott/stream-link', [
            'content_id' => (string) $title->id,
            'provider' => 'local',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'provider' => 'local',
                'playback_type' => 'LOCAL-HLS',
                'action' => 'play',
                'direct_playback' => true,
                'stream_url' => '/api/stream/title/50',
            ]);
    }

    public function test_stream_link_for_disabled_provider_returns_unavailable(): void
    {
        $cfg = OttProviderConfig::bySlug('netflix')->first();
        $cfg->update(['is_active' => false]);

        $response = $this->postJson('/api/ott/stream-link', [
            'content_id' => 'nflx_123',
            'provider' => 'netflix',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'playback_type' => 'OTT-UNAVAILABLE',
            ]);
    }

    public function test_providers_endpoint_hides_secrets(): void
    {
        $cfg = OttProviderConfig::bySlug('netflix')->first();
        $cfg->setEncryptedCredentials(['api_key' => 'super_secret_key_12345']);
        $cfg->save();

        $response = $this->getJson('/api/ott/providers');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonMissing(['super_secret_key_12345'])
            ->assertJsonMissing(['api_credentials'])
            ->assertJsonMissing(['oauth_config']);
    }

    public function test_provider_status_endpoint(): void
    {
        $response = $this->getJson('/api/ott/provider/local/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'slug' => 'local',
                    'status' => 'connected',
                ],
            ]);
    }
}
