<?php

namespace Tests\Feature;

use App\Models\DownloadJob;
use App\Models\Title;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StreamingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_play_non_existent_media_returns_404(): void
    {
        $response = $this->getJson('/api/media/non-existent-9999/play');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_play_downloading_media_without_enough_segments_returns_202(): void
    {
        $job = DownloadJob::create([
            'job_id' => 'stream-test-job-1',
            'content_id' => 'stream-content-1',
            'title' => 'Buffering Movie',
            'source_url' => 'https://example.com/movie.mp4',
            'storage_path' => '/storage/media/movie.mp4',
            'status' => 'DOWNLOADING',
            'progress' => 12.0,
            'download_speed' => '5.0 MB/s',
            'segments_ready' => 0,
            'can_play_progressive' => false,
        ]);

        $response = $this->getJson("/api/media/{$job->content_id}/play");
        $response->assertStatus(202);
        $response->assertJson([
            'success' => false,
            'status' => 'DOWNLOADING',
            'can_play_progressive' => false,
            'segments_ready' => 0,
        ]);
    }

    public function test_play_downloading_media_with_progressive_ready_returns_200_and_hls(): void
    {
        $job = DownloadJob::create([
            'job_id' => 'stream-test-job-2',
            'content_id' => 'stream-content-2',
            'title' => 'Progressive Ready Movie',
            'source_url' => 'https://example.com/movie2.mp4',
            'storage_path' => '/storage/media/movie2.mp4',
            'status' => 'DOWNLOADING',
            'progress' => 35.0,
            'download_speed' => '14.0 MB/s',
            'segments_ready' => 3,
            'can_play_progressive' => true,
        ]);

        $response = $this->getJson("/api/media/{$job->content_id}/play");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'DOWNLOADING_PROGRESSIVE_READY',
            'mode' => 'hls',
            'is_progressive' => true,
        ]);
        $this->assertStringContainsString('playlist.m3u8', $response->json('stream_url'));
    }

    public function test_serve_hls_returns_404_if_file_not_found(): void
    {
        $response = $this->get('/api/streaming/hls/job-not-found/playlist.m3u8');
        $response->assertStatus(404);
    }
}
