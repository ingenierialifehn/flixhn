<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Autenticación local mediante Username/Correo y Contraseña.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $validated['username'])
            ->orWhere('email', $validated['username'])
            ->first();

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
                'email' => $user->email,
                'role' => $user->isAdmin() ? 'admin' : 'client',
                'customer_name' => $user->customer_name,
                'phone' => $user->phone,
                'contract_number' => $user->contract_number,
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
                'email' => $user->email,
                'role' => $user->isAdmin() ? 'admin' : 'client',
                'customer_name' => $user->customer_name,
                'phone' => $user->phone,
                'contract_number' => $user->contract_number,
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
     * Crear un nuevo perfil para el suscriptor (delega a ProfileController para validación uniforme).
     */
    public function createProfile(Request $request): JsonResponse
    {
        return app(ProfileController::class)->store($request);
    }
}
