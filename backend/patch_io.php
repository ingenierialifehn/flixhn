<?php

$classLoaderFile = __DIR__ . '/vendor/composer/ClassLoader.php';
if (file_exists($classLoaderFile)) {
    $content = file_get_contents($classLoaderFile);
    $search = "self::\$includeFile = \\Closure::bind(static function(\$file) {\n            include \$file;\n        }, null, null);";
    $replace = "self::\$includeFile = \\Closure::bind(static function(\$file) {\n            for (\$a = 0; \$a < 10; \$a++) {\n                \$res = @include \$file;\n                if (\$res !== false) return;\n                usleep(25000);\n            }\n            include \$file;\n        }, null, null);";
    if (strpos($content, $search) !== false) {
        $content = str_replace($search, $replace, $content);
        file_put_contents($classLoaderFile, $content);
        echo "ClassLoader patched successfully.\n";
    }
}

$loadConfigFile = __DIR__ . '/vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadConfiguration.php';
if (file_exists($loadConfigFile)) {
    $content = file_get_contents($loadConfigFile);
    $pattern = '/\$config\s*=\s*\(.*require\s*\$path.*?\)\(\);/s';
    $replace = '$config = (function () use ($path) { for ($a = 0; $a < 10; $a++) { $r = @include $path; if ($r !== false && is_array($r)) return $r; usleep(25000); } return require $path; })();';
    $content = preg_replace($pattern, $replace, $content);
    file_put_contents($loadConfigFile, $content);
    echo "LoadConfiguration patched successfully.\n";
}

$filesystemFile = __DIR__ . '/vendor/laravel/framework/src/Illuminate/Filesystem/Filesystem.php';
if (file_exists($filesystemFile)) {
    $content = file_get_contents($filesystemFile);
    
    // Patch replace
    $search1 = "rename(\$tempPath, \$path);";
    $replace1 = "if (@rename(\$tempPath, \$path) === false) { @unlink(\$path); if (@rename(\$tempPath, \$path) === false) { file_put_contents(\$path, \$content); @unlink(\$tempPath); } }";
    if (strpos($content, $search1) !== false) {
        $content = str_replace($search1, $replace1, $content);
    }

    // Patch tempnam notice
    $search_temp = '$tempPath = tempnam(dirname($path), basename($path));';
    $replace_temp = '$tempPath = @tempnam(sys_get_temp_dir(), basename($path));';
    if (strpos($content, $search_temp) !== false) {
        $content = str_replace($search_temp, $replace_temp, $content);
    }

    // Patch getRequire
    $pattern2 = '/public function getRequire\(\$path, array \$data = \[\]\)\s*\{.*?return require \$__path;\s*\}\(\);\s*\}\s*throw/s';
    $replace2 = "public function getRequire(\$path, array \$data = [])\n    {\n        if (\$this->isFile(\$path)) {\n            \$__path = \$path;\n            \$__data = \$data;\n\n            return (static function () use (\$__path, \$__data) {\n                extract(\$__data, EXTR_SKIP);\n\n                for (\$a = 0; \$a < 10; \$a++) {\n                    \$r = @include \$__path;\n                    if (\$r !== false) return \$r;\n                    usleep(25000);\n                }\n                return require \$__path;\n            })();\n        }\n\n        throw";
    $content = preg_replace($pattern2, $replace2, $content);

    // Patch requireOnce
    $pattern3 = '/public function requireOnce\(\$file, array \$data = \[\]\)\s*\{.*?return require_once \$__path;\s*\}\(\);\s*\}\s*throw/s';
    $replace3 = "public function requireOnce(\$file, array \$data = [])\n    {\n        if (\$this->isFile(\$file)) {\n            \$__path = \$file;\n            \$__data = \$data;\n\n            return (static function () use (\$__path, \$__data) {\n                extract(\$__data, EXTR_SKIP);\n\n                for (\$a = 0; \$a < 10; \$a++) {\n                    \$r = @include_once \$__path;\n                    if (\$r !== false) return \$r;\n                    usleep(25000);\n                }\n                return require_once \$__path;\n            })();\n        }\n\n        throw";
    $content = preg_replace($pattern3, $replace3, $content);

    file_put_contents($filesystemFile, $content);
    echo "Filesystem patched successfully.\n";
}
