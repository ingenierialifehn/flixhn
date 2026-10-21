<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    /**
     * Límite máximo de perfiles permitidos por cuenta de suscriptor.
     */
    protected const MAX_PROFILES = 5;

    /**
     * Paleta de colores predeterminada si no se proporciona avatar específico.
     */
    protected const DEFAULT_COLORS = ['#E50914', '#0071EB', '#2BD465', '#F5A623', '#9B51E0', '#E50980'];

    /**
     * Listar todos los perfiles asociados a la cuenta del usuario autenticado.
     * GET /api/profiles
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profiles = $user->profiles()->orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'count' => $profiles->count(),
            'max_allowed' => self::MAX_PROFILES,
            'profiles' => $profiles,
        ]);
    }

    /**
     * Crear un nuevo perfil para la cuenta (valida no superar el límite permitido).
     * POST /api/profiles
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profiles()->count() >= self::MAX_PROFILES) {
            return response()->json([
                'status' => 'error',
                'message' => 'Se ha alcanzado el límite máximo de ' . self::MAX_PROFILES . ' perfiles permitidos para esta cuenta.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'avatar' => 'nullable|string|max:255',
            'avatar_color' => 'nullable|string|max:20',
            'is_kids' => 'boolean',
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'preferences' => 'nullable|array',
        ]);

        $avatar = $validated['avatar']
            ?? $validated['avatar_color']
            ?? self::DEFAULT_COLORS[array_rand(self::DEFAULT_COLORS)];

        $profile = $user->profiles()->create([
            'name' => trim($validated['name']),
            'avatar' => $avatar,
            'is_kids' => $validated['is_kids'] ?? false,
            'pin' => !empty($validated['pin']) ? $validated['pin'] : null,
            'preferences' => $validated['preferences'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Perfil creado con éxito.',
            'profile' => $profile,
        ], 201);
    }

    /**
     * Obtener el detalle de un perfil específico.
     * GET /api/profiles/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $profile = $request->user()->profiles()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'profile' => $profile,
        ]);
    }

    /**
     * Actualizar datos de un perfil (nombre, avatar, switch Kids, PIN de 4 dígitos o preferencias).
     * PUT /api/profiles/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profiles()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'avatar' => 'nullable|string|max:255',
            'avatar_color' => 'nullable|string|max:20',
            'is_kids' => 'boolean',
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'remove_pin' => 'nullable|boolean',
            'preferences' => 'nullable|array',
        ]);

        if (isset($validated['name'])) {
            $profile->name = trim($validated['name']);
        }

        if (isset($validated['avatar']) || isset($validated['avatar_color'])) {
            $profile->avatar = $validated['avatar'] ?? $validated['avatar_color'];
        }

        if (isset($validated['is_kids'])) {
            $profile->is_kids = (bool) $validated['is_kids'];
        }

        if (!empty($validated['remove_pin'])) {
            $profile->pin = null;
        } elseif (array_key_exists('pin', $validated)) {
            $profile->pin = !empty($validated['pin']) ? $validated['pin'] : null;
        }

        if (array_key_exists('preferences', $validated)) {
            $profile->preferences = $validated['preferences'];
        }

        $profile->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Perfil actualizado exitosamente.',
            'profile' => $profile,
        ]);
    }

    /**
     * Eliminar un perfil (no permite borrar el último perfil principal de la cuenta).
     * DELETE /api/profiles/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profiles()->findOrFail($id);

        if ($user->profiles()->count() <= 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se permite borrar el último perfil principal de la cuenta.',
            ], 422);
        }

        $profileName = $profile->name;
        $profile->delete();

        return response()->json([
            'status' => 'success',
            'message' => "El perfil '{$profileName}' ha sido eliminado exitosamente.",
        ]);
    }

    /**
     * Seleccionar el perfil activo en la sesión actual.
     * Emite/asocia la sesión con current_profile_id para filtrar historial, lista personal y recomendaciones.
     * Valida PIN de 4 dígitos si el perfil está protegido.
     * POST /api/profiles/{id}/select
     */
    public function select(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profiles()->findOrFail($id);

        // Si el perfil tiene PIN de 4 dígitos configurado, validarlo
        if (!empty($profile->pin)) {
            $providedPin = $request->input('pin');
            if (empty($providedPin) || (string) $providedPin !== (string) $profile->pin) {
                return response()->json([
                    'status' => 'error',
                    'requires_pin' => true,
                    'message' => 'PIN de 4 dígitos requerido o incorrecto para acceder a este perfil.',
                ], 403);
            }
        }

        // Asociar el perfil a la sesión / token Sanctum actual
        $token = $user->currentAccessToken();
        if ($token) {
            Cache::put("token_{$token->id}_current_profile", $profile->id, now()->addDays(30));
        }
        Cache::put("user_{$user->id}_current_profile", $profile->id, now()->addDays(30));

        return response()->json([
            'status' => 'success',
            'message' => "Perfil '{$profile->name}' seleccionado correctamente.",
            'current_profile_id' => $profile->id,
            'profile' => $profile,
            'preferences' => $profile->preferences,
            'is_kids' => $profile->is_kids,
        ]);
    }
}
