<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\Title;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FixOttPipelineCommand extends Command
{
    protected $signature = 'flixhn:ott-pipeline {action=status} {--query=}';
    protected $description = 'Diagnosticar y reparar pipeline de descargas OTT y buscar contenido real';

    public function handle(): int
    {
        $action = $this->argument('action');
        $query = $this->option('query') ?: 'loki';

        $this->info("Acción: {$action}");

        if ($action === 'status') {
            $this->showStatus();
        } elseif ($action === 'search') {
            $this->searchAll($query);
        } elseif ($action === 'purge-fakes') {
            $this->purgeFakeFiles();
        }

        return 0;
    }

    protected function showStatus(): void
    {
        $this->info("--- TAREAS RECIENTES DE MEDIA INGEST ---");
        $ingests = MediaIngestTask::latest()->take(5)->get();
        foreach ($ingests as $t) {
            $this->line("#{$t->id} [{$t->provider}] '{$t->title}' -> {$t->status} ({$t->progress}%) File: {$t->output_file}");
        }

        $this->info("--- TAREAS RECIENTES DE OTT DOWNLOAD ---");
        $otts = OttDownloadTask::latest()->take(5)->get();
        foreach ($otts as $t) {
            $this->line("#{$t->id} [{$t->provider}] '{$t->title}' -> {$t->status} ({$t->progress}%) File: {$t->file_path}");
        }
    }

    protected function searchAll(string $q): void
    {
        $this->info("Buscando '{$q}' en base de datos...");
        $titles = Title::where('name', 'LIKE', "%{$q}%")->get(['id', 'name', 'type', 'stream_path', 'file_path']);
        $this->info("Titles encontrados: " . $titles->count());
        foreach ($titles as $t) {
            $this->line("Title #{$t->id}: {$t->name} ({$t->type}) File: {$t->file_path}");
        }

        $items = MediaItem::where('title', 'LIKE', "%{$q}%")->get(['id', 'title', 'file_path']);
        $this->info("MediaItems encontrados: " . $items->count());
        foreach ($items as $m) {
            $this->line("MediaItem #{$m->id}: {$m->title} File: {$m->file_path}");
        }

        $channels = Channel::where('name', 'LIKE', "%{$q}%")->get(['id', 'name', 'stream_url']);
        $this->info("Channels encontrados: " . $channels->count());
        foreach ($channels as $c) {
            $this->line("Channel #{$c->id}: {$c->name} URL: {$c->stream_url}");
        }
    }

    protected function purgeFakeFiles(): void
    {
        $this->info("Purgando archivos falsos de YouTube en /mnt/media_library/Disk5/FlixHN/...");
        $files = [
            '/mnt/media_library/Disk5/FlixHN/disney/loki.mp4',
            '/mnt/media_library/Disk5/FlixHN/disney/avengers-endgame-encore.mp4',
            '/mnt/media_library/Disk5/FlixHN/disney/thor-3d.mp4',
            '/mnt/media_library/Disk5/FlixHN/netflix/el-mentalista.mp4',
        ];

        foreach ($files as $f) {
            if (File::exists($f)) {
                $size = File::size($f);
                File::delete($f);
                $this->warn("Eliminado archivo falso: {$f} (" . number_format($size / 1024 / 1024, 1) . " MB)");
            } else {
                $this->line("No existe: {$f}");
            }
        }
    }
}