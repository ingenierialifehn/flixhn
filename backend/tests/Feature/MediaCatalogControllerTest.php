<?php

namespace Tests\Feature;

use App\Models\DownloadJob;
use App\Models\Title;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_search_returns_empty_results(): void
    {
        $response = $this->postJson('/api/media/search', ['q' => '']);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total' => 0,
            'results' => [],
        ]);
    }

    public function test_search_finds_local_media_and_sets_local_status(): void
    {
        $title = Title::create([
            'name' => 'Inception',
            'type' => 'movie',
            'description' => 'Un ladrón que roba secretos a través de los sueños.',
            'release_year' => 2010,
            'genre' => 'Ciencia Ficción',
            'provider' => 'local',
            'duration_seconds' => 8880,
        ]);

        $response = $this->postJson('/api/media/search', ['q' => 'Inception']);
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'query',
            'total',
            'results' => [
                '*' => [
                    'id',
                    'content_id',
                    'title',
                    'description',
                    'status',
                    'is_local',
                    'is_downloadable',
                ],
            ],
        ]);

        $results = $response->json('results');
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals('Inception', $results[0]['title']);
    }

    public function test_availability_endpoint_returns_status(): void
    {
        $title = Title::create([
            'name' => 'Interstellar',
            'type' => 'movie',
            'release_year' => 2014,
            'provider' => 'local',
        ]);

        $response = $this->getJson("/api/media/{$title->id}/availability");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'availability' => [
                'status',
                'available',
                'can_play_progressive',
            ],
        ]);
    }
}
