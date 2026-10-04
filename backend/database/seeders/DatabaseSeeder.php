<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Title;
use App\Models\Season;
use App\Models\Episode;
use App\Models\PlaybackProgress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Cuenta Administradora Inicial
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('password'),
                'role' => 'admin',
                'customer_name' => 'Administrador de Red ISP',
                'assigned_ip' => null,
                'max_screens' => 5,
                'is_active' => true,
            ]
        );

        $admin->profiles()->firstOrCreate(
            ['name' => 'Admin'],
            [
                'avatar_color' => '#E50914',
                'is_kids' => false,
            ]
        );

        // 2. Suscriptor de Prueba
        $subscriber = User::firstOrCreate(
            ['username' => 'cliente1'],
            [
                'password' => Hash::make('cliente123'),
                'role' => 'subscriber',
                'customer_name' => 'Familia Martínez',
                'assigned_ip' => '192.168.100.45',
                'max_screens' => 3,
                'is_active' => true,
            ]
        );

        $profileMain = $subscriber->profiles()->firstOrCreate(
            ['name' => 'Papá'],
            [
                'avatar_color' => '#E50914',
                'is_kids' => false,
            ]
        );

        $profileKids = $subscriber->profiles()->firstOrCreate(
            ['name' => 'Niños'],
            [
                'avatar_color' => '#0071EB',
                'is_kids' => true,
            ]
        );

        // 3. Catálogo Inicial de Películas y Series On-Net
        $this->call(TitleSeeder::class);

        // 4. Parámetros Base de Red / Conexiones
        $this->call(NetworkSettingSeeder::class);
    }
}
