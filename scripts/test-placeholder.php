<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$tests = [];
foreach (['tests/Unit'] as $path) {
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $tests[] = $file->getPathname();
        }
    }
}

sort($tests);
foreach ($tests as $test) {
    require $test;
}

echo 'Larena Monitoring contract tests passed: ' . count($tests) . " file(s).\n";
