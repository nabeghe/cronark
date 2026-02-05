<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

// Clean up any leftover storage files from previous tests
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronark_test';
if (is_dir($tempDir)) {
    $files = glob($tempDir . '/*');
    if ($files !== false) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    @rmdir($tempDir);
}

// Also clean integration test dirs
$pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronark_integration_*';
$dirs = glob($pattern, GLOB_ONLYDIR);
if ($dirs !== false) {
    foreach ($dirs as $dir) {
        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }
}

echo "Bootstrap completed successfully.\n";
