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
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category'); // application, database, conversions, library, livetv, statistics
            $table->text('description');
            $table->timestamp('last_run_at')->nullable();
            $table->decimal('last_duration_seconds', 8, 2)->default(0);
            $table->string('status')->default('idle'); // idle, running, completed, failed
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
