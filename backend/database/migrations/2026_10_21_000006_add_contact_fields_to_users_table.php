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
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'email')) {
                    $table->string('email')->nullable()->unique()->after('username');
                }
                if (!Schema::hasColumn('users', 'phone')) {
                    $table->string('phone')->nullable()->after('customer_name');
                }
                if (!Schema::hasColumn('users', 'contract_number')) {
                    $table->string('contract_number')->nullable()->after('phone');
                }
            });

            // Permitir 'client' en el enum/columna de roles para el backend
            try {
                DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'client', 'subscriber') NOT NULL DEFAULT 'client'");
            } catch (\Throwable $e) {}
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'email')) {
                    $table->dropColumn('email');
                }
                if (Schema::hasColumn('users', 'phone')) {
                    $table->dropColumn('phone');
                }
                if (Schema::hasColumn('users', 'contract_number')) {
                    $table->dropColumn('contract_number');
                }
            });
        }
    }
};
