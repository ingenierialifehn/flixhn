<?php

namespace App\Http\Controllers;

use App\Models\ServerSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogAdminController extends Controller
{
    /**
     * Opciones válidas de modo de registro de depuración.
     */
    protected const DEBUG_MODES = [
        'no' => 'No',
        'yes' => 'Yes',
        'until_restart' => 'Yes, until the next server restart',
        'until_rotation' => 'Yes, until the next log file rotation',
    ];

    /**
     * Texto de ayuda contextual requerido por especificación FlixHN.
     */
    protected const HELP_TEXT = 'El registro de depuración solo debe habilitarse según sea necesario para solucionar problemas. El mayor acceso al sistema de archivos puede afectar el rendimiento en algunos entornos.';

    /**
     * Obtener ajustes actuales del modo de depuración de logs.
     * GET /api/admin/logs/settings
     */
    public function settings(): JsonResponse
    {
        try {
            $mode = ServerSetting::get('debug_logging_mode', 'no');

            // Si el modo configurado era "until_restart", verificar si el servidor se ha reiniciado
            if ($mode === 'until_restart') {
                $savedBootId = ServerSetting::get('debug_logging_boot_id');
                $currentBootId = $this->getCurrentBootId();

                if ($savedBootId && $currentBootId && $savedBootId !== $currentBootId) {
                    // El servidor se reinició: revertir automáticamente a "no"
                    $mode = 'no';
                    ServerSetting::set('debug_logging_mode', 'no', 'logging');
                    ServerSetting::set('debug_logging_boot_id', null, 'logging');
                    Log::info('Nivel de registro de depuración restablecido a [No] tras el reinicio del servidor.');
                }
            }

            $options = [];
            foreach (self::DEBUG_MODES as $val => $label) {
                $options[] = [
                    'value' => $val,
                    'label' => $label,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'debug_logging_mode' => $mode,
                    'is_debug_active' => $mode !== 'no',
                    'options' => $options,
                    'help_text' => self::HELP_TEXT,
                    'current_log_channel' => config('logging.default', 'stack'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::settings Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al recuperar ajustes de registro: ' . $e->getMessage(),
                'data' => [
                    'debug_logging_mode' => 'no',
                    'is_debug_active' => false,
                    'options' => [],
                    'help_text' => self::HELP_TEXT,
                ],
            ], 500);
        }
    }

    /**
     * Actualizar modo de depuración de logs.
     * POST /api/admin/logs/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'debug_logging_mode' => 'required|string|in:no,yes,until_restart,until_rotation',
            ]);

            $mode = $validated['debug_logging_mode'];

            ServerSetting::set('debug_logging_mode', $mode, 'logging');

            if ($mode === 'until_restart') {
                ServerSetting::set('debug_logging_boot_id', $this->getCurrentBootId(), 'logging');
            } else {
                ServerSetting::set('debug_logging_boot_id', null, 'logging');
            }

            // Ajustar dinámicamente el nivel de registro en la sesión/config en curso
            $targetLevel = ($mode === 'no') ? 'info' : 'debug';
            config(['logging.channels.single.level' => $targetLevel]);
            config(['logging.channels.stderr_stream.level' => $targetLevel]);

            $modeLabel = self::DEBUG_MODES[$mode] ?? $mode;
            Log::info("Configuración de depuración actualizada por el administrador a: {$modeLabel} [{$mode}]");

            return response()->json([
                'status' => 'success',
                'message' => "Ajustes de registro de depuración actualizados a '{$modeLabel}'.",
                'data' => [
                    'debug_logging_mode' => $mode,
                    'is_debug_active' => $mode !== 'no',
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parámetros de configuración inválidos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::updateSettings Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al guardar la configuración de registro: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar archivos de registro disponibles en storage/logs/.
     * GET /api/admin/logs/files
     */
    public function files(): JsonResponse
    {
        try {
            $logsDir = storage_path('logs');

            if (!File::isDirectory($logsDir)) {
                File::makeDirectory($logsDir, 0775, true, true);
            }

            // Asegurar que exista el archivo activo laravel.log
            $activeLogPath = $logsDir . DIRECTORY_SEPARATOR . 'laravel.log';
            if (!File::exists($activeLogPath)) {
                File::put($activeLogPath, sprintf("[%s] local.INFO: Archivo de registro principal inicializado por FlixHN.\n", now()->toDateTimeString()));
            }

            $rawFiles = File::files($logsDir);
            $filesList = [];

            foreach ($rawFiles as $file) {
                $filename = $file->getFilename();

                // Ignorar archivos ocultos o que no terminen en .log
                if ($filename === '.gitignore' || !str_ends_with(strtolower($filename), '.log')) {
                    continue;
                }

                $size = $file->getSize();
                $mtime = $file->getMTime();
                $isActive = ($filename === 'laravel.log');

                $filesList[] = [
                    'name' => $filename,
                    'size_bytes' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'modified_at' => Carbon::createFromTimestamp($mtime)->toDateTimeString(),
                    'modified_diff' => Carbon::createFromTimestamp($mtime)->diffForHumans(),
                    'is_active' => $isActive,
                ];
            }

            // Ordenar: laravel.log siempre primero, seguido de los archivos más recientes
            usort($filesList, function ($a, $b) {
                if ($a['is_active']) return -1;
                if ($b['is_active']) return 1;
                return strcmp($b['modified_at'], $a['modified_at']);
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'files' => $filesList,
                    'total_files' => count($filesList),
                    'active_file' => 'laravel.log',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::files Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al listar archivos de registro: ' . $e->getMessage(),
                'data' => [
                    'files' => [],
                    'total_files' => 0,
                    'active_file' => 'laravel.log',
                ],
            ], 500);
        }
    }

    /**
     * Obtener contenido y registros formateados de un archivo de log específico.
     * GET /api/admin/logs/content
     */
    public function content(Request $request): JsonResponse
    {
        try {
            $filename = basename((string) $request->query('filename', 'laravel.log'));
            $linesLimit = (int) $request->query('lines', 400);
            $linesLimit = max(20, min(1000, $linesLimit));
            $levelFilter = strtoupper((string) $request->query('level', 'ALL'));
            $search = trim((string) $request->query('search', ''));

            // Sanitización estricta contra Path Traversal
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename) || !str_ends_with($filename, '.log')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nombre de archivo no válido o extensión no permitida.',
                ], 400);
            }

            $logsDir = realpath(storage_path('logs'));
            $filePath = storage_path('logs/' . $filename);
            $realFilePath = realpath($filePath);

            if (!$realFilePath || !$logsDir || !str_starts_with($realFilePath, $logsDir) || !File::exists($realFilePath)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El archivo de registro '{$filename}' no existe en el almacenamiento.",
                ], 404);
            }

            // Lectura eficiente de las últimas líneas
            $rawLines = $this->tailFile($realFilePath, $linesLimit);
            $records = $this->parseLogRecords($rawLines);

            // Contadores por nivel en la muestra leída
            $counts = [
                'ALL' => count($records),
                'ERROR' => 0,
                'WARNING' => 0,
                'INFO' => 0,
                'DEBUG' => 0,
            ];

            foreach ($records as $rec) {
                $lvl = $rec['level'];
                if (in_array($lvl, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])) {
                    $counts['ERROR']++;
                } elseif ($lvl === 'WARNING') {
                    $counts['WARNING']++;
                } elseif (in_array($lvl, ['INFO', 'NOTICE'])) {
                    $counts['INFO']++;
                } elseif ($lvl === 'DEBUG') {
                    $counts['DEBUG']++;
                }
            }

            // Aplicar filtros
            $filteredRecords = array_values(array_filter($records, function ($rec) use ($levelFilter, $search) {
                // Filtro de nivel
                if ($levelFilter !== 'ALL') {
                    $recLevel = $rec['level'];
                    if ($levelFilter === 'ERROR' && !in_array($recLevel, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])) {
                        return false;
                    }
                    if ($levelFilter === 'WARNING' && $recLevel !== 'WARNING') {
                        return false;
                    }
                    if ($levelFilter === 'INFO' && !in_array($recLevel, ['INFO', 'NOTICE'])) {
                        return false;
                    }
                    if ($levelFilter === 'DEBUG' && $recLevel !== 'DEBUG') {
                        return false;
                    }
                }

                // Filtro de búsqueda por texto libre
                if ($search !== '') {
                    $searchLower = mb_strtolower($search);
                    $msgMatch = str_contains(mb_strtolower($rec['message']), $searchLower);
                    $traceMatch = !empty($rec['stacktrace_text']) && str_contains(mb_strtolower($rec['stacktrace_text']), $searchLower);
                    $rawMatch = str_contains(mb_strtolower($rec['raw']), $searchLower);
                    if (!$msgMatch && !$traceMatch && !$rawMatch) {
                        return false;
                    }
                }

                return true;
            }));

            $fileSize = File::size($realFilePath);
            $fileMtime = File::lastModified($realFilePath);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'filename' => $filename,
                    'file_size_bytes' => $fileSize,
                    'file_size_formatted' => $this->formatBytes($fileSize),
                    'modified_at' => Carbon::createFromTimestamp($fileMtime)->toDateTimeString(),
                    'modified_diff' => Carbon::createFromTimestamp($fileMtime)->diffForHumans(),
                    'total_entries_parsed' => count($records),
                    'filtered_entries_count' => count($filteredRecords),
                    'counts_by_level' => $counts,
                    'entries' => $filteredRecords,
                    'raw_tail' => implode("\n", $rawLines),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::content Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al leer el archivo de registro: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Descarga segura del archivo de log seleccionado.
     * GET /api/admin/logs/download/{filename}
     */
    public function download(string $filename): BinaryFileResponse|JsonResponse
    {
        try {
            $cleanFilename = basename($filename);

            // Sanitización estricta contra Path Traversal
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $cleanFilename) || !str_ends_with($cleanFilename, '.log')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nombre de archivo no válido para descarga.',
                ], 400);
            }

            $logsDir = realpath(storage_path('logs'));
            $filePath = storage_path('logs/' . $cleanFilename);
            $realFilePath = realpath($filePath);

            if (!$realFilePath || !$logsDir || !str_starts_with($realFilePath, $logsDir) || !File::exists($realFilePath)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El archivo de registro solicitado no existe.',
                ], 404);
            }

            return response()->download($realFilePath, $cleanFilename, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $cleanFilename . '"',
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::download Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al descargar el archivo de registro: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vaciar o rotar de manera segura el archivo de log activo.
     * POST /api/admin/logs/clear
     */
    public function clear(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'filename' => 'nullable|string',
                'action' => 'nullable|string|in:clear,rotate',
            ]);

            $filename = basename((string) ($validated['filename'] ?? 'laravel.log'));
            $action = $validated['action'] ?? 'clear';

            // Sanitización estricta contra Path Traversal
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename) || !str_ends_with($filename, '.log')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nombre de archivo no válido.',
                ], 400);
            }

            $logsDir = realpath(storage_path('logs'));
            $filePath = storage_path('logs/' . $filename);
            $realFilePath = realpath($filePath);

            if (!$realFilePath || !$logsDir || !str_starts_with($realFilePath, $logsDir) || !File::exists($realFilePath)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El archivo de registro no existe.',
                ], 404);
            }

            $archivedFilename = null;

            // Si la acción es rotar, crear un respaldo con marca de tiempo
            if ($action === 'rotate' && File::size($realFilePath) > 0) {
                $timestamp = Carbon::now()->format('Y-m-d-His');
                $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                $archivedFilename = "{$nameWithoutExt}-{$timestamp}.log";
                $archivedPath = $logsDir . DIRECTORY_SEPARATOR . $archivedFilename;

                File::copy($realFilePath, $archivedPath);
            }

            // Vaciar el archivo y escribir encabezado limpio
            $headerLine = sprintf(
                "[%s] local.INFO: Archivo de registro '%s' %s por el administrador FlixHN.\n",
                now()->toDateTimeString(),
                $filename,
                $action === 'rotate' ? 'rotado y reiniciado' : 'vaciado'
            );
            File::put($realFilePath, $headerLine);

            // Regla "Yes, until the next log file rotation":
            // Si el modo de depuración estaba en "until_rotation", restablecer a "no"
            $currentDebugMode = ServerSetting::get('debug_logging_mode', 'no');
            $debugReset = false;

            if ($currentDebugMode === 'until_rotation') {
                ServerSetting::set('debug_logging_mode', 'no', 'logging');
                $currentDebugMode = 'no';
                $debugReset = true;
                Log::info('Nivel de registro de depuración restablecido a [No] tras la rotación/limpieza de logs.');
            }

            $msg = $action === 'rotate'
                ? "Archivo rotado con éxito como '{$archivedFilename}' y log principal vaciado."
                : "Archivo de registro '{$filename}' vaciado con éxito.";

            if ($debugReset) {
                $msg .= ' El modo de depuración se restableció a [No] automáticamente.';
            }

            return response()->json([
                'status' => 'success',
                'message' => $msg,
                'data' => [
                    'filename' => $filename,
                    'action_performed' => $action,
                    'archived_filename' => $archivedFilename,
                    'debug_logging_mode' => $currentDebugMode,
                    'debug_reset_to_no' => $debugReset,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAdminController::clear Error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al vaciar o rotar el archivo de registro: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lectura eficiente de las últimas N líneas de un archivo de registro.
     */
    protected function tailFile(string $filepath, int $lines = 400): array
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [];
        }

        $size = filesize($filepath);
        if ($size === 0) {
            return [];
        }

        // Si el archivo es menor a 3MB, leer con file()
        if ($size < 3 * 1024 * 1024) {
            $allLines = file($filepath, FILE_IGNORE_NEW_LINES);
            if ($allLines === false) {
                return [];
            }
            return array_slice($allLines, -$lines);
        }

        // Para archivos mayores, leer en bloques desde el final
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return [];
        }

        $buffer = '';
        $chunkSize = 64 * 1024;
        $pos = $size;
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $allLines = explode("\n", $buffer);
        $allLines = array_map(fn($l) => rtrim($l, "\r"), $allLines);

        return array_slice($allLines, -$lines);
    }

    /**
     * Analiza líneas sin formato en registros estructurados con timestamps, niveles y trazas de error.
     */
    protected function parseLogRecords(array $lines): array
    {
        $records = [];
        $currentRecord = null;
        $recordId = 1;

        // Patrón Monolog: [2026-10-17 10:02:37] local.INFO: Mensaje ...
        $pattern = '/^\[(?<timestamp>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[\+-]\d{2}:?\d{2})?)\]\s*(?:(?<env>[a-zA-Z0-9_\-]+)\.)?(?<level>[a-zA-Z]+):\s*(?<message>.*)$/';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match($pattern, $line, $matches)) {
                if ($currentRecord !== null) {
                    $records[] = $currentRecord;
                }

                $levelUpper = strtoupper($matches['level']);

                $currentRecord = [
                    'id' => $recordId++,
                    'timestamp' => $matches['timestamp'],
                    'env' => $matches['env'] ?? 'local',
                    'level' => $levelUpper,
                    'message' => $matches['message'],
                    'stacktrace' => [],
                    'raw' => $line,
                ];
            } else {
                if ($currentRecord !== null) {
                    $currentRecord['stacktrace'][] = $line;
                    $currentRecord['raw'] .= "\n" . $line;
                } else {
                    $records[] = [
                        'id' => $recordId++,
                        'timestamp' => '',
                        'env' => '',
                        'level' => 'INFO',
                        'message' => $line,
                        'stacktrace' => [],
                        'raw' => $line,
                    ];
                }
            }
        }

        if ($currentRecord !== null) {
            $records[] = $currentRecord;
        }

        foreach ($records as &$rec) {
            if (!empty($rec['stacktrace'])) {
                $rec['stacktrace_text'] = implode("\n", $rec['stacktrace']);
            } else {
                $rec['stacktrace_text'] = null;
            }
        }

        return $records;
    }

    /**
     * Formatea tamaños en bytes a unidades legibles (B, KB, MB, GB).
     */
    protected function formatBytes(int $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
    }

    /**
     * Identificador del arranque actual del kernel/contenedor del servidor.
     */
    protected function getCurrentBootId(): string
    {
        if (file_exists('/proc/sys/kernel/random/boot_id')) {
            $id = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
            if ($id !== '') {
                return $id;
            }
        }

        // Fallback: identificador en archivo temporal de sistema
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flixhn_server_session';
        if (!file_exists($marker)) {
            @file_put_contents($marker, uniqid('boot_', true));
        }

        return (string) @file_get_contents($marker);
    }
}
