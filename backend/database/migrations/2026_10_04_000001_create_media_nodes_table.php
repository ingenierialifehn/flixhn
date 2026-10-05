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
        if (!Schema::hasTable('media_nodes')) {
            Schema::create('media_nodes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('ip_address');
                $table->integer('port')->default(6789);
                $table->text('api_key')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_master')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_nodes');
    }
};
