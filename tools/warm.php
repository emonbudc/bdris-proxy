<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/warm.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
bdris_warm($base, function (string $line): void {
    echo $line, "\n";
});
