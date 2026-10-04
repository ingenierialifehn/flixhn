<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * Listar todos los suscriptores del ISP con perfiles y sesiones activas.
     */
    public function index(): JsonResponse
    {
        $users = User::with(['profiles'])
            ->withCount('tokens')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * Crear un nuevo suscriptor asignado por el ISP.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|unique:users,username|max:50',
            'password' => 'required|string|min:6',
            'customer_name' => 'required|string|max:100',
            'assigned_ip' => 'nullable|ip',
            'max_screens' => 'integer|min:1|max:10',
            'role' => 'in:admin,subscriber',
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'subscriber',
            'customer_name' => $validated['customer_name'],
            'assigned_ip' => $validated['assigned_ip'] ?? null,
            'max_screens' => $validated['max_screens'] ?? 2,
            'is_active' => true,
        ]);

        // Crear automáticamente el perfil principal por defecto
        $user->profiles()->create([
            'name' => 'Principal',
            'avatar_color' => '#E50914',
            'is_kids' => false,
        ]);

        return response()->json([
            'message' => 'Suscriptor registrado con éxito en el sistema ISP.',
            'user' => $user->fresh('profiles'),
        ], 201);
    }

    /**
     * Actualizar datos del suscriptor.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'customer_name' => 'sometimes|required|string|max:100',
            'assigned_ip' => 'nullable|ip',
            'max_screens' => 'sometimes|integer|min:1|max:10',
            'role' => 'sometimes|in:admin,subscriber',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Información de suscriptor actualizada.',
            'user' => $user,
        ]);
    }

    /**
     * Activar o suspender cuenta de suscriptor.
     */
    public function toggleActive(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->role === 'admin' && $user->username === 'admin') {
            return response()->json([
                'message' => 'No se puede suspender el usuario administrador principal.',
            ], 422);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        if (!$user->is_active) {
            // Revocar tokens activos si fue suspendido
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => $user->is_active ? 'Suscriptor activado.' : 'Suscriptor suspendido.',
            'is_active' => $user->is_active,
        ]);
    }

    /**
     * Restablecer contraseña de suscriptor.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Revocar sesiones existentes
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Contraseña actualizada y sesiones anteriores revocadas.',
        ]);
    }

    /**
     * Eliminar suscriptor y todos sus perfiles asociados.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->role === 'admin' && $user->username === 'admin') {
            return response()->json([
                'message' => 'El administrador principal no puede ser eliminado.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Suscriptor eliminado permanentemente.',
        ]);
    }
}
