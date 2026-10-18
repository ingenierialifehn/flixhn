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
                if (!Schema::hasColumn('titles', 'sort_title')) {
                    $table->string('sort_title')->nullable()->after('name');
                }
                if (!Schema::hasColumn('titles', 'release_date')) {
                    $table->string('release_date', 30)->nullable()->after('release_year');
                }
                if (!Schema::hasColumn('titles', 'community_rating')) {
                    $table->decimal('community_rating', 3, 1)->nullable()->after('description');
                }
                if (!Schema::hasColumn('titles', 'parental_rating')) {
                    $table->string('parental_rating', 20)->nullable()->after('community_rating');
                }
                if (!Schema::hasColumn('titles', 'custom_rating')) {
                    $table->string('custom_rating', 50)->nullable()->after('parental_rating');
                }
                if (!Schema::hasColumn('titles', 'video_format_3d')) {
                    $table->string('video_format_3d', 30)->nullable()->default('None')->after('custom_rating');
                }
                if (!Schema::hasColumn('titles', 'imdb_id')) {
                    $table->string('imdb_id', 30)->nullable()->after('genre');
                }
                if (!Schema::hasColumn('titles', 'tmdb_id')) {
                    $table->string('tmdb_id', 30)->nullable()->after('imdb_id');
                }
                if (!Schema::hasColumn('titles', 'tvdb_id')) {
                    $table->string('tvdb_id', 30)->nullable()->after('tmdb_id');
                }
                if (!Schema::hasColumn('titles', 'cast')) {
                    $table->json('cast')->nullable()->after('tvdb_id');
                }
                if (!Schema::hasColumn('titles', 'studios')) {
                    $table->json('studios')->nullable()->after('cast');
                }
                if (!Schema::hasColumn('titles', 'tags')) {
                    $table->json('tags')->nullable()->after('studios');
                }
                if (!Schema::hasColumn('titles', 'metadata_language')) {
                    $table->string('metadata_language', 10)->nullable()->default('es-ES')->after('tags');
                }
                if (!Schema::hasColumn('titles', 'country')) {
                    $table->string('country', 100)->nullable()->after('metadata_language');
                }
                if (!Schema::hasColumn('titles', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('country');
                }
                if (!Schema::hasColumn('titles', 'locked_fields')) {
                    $table->json('locked_fields')->nullable()->after('is_locked');
                }
            });
        }

        if (Schema::hasTable('episodes')) {
            Schema::table('episodes', function (Blueprint $table) {
                if (!Schema::hasColumn('episodes', 'sort_title')) {
                    $table->string('sort_title')->nullable()->after('title');
                }
                if (!Schema::hasColumn('episodes', 'release_date')) {
                    $table->string('release_date', 30)->nullable()->after('description');
                }
                if (!Schema::hasColumn('episodes', 'community_rating')) {
                    $table->decimal('community_rating', 3, 1)->nullable()->after('release_date');
                }
                if (!Schema::hasColumn('episodes', 'parental_rating')) {
                    $table->string('parental_rating', 20)->nullable()->after('community_rating');
                }
                if (!Schema::hasColumn('episodes', 'imdb_id')) {
                    $table->string('imdb_id', 30)->nullable()->after('parental_rating');
                }
                if (!Schema::hasColumn('episodes', 'tmdb_id')) {
                    $table->string('tmdb_id', 30)->nullable()->after('imdb_id');
                }
                if (!Schema::hasColumn('episodes', 'tvdb_id')) {
                    $table->string('tvdb_id', 30)->nullable()->after('tmdb_id');
                }
                if (!Schema::hasColumn('episodes', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('tvdb_id');
                }
                if (!Schema::hasColumn('episodes', 'locked_fields')) {
                    $table->json('locked_fields')->nullable()->after('is_locked');
                }
            });
        }

        if (Schema::hasTable('tv_channels')) {
            Schema::table('tv_channels', function (Blueprint $table) {
                if (!Schema::hasColumn('tv_channels', 'sort_title')) {
                    $table->string('sort_title')->nullable()->after('name');
                }
                if (!Schema::hasColumn('tv_channels', 'overview')) {
                    $table->text('overview')->nullable()->after('sort_title');
                }
                if (!Schema::hasColumn('tv_channels', 'community_rating')) {
                    $table->decimal('community_rating', 3, 1)->nullable()->after('overview');
                }
                if (!Schema::hasColumn('tv_channels', 'parental_rating')) {
                    $table->string('parental_rating', 20)->nullable()->after('community_rating');
                }
                if (!Schema::hasColumn('tv_channels', 'genres')) {
                    $table->json('genres')->nullable()->after('parental_rating');
                }
                if (!Schema::hasColumn('tv_channels', 'tags')) {
                    $table->json('tags')->nullable()->after('genres');
                }
                if (!Schema::hasColumn('tv_channels', 'metadata_language')) {
                    $table->string('metadata_language', 10)->nullable()->default('es-ES')->after('tags');
                }
                if (!Schema::hasColumn('tv_channels', 'country')) {
                    $table->string('country', 100)->nullable()->after('metadata_language');
                }
                if (!Schema::hasColumn('tv_channels', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('country');
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
                $columns = [
                    'sort_title', 'release_date', 'community_rating', 'parental_rating',
                    'custom_rating', 'video_format_3d', 'imdb_id', 'tmdb_id', 'tvdb_id',
                    'cast', 'studios', 'tags', 'metadata_language', 'country', 'is_locked', 'locked_fields'
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('titles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('episodes')) {
            Schema::table('episodes', function (Blueprint $table) {
                $columns = [
                    'sort_title', 'release_date', 'community_rating', 'parental_rating',
                    'imdb_id', 'tmdb_id', 'tvdb_id', 'is_locked', 'locked_fields'
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('episodes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('tv_channels')) {
            Schema::table('tv_channels', function (Blueprint $table) {
                $columns = [
                    'sort_title', 'overview', 'community_rating', 'parental_rating',
                    'genres', 'tags', 'metadata_language', 'country', 'is_locked'
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('tv_channels', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
