<?php

declare(strict_types=1);

// Runs `php -l` on every PHP file under the given folders (vendor/ skipped).
// Usage: php tools/php-lint.php <dir> [<dir> ...]

$dirs = array_slice($argv, 1);
if ($dirs === []) {
    fwrite(STDERR, "Usage: php tools/php-lint.php <dir> [<dir> ...]\n");
    exit(2);
}

$failed = 0;
$checked = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('#[\\\\/]vendor[\\\\/]#', $path) === 1) {
            continue;
        }
        $checked++;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $failed++;
            echo implode("\n", $output), "\n";
        }
        $output = [];
    }
}

echo "php-lint: {$checked} file(s) checked, {$failed} with errors\n";
exit($failed === 0 ? 0 : 1);
