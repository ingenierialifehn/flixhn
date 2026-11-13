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
                if (!Schema::hasColumn('titles', 'file_path')) {
                    $table->string('file_path', 500)->nullable()->after('stream_path');
                }
                if (!Schema::hasColumn('titles', 'source_type')) {
                    $table->string('source_type', 50)->default('local')->nullable()->after('provider');
                }
            });
        }

        if (Schema::hasTable('episodes')) {
            Schema::table('episodes', function (Blueprint $table) {
                if (!Schema::hasColumn('episodes', 'file_path')) {
                    $table->string('file_path', 500)->nullable()->after('stream_path');
                }
                if (!Schema::hasColumn('episodes', 'source_type')) {
                    $table->string('source_type', 50)->default('local')->nullable()->after('duration_seconds');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('titles')) {
            Schema::table('titles', function (Blueprint $table) {
                if (Schema::hasColumn('titles', 'file_path')) {
                    $table->dropColumn('file_path');
                }
                if (Schema::hasColumn('titles', 'source_type')) {
                    $table->dropColumn('source_type');
                }
            });
        }

        if (Schema::hasTable('episodes')) {
            Schema::table('episodes', function (Blueprint $table) {
                if (Schema::hasColumn('episodes', 'file_path')) {
                    $table->dropColumn('file_path');
                }
                if (Schema::hasColumn('episodes', 'source_type')) {
                    $table->dropColumn('source_type');
                }
            });
        }
    }
};

