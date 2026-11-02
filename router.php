<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/proxy.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

if ($path === '/site' || str_starts_with($path, '/site/')) {
    bdris_proxy_handle(substr($path, strlen('/site')));
    return true;
}

foreach (['/br/', '/dr/', '/application/', '/user/', '/login', '/logout', '/api/', '/admin/', '/language/', '/TSPD/'] as $prefix) {
    if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
        bdris_proxy_handle($path);
        return true;
    }
}

if ($path === '/warm') {
    require_once __DIR__ . '/lib/warm.php';
    bdris_warm_stream();
    return true;
}

if ($path === '/healthz') {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    return true;
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /site/\n";
    return true;
}

$publicFile = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/public/index.php';
return true;
