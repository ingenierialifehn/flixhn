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
        if (!Schema::hasTable('user_profiles')) {
            Schema::create('user_profiles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('name');
                $table->string('avatar')->default('#E50914');
                $table->boolean('is_kids')->default(false);
                $table->string('pin', 10)->nullable();
                $table->json('preferences')->nullable();
                $table->timestamps();
            });
        }

        // Migración transparente de perfiles previos a la nueva tabla user_profiles
        if (Schema::hasTable('profiles') && Schema::hasTable('user_profiles')) {
            $existingCount = DB::table('user_profiles')->count();
            if ($existingCount === 0) {
                $oldProfiles = DB::table('profiles')->get();
                foreach ($oldProfiles as $p) {
                    DB::table('user_profiles')->insert([
                        'id' => $p->id,
                        'user_id' => $p->user_id,
                        'name' => $p->name,
                        'avatar' => $p->avatar_color ?? '#E50914',
                        'is_kids' => $p->is_kids ?? false,
                        'pin' => null,
                        'preferences' => null,
                        'created_at' => $p->created_at ?? now(),
                        'updated_at' => $p->updated_at ?? now(),
                    ]);
                }
            }
        }

        // Actualizar clave foránea en playback_progress para referenciar user_profiles
        if (Schema::hasTable('playback_progress')) {
            try {
                Schema::table('playback_progress', function (Blueprint $table) {
                    $table->dropForeign(['profile_id']);
                });
            } catch (\Throwable $e) {
                // Continuar si la FK ya no existía
            }

            try {
                Schema::table('playback_progress', function (Blueprint $table) {
                    $table->foreign('profile_id')->references('id')->on('user_profiles')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Continuar si la FK ya estaba en user_profiles
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('playback_progress') && Schema::hasTable('profiles')) {
            try {
                Schema::table('playback_progress', function (Blueprint $table) {
                    $table->dropForeign(['profile_id']);
                    $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
                });
            } catch (\Throwable $e) {}
        }

        Schema::dropIfExists('user_profiles');
    }
};
