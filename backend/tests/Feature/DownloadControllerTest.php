<?php

namespace Tests\Feature;

use App\Models\DownloadJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_request_requires_source_url(): void
    {
        $response = $this->postJson('/api/download/request', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['source_url', 'url']);
    }

    public function test_download_request_creates_job_successfully(): void
    {
        $payload = [
            'source_url' => 'https://archive.org/download/test/sample.mp4',
            'title' => 'Sample Test Video',
            'content_id' => 'sample-test-1',
            'priority' => 3,
            'quality' => '1080p',
        ];

        $response = $this->postJson('/api/download/request', $payload);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'status' => 'QUEUED',
        ]);

        $this->assertDatabaseHas('download_jobs', [
            'content_id' => 'sample-test-1',
            'title' => 'Sample Test Video',
            'status' => 'QUEUED',
        ]);
    }

    public function test_download_request_prevents_duplicate_active_jobs(): void
    {
        $payload = [
            'source_url' => 'https://archive.org/download/test/sample2.mp4',
            'title' => 'Sample Video 2',
            'content_id' => 'sample-test-2',
        ];

        $first = $this->postJson('/api/download/request', $payload);
        $first->assertStatus(201);

        $second = $this->postJson('/api/download/request', $payload);
        $second->assertStatus(200); // 200 OK porque ya existía

        $this->assertEquals(1, DownloadJob::where('content_id', 'sample-test-2')->count());
    }

    public function test_get_job_status_returns_data(): void
    {
        $job = DownloadJob::create([
            'job_id' => 'test-status-job-1',
            'content_id' => 'status-1',
            'title' => 'Status Check Movie',
            'source_url' => 'https://example.com/video.mp4',
            'storage_path' => '/storage/media/video.mp4',
            'status' => 'DOWNLOADING',
            'progress' => 45.5,
            'download_speed' => '12.4 MB/s',
            'eta' => '01:30 min',
        ]);

        $response = $this->getJson("/api/download/{$job->id}/status");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'DOWNLOADING',
            'progress' => 45.5,
            'download_speed' => '12.4 MB/s',
        ]);
    }

    public function test_cancel_job_updates_status(): void
    {
        $job = DownloadJob::create([
            'job_id' => 'test-cancel-job-1',
            'content_id' => 'cancel-1',
            'title' => 'Job To Cancel',
            'source_url' => 'https://example.com/cancel.mp4',
            'storage_path' => '/storage/media/cancel.mp4',
            'status' => 'QUEUED',
        ]);

        $response = $this->postJson("/api/download/{$job->id}/cancel");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('download_jobs', [
            'id' => $job->id,
            'status' => 'CANCELLED',
        ]);
    }

    public function test_admin_jobs_endpoint_returns_metrics(): void
    {
        $response = $this->getJson('/api/admin/flixgrab/jobs');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'jobs',
            'metrics' => [
                'active_tasks',
                'completed_tasks',
                'failed_tasks',
                'total_speed',
                'disk_free_formatted',
            ],
        ]);
    }
}
