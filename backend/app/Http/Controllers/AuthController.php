<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Autenticación local mediante Username y Contraseña.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $validated['username'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Credenciales incorrectas o usuario no registrado en el ISP.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Cuenta suspendida o inactiva. Contacte al soporte técnico del ISP.',
            ], 403);
        }

        // Control de límite de pantallas simultáneas por tokens activos
        if ($user->tokens()->count() >= $user->max_screens) {
            // Revoca el token más antiguo para admitir la nueva sesión de streaming
            $user->tokens()->orderBy('created_at', 'asc')->first()?->delete();
        }

        // Crear token de acceso con Sanctum
        $token = $user->createToken('flixhn-device')->plainTextToken;

        // Cargar perfiles asociados
        $user->load('profiles');

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'client_ip' => $request->ip(),
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'customer_name' => $user->customer_name,
                'assigned_ip' => $user->assigned_ip,
                'max_screens' => $user->max_screens,
                'profiles' => $user->profiles,
            ],
        ]);
    }

    /**
     * Obtener datos del suscriptor autenticado y perfiles.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('profiles');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'customer_name' => $user->customer_name,
                'assigned_ip' => $user->assigned_ip,
                'max_screens' => $user->max_screens,
                'profiles' => $user->profiles,
            ],
            'client_ip' => $request->ip(),
            'is_on_net' => $user->assigned_ip ? ($user->assigned_ip === $request->ip()) : true,
        ]);
    }

    /**
     * Cerrar sesión y revocar el token actual del dispositivo.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.',
        ]);
    }

    /**
     * Crear un nuevo perfil para el suscriptor.
     */
    public function createProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profiles()->count() >= 5) {
            return response()->json([
                'message' => 'Se ha alcanzado el límite máximo de 5 perfiles.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'avatar_color' => 'nullable|string|max:20',
            'is_kids' => 'boolean',
        ]);

        $colors = ['#E50914', '#0071EB', '#F5A623', '#2BD465', '#9B51E0'];
        $chosenColor = $validated['avatar_color'] ?? $colors[array_rand($colors)];

        $profile = $user->profiles()->create([
            'name' => $validated['name'],
            'avatar_color' => $chosenColor,
            'is_kids' => $validated['is_kids'] ?? false,
        ]);

        return response()->json([
            'message' => 'Perfil creado con éxito.',
            'profile' => $profile,
        ], 201);
    }
}
