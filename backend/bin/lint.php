<?php

declare(strict_types=1);

// `composer lint` — php -l over src/, database/ and public/ (SPEC §13.3).
$root = dirname(__DIR__);
$targets = ['src', 'database', 'public', 'config', 'tests'];
$failed = 0;
$checked = 0;
foreach ($targets as $target) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $target));
    foreach ($iterator as $file) {
        if (substr((string) $file, -4) !== '.php') {
            continue;
        }
        $checked++;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $file) . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, implode("\n", $output) . "\n");
            $failed++;
        }
        $output = [];
    }
}
fwrite(STDOUT, $failed === 0 ? "Lint OK ({$checked} file)\n" : "Lint FAILED ({$failed} errori)\n");
exit($failed === 0 ? 0 : 1);
