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
        if (!Schema::hasTable('database_settings')) {
            Schema::create('database_settings', function (Blueprint $table) {
                $table->id();
                $table->integer('db_cache_size_mb')->default(96);
                $table->boolean('optimize_on_shutdown')->default(false);
                $table->integer('analysis_row_limit')->default(400);
                $table->boolean('vacuum_on_next_startup')->default(false);
                $table->boolean('auto_backup_enabled')->default(true);
                $table->string('auto_backup_frequency')->default('daily'); // 'daily', 'weekly'
                $table->integer('auto_backup_retention_days')->default(7);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_settings');
    }
};
