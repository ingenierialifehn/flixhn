<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ApiKeyAdminController extends Controller
{
    /**
     * Listar todas las claves API registradas con métricas resumidas.
     * GET /api/admin/api-keys
     */
    public function index(Request $request): JsonResponse
    {
        $keys = ApiKey::with('user:id,username,customer_name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (ApiKey $item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'key' => $item->key,
                    'truncated_key' => $item->truncated_key,
                    'user_id' => $item->user_id,
                    'user_name' => $item->user ? ($item->user->customer_name ?: $item->user->username) : 'Sistema / Administrador',
                    'scopes' => $item->scopes ?? ['*'],
                    'last_used_at' => $item->last_used_at?->toIso8601String(),
                    'last_used_human' => $item->last_used_at ? $item->last_used_at->diffForHumans() : 'Nunca',
                    'expires_at' => $item->expires_at?->toIso8601String(),
                    'expires_human' => $item->expires_at ? $item->expires_at->diffForHumans() : 'Nunca',
                    'is_active' => (bool) $item->is_active,
                    'is_expired' => $item->isExpired(),
                    'created_at' => $item->created_at?->toIso8601String(),
                    'created_human' => $item->created_at?->diffForHumans(),
                ];
            });

        $total = $keys->count();
        $active = $keys->where('is_active', true)->filter(fn ($k) => !$k['is_expired'])->count();
        $revoked = $keys->where('is_active', false)->count();
        $expired = $keys->filter(fn ($k) => $k['is_expired'])->count();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total' => $total,
                'active' => $active,
                'revoked' => $revoked,
                'expired' => $expired,
            ],
            'keys' => $keys->values(),
        ]);
    }

    /**
     * Crear y emitir una nueva clave criptográficamente segura.
     * POST /api/admin/api-keys
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string',
            'expiration' => 'nullable|string|in:never,30_days,90_days,1_year',
            'custom_days' => 'nullable|integer|min:1|max:1825',
        ]);

        $rawKey = ApiKey::generateSecureKey();
        $truncatedKey = ApiKey::truncateKey($rawKey);

        // Calcular fecha de expiración
        $expiresAt = null;
        $expiration = $validated['expiration'] ?? 'never';

        if (!empty($validated['custom_days'])) {
            $expiresAt = Carbon::now()->addDays((int) $validated['custom_days']);
        } elseif ($expiration === '30_days') {
            $expiresAt = Carbon::now()->addDays(30);
        } elseif ($expiration === '90_days') {
            $expiresAt = Carbon::now()->addDays(90);
        } elseif ($expiration === '1_year') {
            $expiresAt = Carbon::now()->addYear();
        }

        // Determinar permisos/alcance
        $scopes = $validated['scopes'] ?? ['*'];
        if (empty($scopes)) {
            $scopes = ['*'];
        }

        $apiKey = ApiKey::create([
            'name' => trim($validated['name']),
            'key' => $rawKey,
            'truncated_key' => $truncatedKey,
            'user_id' => Auth::id() ?? $request->user()?->id,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Clave API creada exitosamente. Cópiala ahora; no se volverá a mostrar completa.',
            'raw_key' => $rawKey,
            'api_key' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'key' => $rawKey,
                'truncated_key' => $truncatedKey,
                'scopes' => $apiKey->scopes,
                'last_used_at' => null,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'is_active' => true,
                'is_expired' => false,
                'created_at' => $apiKey->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Actualizar metadatos o alcance de una clave API existente.
     * PUT /api/admin/api-keys/{id}
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $apiKey = ApiKey::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:100',
            'is_active' => 'sometimes|boolean',
            'scopes' => 'sometimes|nullable|array',
            'scopes.*' => 'string',
        ]);

        if (isset($validated['name'])) {
            $apiKey->name = trim($validated['name']);
        }

        if (isset($validated['is_active'])) {
            $apiKey->is_active = (bool) $validated['is_active'];
        }

        if (array_key_exists('scopes', $validated)) {
            $apiKey->scopes = !empty($validated['scopes']) ? $validated['scopes'] : ['*'];
        }

        $apiKey->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Clave API actualizada correctamente.',
            'api_key' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'key' => $apiKey->key,
                'truncated_key' => $apiKey->truncated_key,
                'scopes' => $apiKey->scopes,
                'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'is_active' => (bool) $apiKey->is_active,
                'is_expired' => $apiKey->isExpired(),
                'created_at' => $apiKey->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Alternar estado Activa / Revocada rápidamente.
     * POST /api/admin/api-keys/{id}/toggle
     */
    public function toggle(int|string $id): JsonResponse
    {
        $apiKey = ApiKey::findOrFail($id);
        $apiKey->is_active = !$apiKey->is_active;
        $apiKey->save();

        $stateText = $apiKey->is_active ? 'activada' : 'revocada';

        return response()->json([
            'status' => 'success',
            'message' => "La clave API ha sido {$stateText} exitosamente.",
            'is_active' => (bool) $apiKey->is_active,
        ]);
    }

    /**
     * Eliminar definitivamente una clave API del sistema.
     * DELETE /api/admin/api-keys/{id}
     */
    public function destroy(int|string $id): JsonResponse
    {
        $apiKey = ApiKey::findOrFail($id);
        $name = $apiKey->name;
        $apiKey->delete();

        return response()->json([
            'status' => 'success',
            'message' => "La clave API \"{$name}\" ha sido eliminada permanentemente.",
        ]);
    }

    /**
     * Endpoint de prueba y telemetría para comprobar una clave API en vivo.
     * GET /api/v1/ping
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        return response()->json([
            'status' => 'online',
            'service' => 'FlixHN Core Integration API',
            'timestamp' => now()->toIso8601String(),
            'client' => [
                'name' => $apiKey ? $apiKey->name : 'Desconocido',
                'truncated_key' => $apiKey ? $apiKey->truncated_key : null,
                'scopes' => $apiKey ? $apiKey->scopes : [],
                'last_used_at' => $apiKey ? $apiKey->last_used_at?->toIso8601String() : null,
            ],
        ]);
    }
}
