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
        if (Schema::hasTable('titles')) {
            Schema::table('titles', function (Blueprint $table) {
                if (Schema::hasColumn('titles', 'stream_path')) {
                    $table->text('stream_path')->nullable()->change();
                }
                if (Schema::hasColumn('titles', 'source_path')) {
                    $table->text('source_path')->nullable()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->string('stream_path', 255)->nullable()->change();
            $table->string('source_path', 255)->nullable()->change();
        });
    }
};
