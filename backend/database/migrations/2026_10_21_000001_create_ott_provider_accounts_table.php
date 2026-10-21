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
        if (!Schema::hasTable('ott_provider_accounts')) {
            Schema::create('ott_provider_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 50)->unique(); // netflix, disney, hbo_max
                $table->string('account_name', 150); // Alias o correo de la cuenta
                $table->longText('credentials'); // JSON cifrado con Crypt::encryptString
                $table->enum('status', ['connected', 'disconnected', 'syncing', 'error'])->default('disconnected');
                $table->timestamp('last_sync_at')->nullable();
                $table->boolean('auto_download')->default(false);
                $table->string('quality_profile', 30)->default('1080p'); // 1080p, 4K HDR, 720p
                $table->json('session_metadata')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index('provider');
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ott_provider_accounts');
    }
};
