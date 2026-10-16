<?php

namespace App\Http\Controllers;

use App\Models\TranscodingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TranscodingSettingController extends Controller
{
    /**
     * Obtener la configuración actual de transcodificación.
     * GET /api/admin/transcoding
     */
    public function index(): JsonResponse
    {
        try {
            $setting = TranscodingSetting::current();

            return response()->json([
                'status' => 'success',
                'data' => $setting,
            ]);
        } catch (\Throwable $e) {
            Log::error('TranscodingSettingController::index Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'success',
                'data' => TranscodingSetting::defaults(),
            ]);
        }
    }

    /**
     * Validar y actualizar la configuración de transcodificación.
     * POST /api/admin/transcoding
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enable_hardware_acceleration' => 'required|string|in:no,vaapi,nvenc,qsv,videotoolbox,amf',
                'h264_encoder_settings' => 'required|array',
                'h264_encoder_settings.preset' => 'required|string|in:ultrafast,superfast,veryfast,faster,fast,medium,slow,slower,veryslow',
                'h264_encoder_settings.crf' => 'required|integer|min:0|max:51',
                'h264_encoder_settings.profile' => 'nullable|string',
                'h265_encoder_settings' => 'required|array',
                'h265_encoder_settings.preset' => 'required|string|in:ultrafast,superfast,veryfast,faster,fast,medium,slow,slower,veryslow',
                'h265_encoder_settings.crf' => 'required|integer|min:0|max:51',
                'h265_encoder_settings.profile' => 'nullable|string',
                'temp_path' => 'nullable|string|max:500',
                'enable_throttling' => 'required|boolean',
                'audio_boost_downmix' => 'required|integer|min:1|max:10',
                'allow_subtitle_extraction_on_the_fly' => 'required|boolean',
                'enable_software_tone_mapping' => 'required|boolean',
                'tone_mapping_resolution_limit' => 'required|string|in:no_limit,4k,1080p,720p,480p',
                'tone_mapping_algorithm' => 'required|string|in:hable,mobius,reinhard,bt.2390',
            ]);

            // Sanitizar temp_path (string vacío pasa a null)
            if (isset($validated['temp_path']) && trim($validated['temp_path']) === '') {
                $validated['temp_path'] = null;
            }

            $setting = TranscodingSetting::current();
            $setting->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración guardada correctamente',
                'data' => $setting->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Errores de validación en los parámetros de transcodificación.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('TranscodingSettingController::update Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al guardar la configuración: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar si la ruta temporal existe y tiene permisos de escritura en el servidor.
     * POST /api/admin/transcoding/validate-path
     */
    public function validatePath(Request $request): JsonResponse
    {
        try {
            $rawPath = $request->input('temp_path');
            $path = is_string($rawPath) ? trim($rawPath) : '';

            // Si está vacía, validar la ruta temporal predeterminada del sistema
            if ($path === '') {
                $defaultPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flixhn_transcode';

                if (!File::exists($defaultPath)) {
                    @File::makeDirectory($defaultPath, 0775, true);
                }

                $exists = File::exists($defaultPath);
                $writable = $exists && is_writable($defaultPath);

                return response()->json([
                    'status' => $writable ? 'success' : 'warning',
                    'exists' => $exists,
                    'writable' => $writable,
                    'path' => $defaultPath,
                    'is_default' => true,
                    'message' => $writable
                        ? 'Ruta por defecto del servidor lista y con permisos de escritura (' . $defaultPath . ').'
                        : 'La ruta por defecto del sistema no tiene permisos de escritura.',
                ]);
            }

            // Validar ruta especificada por el usuario
            $exists = File::exists($path);

            if (!$exists) {
                // Intentar crear el directorio si no existe para verificar si hay permisos en el padre
                $created = @File::makeDirectory($path, 0775, true);
                $exists = $created || File::exists($path);
            }

            $writable = $exists && is_writable($path);

            if ($exists && $writable) {
                // Test adicional: escribir un pequeño archivo temporal de prueba para descartar sistemas de sólo lectura
                $testFile = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.flixhn_write_test_' . uniqid();
                $writeSuccess = @file_put_contents($testFile, 'test');
                if ($writeSuccess !== false) {
                    @unlink($testFile);
                    return response()->json([
                        'status' => 'success',
                        'exists' => true,
                        'writable' => true,
                        'path' => $path,
                        'message' => 'La ruta es válida y tiene permisos completos de lectura y escritura.',
                    ]);
                }
            }

            if ($exists && !$writable) {
                return response()->json([
                    'status' => 'error',
                    'exists' => true,
                    'writable' => false,
                    'path' => $path,
                    'message' => 'La carpeta existe pero el servidor web no tiene permisos de escritura (chmod/chown requerido).',
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'exists' => false,
                'writable' => false,
                'path' => $path,
                'message' => 'La ruta especificada no existe y no pudo ser creada automáticamente.',
            ], 422);

        } catch (\Throwable $e) {
            Log::error('TranscodingSettingController::validatePath Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'exists' => false,
                'writable' => false,
                'message' => 'Error al verificar la ruta en el servidor: ' . $e->getMessage(),
            ], 500);
        }
    }
}
