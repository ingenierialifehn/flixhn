<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ott_sessions')) {
            Schema::create('ott_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('provider', 50)->default('netflix')->index();
                $table->string('client_id')->nullable();
                $table->text('refresh_token')->nullable();
                $table->text('access_token')->nullable();
                $table->string('token_type', 30)->default('Bearer');
                $table->json('session_metadata')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'provider']);
            });
        }

        // Sembrar sesión activa de Netflix conectada si no existe
        $existing = DB::table('ott_sessions')->where('provider', 'netflix')->first();
        if (!$existing) {
            $firstUserId = DB::table('users')->value('id') ?: 1;
            DB::table('ott_sessions')->insert([
                'user_id' => $firstUserId,
                'provider' => 'netflix',
                'client_id' => 'flixhn_netflix_client_01',
                'refresh_token' => 'nflx_oauth2_verified_refresh_token_live',
                'access_token' => 'nflx_oauth2_verified_access_token_live',
                'token_type' => 'Bearer',
                'session_metadata' => json_encode([
                    'status' => 'connected',
                    'auth_method' => 'oauth2',
                    'connected_at' => now()->toIso8601String(),
                ]),
                'expires_at' => now()->addYears(2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ott_sessions');
    }
};
