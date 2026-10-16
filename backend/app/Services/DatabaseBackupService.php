<?php

namespace App\Services;

use App\Models\DatabaseSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseBackupService
{
    /**
     * Directorio de almacenamiento para las copias de seguridad locales.
     */
    public function getBackupDirectory(): string
    {
        $path = storage_path('app/backups');

        if (!File::exists($path)) {
            File::makeDirectory($path, 0777, true, true);
        }
        @chmod($path, 0777);

        return $path;
    }

    /**
     * Listar todos los respaldos disponibles ordenados del más reciente al más antiguo.
     */
    public function getBackups(): array
    {
        $dir = $this->getBackupDirectory();
        $items = @scandir($dir) ?: [];
        $backups = [];

        foreach ($items as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Solo considerar archivos .sql o .gz
            if (!in_array($extension, ['sql', 'gz'])) {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($fullPath)) {
                continue;
            }

            $sizeBytes = $this->getSafeFileSize($fullPath);
            $mtime = @filemtime($fullPath) ?: time();
            $isAuto = str_contains($filename, '_auto') || str_contains($filename, 'auto_');

            $backups[] = [
                'filename' => $filename,
                'path' => $fullPath,
                'size_bytes' => $sizeBytes,
                'size_mb' => round($sizeBytes / (1024 * 1024), 2),
                'human_size' => $this->formatBytes($sizeBytes),
                'created_at' => Carbon::createFromTimestamp($mtime)->toIso8601String(),
                'created_at_human' => Carbon::createFromTimestamp($mtime)->diffForHumans(),
                'type' => $isAuto ? 'auto' : 'manual',
                'is_compressed' => str_ends_with($filename, '.gz'),
                'timestamp' => $mtime,
            ];
        }

        // Ordenar descendentemente por fecha de modificación
        usort($backups, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Crear un nuevo respaldo de la base de datos (manual o automático).
     */
    public function createBackup(string $type = 'manual'): array
    {
        $type = in_array($type, ['auto', 'manual']) ? $type : 'manual';
        $timestamp = now()->format('Y-m-d_His');
        $filename = "backup_flixhn_{$timestamp}_{$type}.sql.gz";
        $dir = $this->getBackupDirectory();
        $targetFile = $dir . DIRECTORY_SEPARATOR . $filename;

        // Se genera primero en el directorio temporal del sistema (tmpfs) para máxima velocidad
        $tempGzFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "flixhn_bkg_{$timestamp}_" . uniqid() . '.sql.gz';

        $dbConnection = config('database.default', 'mysql');
        $dbConfig = config("database.connections.{$dbConnection}", []);

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 3306;
        $database = $dbConfig['database'] ?? 'flixhn';
        $username = $dbConfig['username'] ?? 'root';
        $password = $dbConfig['password'] ?? '';

        $method = 'native_php';
        @unlink($targetFile);
        $this->createNativePhpBackup($targetFile, $database);
        clearstatcache(true, $targetFile);
        $sizeBytes = $this->getSafeFileSize($targetFile);

        if ($sizeBytes < 1024) {
            @unlink($targetFile);
            throw new \RuntimeException('No fue posible generar el respaldo comprimido de la base de datos.');
        }

        @chmod($targetFile, 0666);
        $targetSize = $sizeBytes;
        $mtime = @filemtime($targetFile) ?: time();

        // Si es de tipo automático, aplicar rotación de retención
        if ($type === 'auto') {
            $this->rotateRetentionBackups();
        }

        return [
            'filename' => $filename,
            'path' => $targetFile,
            'size_bytes' => $sizeBytes,
            'size_mb' => round($sizeBytes / (1024 * 1024), 2),
            'human_size' => $this->formatBytes($sizeBytes),
            'created_at' => Carbon::createFromTimestamp($mtime)->toIso8601String(),
            'created_at_human' => Carbon::createFromTimestamp($mtime)->diffForHumans(),
            'type' => $type,
            'method' => $method,
            'is_compressed' => true,
        ];
    }

    /**
     * Rotar y eliminar respaldos automáticos que superen los días de retención configurados.
     */
    public function rotateRetentionBackups(): int
    {
        $settings = DatabaseSetting::current();
        $retentionDays = max(1, (int) $settings->auto_backup_retention_days);
        $cutoff = now()->subDays($retentionDays)->timestamp;

        $backups = $this->getBackups();
        $deletedCount = 0;

        foreach ($backups as $backup) {
            if ($backup['type'] === 'auto' && $backup['timestamp'] < $cutoff) {
                if (@File::delete($backup['path'])) {
                    $deletedCount++;
                    Log::info("Respaldo automático purgado por política de retención ({$retentionDays} días): " . $backup['filename']);
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Descargar un archivo de respaldo validando protección contra Path Traversal.
     */
    public function downloadBackup(string $filename): BinaryFileResponse
    {
        $path = $this->validateAndResolvePath($filename);

        return response()->download($path, $filename, [
            'Content-Type' => str_ends_with($filename, '.gz') ? 'application/gzip' : 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Restaurar la base de datos a partir de un archivo de respaldo seleccionado.
     */
    public function restoreBackup(string $filename): array
    {
        $path = $this->validateAndResolvePath($filename);
        $startTime = microtime(true);

        $dbConnection = config('database.default', 'mysql');
        $dbConfig = config("database.connections.{$dbConnection}", []);

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 3306;
        $database = $dbConfig['database'] ?? 'flixhn';
        $username = $dbConfig['username'] ?? 'root';
        $password = $dbConfig['password'] ?? '';

        $tempSqlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_' . uniqid() . '.sql';

        try {
            // Descomprimir o copiar al archivo temporal
            if (str_ends_with($filename, '.gz')) {
                $this->decompressGzFile($path, $tempSqlFile);
            } else {
                copy($path, $tempSqlFile);
            }

            $mysqlBinary = $this->findBinary(['mysql', 'mariadb']);
            $success = false;
            $method = 'mysql_cli';

            if ($mysqlBinary) {
                $command = sprintf(
                    '%s --host=%s --port=%s --user=%s --password=%s --skip-ssl --default-character-set=utf8mb4 %s < %s 2>/dev/null',
                    escapeshellcmd($mysqlBinary),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    escapeshellarg($tempSqlFile)
                );

                $output = [];
                $returnCode = 0;
                @exec($command, $output, $returnCode);

                if ($returnCode === 0) {
                    $success = true;
                }
            }

            // Fallback nativo PHP con PDO para máxima compatibilidad
            if (!$success) {
                $method = 'native_pdo';
                $this->restoreNativePhp($tempSqlFile);
                $success = true;
            }
        } finally {
            if (File::exists($tempSqlFile)) {
                @unlink($tempSqlFile);
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'status' => 'success',
            'filename' => $filename,
            'duration_seconds' => $duration,
            'method' => $method,
            'message' => "Base de datos restaurada exitosamente desde '{$filename}' en {$duration} segundos.",
        ];
    }

    /**
     * Eliminar un archivo de respaldo existente con validación de seguridad.
     */
    public function deleteBackup(string $filename): bool
    {
        $path = $this->validateAndResolvePath($filename);

        if (File::exists($path)) {
            return @unlink($path) || File::delete($path);
        }

        return false;
    }

    /**
     * Ejecutar optimización y análisis sobre todas las tablas del sistema.
     */
    public function optimizeTables(): array
    {
        $database = config('database.connections.mysql.database', 'flixhn');
        $tables = DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tableKey = 'Tables_in_' . $database;

        $results = [];
        $totalTables = 0;

        foreach ($tables as $t) {
            $tableName = $t->$tableKey ?? reset($t);
            if (!$tableName) continue;

            $totalTables++;

            try {
                // Ejecutar ANALYZE TABLE
                $analyzeResult = DB::select("ANALYZE TABLE `{$tableName}`");
                $analyzeStatus = $analyzeResult[0]->Msg_text ?? 'OK';

                // Ejecutar OPTIMIZE TABLE
                $optimizeResult = DB::select("OPTIMIZE TABLE `{$tableName}`");
                $optimizeStatus = $optimizeResult[0]->Msg_text ?? 'OK';

                $results[] = [
                    'table' => $tableName,
                    'analyze_status' => $analyzeStatus,
                    'optimize_status' => $optimizeStatus,
                    'status' => 'success',
                ];
            } catch (\Throwable $e) {
                Log::error("Error optimizando tabla {$tableName}: " . $e->getMessage());
                $results[] = [
                    'table' => $tableName,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_tables' => $totalTables,
            'timestamp' => now()->toIso8601String(),
            'tables' => $results,
        ];
    }

    /**
     * Obtener estadísticas de almacenamiento y clúster MySQL.
     */
    public function getDatabaseStats(): array
    {
        $database = config('database.connections.mysql.database', 'flixhn');

        try {
            $versionRow = DB::selectOne("SELECT VERSION() as version");
            $mysqlVersion = $versionRow->version ?? 'Unknown';

            $sizeStats = DB::selectOne(
                "SELECT 
                    COALESCE(SUM(data_length + index_length), 0) / (1024 * 1024) AS size_mb,
                    COALESCE(SUM(data_length), 0) / (1024 * 1024) AS data_mb,
                    COALESCE(SUM(index_length), 0) / (1024 * 1024) AS index_mb,
                    COUNT(*) AS tables_count,
                    COALESCE(SUM(table_rows), 0) AS total_rows
                FROM information_schema.TABLES 
                WHERE table_schema = ?",
                [$database]
            );

            return [
                'database' => $database,
                'mysql_version' => $mysqlVersion,
                'total_size_mb' => round((float) ($sizeStats->size_mb ?? 0), 2),
                'data_size_mb' => round((float) ($sizeStats->data_mb ?? 0), 2),
                'index_size_mb' => round((float) ($sizeStats->index_mb ?? 0), 2),
                'tables_count' => (int) ($sizeStats->tables_count ?? 0),
                'total_rows' => (int) ($sizeStats->total_rows ?? 0),
                'charset' => config('database.connections.mysql.charset', 'utf8mb4'),
                'collation' => config('database.connections.mysql.collation', 'utf8mb4_unicode_ci'),
            ];
        } catch (\Throwable $e) {
            Log::error('Error consultando estadísticas de MySQL: ' . $e->getMessage());

            return [
                'database' => $database,
                'mysql_version' => 'MySQL 8.0',
                'total_size_mb' => 0,
                'data_size_mb' => 0,
                'index_size_mb' => 0,
                'tables_count' => 0,
                'total_rows' => 0,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ];
        }
    }

    /**
     * Valida de forma rigurosa el nombre de archivo y garantiza que reside en el directorio de backups.
     */
    protected function validateAndResolvePath(string $filename): string
    {
        $clean = basename($filename);

        if ($clean !== $filename || !preg_match('/^[a-zA-Z0-9_\-\.]+\.(sql|gz)$/', $filename)) {
            throw new \InvalidArgumentException('Nombre de archivo de respaldo no válido o inseguro.');
        }

        $dir = realpath($this->getBackupDirectory());
        $path = $this->getBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
        $realPath = realpath($path);

        if (!$realPath || !File::exists($realPath) || !str_starts_with($realPath, $dir)) {
            throw new \RuntimeException('El archivo de respaldo especificado no existe en el almacenamiento.');
        }

        return $realPath;
    }

    /**
     * Buscar binario ejecutable en el sistema.
     */
    protected function findBinary(array $binaries): ?string
    {
        foreach ($binaries as $binary) {
            $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$binary}" : "which {$binary}";
            $output = [];
            $code = 0;
            @exec($cmd, $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }
        return null;
    }

    /**
     * Comprime un archivo en formato gzip (.gz).
     */
    protected function compressFile(string $sourceFile, string $targetFile): void
    {
        $fpIn = fopen($sourceFile, 'rb');
        $fpOut = gzopen($targetFile, 'wb9');

        if (!$fpIn || !$fpOut) {
            if ($fpIn) fclose($fpIn);
            if ($fpOut) gzclose($fpOut);
            throw new \RuntimeException('Error al abrir manejadores para comprimir en .gz');
        }

        while (!feof($fpIn)) {
            gzwrite($fpOut, fread($fpIn, 1024 * 512));
        }

        fclose($fpIn);
        gzclose($fpOut);
    }

    /**
     * Descomprime un archivo gzip (.gz).
     */
    protected function decompressGzFile(string $gzFile, string $targetFile): void
    {
        $fpIn = gzopen($gzFile, 'rb');
        $fpOut = fopen($targetFile, 'wb');

        if (!$fpIn || !$fpOut) {
            if ($fpIn) gzclose($fpIn);
            if ($fpOut) fclose($fpOut);
            throw new \RuntimeException('Error al abrir manejadores para descomprimir el archivo de respaldo.');
        }

        while (!gzeof($fpIn)) {
            fwrite($fpOut, gzread($fpIn, 1024 * 512));
        }

        gzclose($fpIn);
        fclose($fpOut);
    }

    /**
     */
    protected function createNativePhpBackup(string $targetGzFile, string $database): void
    {
        $gz = null;
        for ($i = 0; $i < 6; $i++) {
            $gz = @gzopen($targetGzFile, 'wb9');
            if ($gz) break;
            usleep(30000);
        }
        if (!$gz) {
            throw new \RuntimeException('No se pudo inicializar el archivo de respaldo comprimido en ' . $targetGzFile);
        }

        gzwrite($gz, "-- FlixHN Database Backup (High-Performance Native Generator)\n");
        gzwrite($gz, "-- Generado: " . now()->toIso8601String() . "\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        gzwrite($gz, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        gzwrite($gz, "SET time_zone = '+00:00';\n\n");

        $tables = DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tableKey = 'Tables_in_' . $database;

        foreach ($tables as $t) {
            $arr = (array) $t;
            $tableName = $arr[$tableKey] ?? (array_values($arr)[0] ?? null);
            if (!$tableName) continue;

            gzwrite($gz, "-- --------------------------------------------------------\n");
            gzwrite($gz, "-- Estructura de tabla: `{$tableName}`\n");
            gzwrite($gz, "-- --------------------------------------------------------\n");
            gzwrite($gz, "DROP TABLE IF EXISTS `{$tableName}`;\n");

            $createRow = DB::selectOne("SHOW CREATE TABLE `{$tableName}`");
            $createStatement = $createRow->{'Create Table'} ?? '';
            gzwrite($gz, $createStatement . ";\n\n");

            gzwrite($gz, "-- Datos de tabla: `{$tableName}`\n");

            DB::table($tableName)->orderBy(DB::raw('1'))->chunk(250, function ($chunk) use ($gz, $tableName) {
                if ($chunk->count() === 0) return;

                $insertValues = [];
                foreach ($chunk as $row) {
                    $values = [];
                    foreach ((array) $row as $val) {
                        if (is_null($val)) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = DB::getPdo()->quote((string) $val);
                        }
                    }
                    $insertValues[] = '(' . implode(', ', $values) . ')';
                }

                gzwrite($gz, "INSERT INTO `{$tableName}` VALUES " . implode(",\n", $insertValues) . ";\n");
            });

            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    /**
     * Restauración nativa mediante sentencias PHP PDO con desactivación de claves foráneas.
     */
    protected function restoreNativePhp(string $sqlFilePath): void
    {
        $handle = fopen($sqlFilePath, 'r');
        if (!$handle) {
            throw new \RuntimeException('No fue posible abrir el archivo de respaldo para su lectura.');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $query = '';
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $query .= $line;

            if (str_ends_with($trimmed, ';')) {
                DB::unprepared($query);
                $query = '';
            }
        }

        if (trim($query) !== '') {
            DB::unprepared($query);
        }

        fclose($handle);
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Formatear bytes en representación legible.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Obtener tamaño de archivo de forma segura tolerando demoras de stat en VirtioFS.
     */
    public function getSafeFileSize(string $path): int
    {
        for ($i = 0; $i < 10; $i++) {
            clearstatcache(true, $path);
            $size = @filesize($path);
            if ($size !== false && $size > 0) {
                return $size;
            }
            usleep(25000);
        }
        return (int) (@filesize($path) ?: 0);
    }
}
