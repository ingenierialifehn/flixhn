<?php

namespace Database\Seeders;

use App\Models\ScheduledTask;
use Illuminate\Database\Seeder;

class ScheduledTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaults = ScheduledTask::defaultTasks();

        foreach ($defaults as $taskData) {
            ScheduledTask::updateOrCreate(
                ['key' => $taskData['key']],
                [
                    'name' => $taskData['name'],
                    'category' => $taskData['category'],
                    'description' => $taskData['description'],
                    'status' => 'idle',
                ]
            );
        }
    }
}
