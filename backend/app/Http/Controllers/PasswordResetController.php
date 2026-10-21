<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    /**
     * Solicitar código de recuperación de cuenta (Email o WhatsApp/Teléfono).
     * POST /api/auth/password/forgot
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string',
            'channel' => 'nullable|in:email,whatsapp,sms',
        ]);

        $identifier = trim($validated['identifier']);

        // Buscar al usuario por correo, teléfono, número de contrato o nombre de usuario
        $user = User::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->orWhere('phone', $identifier)
            ->orWhere('contract_number', $identifier)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró ninguna cuenta o abonado asociado a este identificador.',
            ], 404);
        }

        // Determinar canal de entrega (email, whatsapp o sms)
        $channel = $validated['channel'] ?? null;
        if (!$channel) {
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL) || !empty($user->email)) {
                $channel = 'email';
            } else {
                $channel = 'whatsapp';
            }
        }

        // Generar código numérico seguro de 6 dígitos
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Invalidar códigos anteriores pendientes para este identificador
        PasswordResetCode::where('identifier', $identifier)->delete();

        // Almacenar el nuevo código con expiración exacta de 15 minutos
        $resetCode = PasswordResetCode::create([
            'identifier' => $identifier,
            'code' => $code,
            'channel' => $channel,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        $whatsappUrl = null;

        // Despacho de notificación según el canal seleccionado
        if ($channel === 'email') {
            $recipientEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : $user->email;

            if ($recipientEmail) {
                try {
                    Mail::raw(
                        "Hola {$user->customer_name},\n\nTu código de recuperación para FlixHN es: {$code}\n\nEste código es confidencial y expirará en 15 minutos.\nSi no solicitaste este cambio, puedes ignorar este mensaje.",
                        function ($message) use ($recipientEmail) {
                            $message->to($recipientEmail)
                                ->subject('Código de Recuperación de Cuenta - FlixHN');
                        }
                    );
                } catch (\Throwable $e) {
                    Log::warning("PasswordResetController: Error despachando correo a {$recipientEmail}: " . $e->getMessage());
                }
            }
        } else {
            // Canal WhatsApp / SMS
            $phone = $user->phone ?: preg_replace('/[^0-9]/', '', $identifier);
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

            $messageText = "FlixHN: Tu código de recuperación de cuenta es {$code}. Expira en 15 minutos. No compartas este código con nadie.";
            $whatsappUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode($messageText);

            // Integración con webhook de mensajería del ISP / Pasarela WhatsApp
            $webhookUrl = env('ISP_WHATSAPP_WEBHOOK') ?? env('ISP_MESSAGING_WEBHOOK');
            if ($webhookUrl) {
                try {
                    Http::timeout(4)->post($webhookUrl, [
                        'phone' => $cleanPhone,
                        'message' => $messageText,
                        'code' => $code,
                        'channel' => $channel,
                        'contract_number' => $user->contract_number,
                        'customer_name' => $user->customer_name,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("PasswordResetController: Error contactando pasarela de WhatsApp/SMS ISP: " . $e->getMessage());
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Código de 6 dígitos generado con éxito y enviado vía {$channel}.",
            'channel' => $channel,
            'identifier' => $identifier,
            'expires_in_minutes' => 15,
            'whatsapp_url' => $whatsappUrl,
            'dev_code' => config('app.debug') ? $code : null,
        ]);
    }

    /**
     * Validar que el código de 6 dígitos ingresado por el usuario no haya expirado.
     * POST /api/auth/password/verify-code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string',
            'code' => 'required|string',
        ]);

        $identifier = trim($validated['identifier']);
        $code = trim($validated['code']);

        $record = PasswordResetCode::where('identifier', $identifier)
            ->where('code', $code)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código de verificación es incorrecto o no coincide con los registros.',
            ], 422);
        }

        if ($record->isExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código de verificación ha expirado (límite de 15 minutos superado). Solicita un nuevo código.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Código de verificación válido. Puede proceder a restablecer la contraseña.',
            'identifier' => $identifier,
        ]);
    }

    /**
     * Restablecer la contraseña del usuario, actualizarla en users y purgar el código.
     * POST /api/auth/password/reset
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string',
            'code' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $identifier = trim($validated['identifier']);
        $code = trim($validated['code']);

        $record = PasswordResetCode::where('identifier', $identifier)
            ->where('code', $code)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Código de verificación inválido.',
            ], 422);
        }

        if ($record->isExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código de verificación ha expirado. Por favor solicita uno nuevo.',
            ], 422);
        }

        // Buscar al usuario correspondiente
        $user = User::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->orWhere('phone', $identifier)
            ->orWhere('contract_number', $identifier)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró el usuario para restablecer la contraseña.',
            ], 404);
        }

        // Actualizar contraseña con Hash seguro
        $user->password = Hash::make($validated['new_password']);
        $user->save();

        // Revocar todos los tokens de dispositivos activos por seguridad
        $user->tokens()->delete();

        // Purgar todos los códigos de recuperación de este identificador
        PasswordResetCode::where('identifier', $identifier)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Contraseña restablecida exitosamente. Todas las sesiones anteriores han sido revocadas. Ya puede iniciar sesión con su nueva clave.',
        ]);
    }
}
