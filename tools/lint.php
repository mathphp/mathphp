<?php

declare(strict_types=1);

$paths = array_slice($argv ?? [], 1);

if ($paths === []) {
    $paths = ['src', 'tests', 'tools'];
}

$files = [];
$hasErrors = false;

foreach ($paths as $path) {
    if (is_file($path)) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $files[] = $path;
        }

        continue;
    }

    if (!is_dir($path)) {
        fwrite(STDERR, sprintf("Lint path does not exist: %s\n", $path));
        $hasErrors = true;
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$files = array_values(array_unique($files));
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No PHP files found to lint.\n");
    exit(1);
}

foreach ($files as $file) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        fwrite(STDERR, sprintf("Could not start PHP syntax check for %s.\n", $file));
        $hasErrors = true;
        continue;
    }

    fclose($pipes[0]);
    $standardOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $standardError = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    if (proc_close($process) === 0) {
        continue;
    }

    $hasErrors = true;
    fwrite(STDERR, sprintf("Syntax check failed for %s:\n", $file));
    fwrite(STDERR, trim((string) $standardOutput . (string) $standardError) . "\n");
}

if ($hasErrors) {
    exit(1);
}

printf("Syntax OK: %d PHP file(s).\n", count($files));
