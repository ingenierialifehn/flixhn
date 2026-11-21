<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flix_search_history')) {
            Schema::create('flix_search_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('search_type', 32)->default('movie');
                $table->string('query', 255);
                $table->timestamp('last_modified')->useCurrent()->index();
                $table->timestamps();

                $table->unique(['user_id', 'search_type', 'query'], 'flix_search_history_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flix_search_history');
    }
};
