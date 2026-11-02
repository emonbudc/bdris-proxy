<?php
declare(strict_types=1);

const BDRIS_UPSTREAM = 'https://bdris.gov.bd';
const BDRIS_BASE = '/site';
const BDRIS_CACHE_DIR = __DIR__ . '/../cache';
const BDRIS_CACHE_TTL = 86400;
const BDRIS_CACHE_VERSION = '3';
const BDRIS_HTML_CACHE_TTL = 300;

function bdris_proxy_handle(string $path): void
{
    $uri = $path === '' ? '/' : $path;
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query !== '') {
        $uri .= '?' . $query;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $body = null;
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $body = file_get_contents('php://input');
    }

    if ($method === 'GET' && bdris_html_cacheable($uri)) {
        $cachedHtml = bdris_html_cache_get($uri);
        if ($cachedHtml !== null) {
            bdris_emit($cachedHtml, $cachedHtml['cookies'], $uri, true, true);
            return;
        }
    }

    if ($method === 'GET') {
        $cached = bdris_cache_get($uri);
        if ($cached !== null) {
            bdris_emit($cached, [], $uri, true);
            return;
        }
    }

    $url = BDRIS_UPSTREAM . $uri;
    $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';
    $clientHeaders = bdris_request_headers();
    $result = null;
    $extraCookies = [];
    $bootstrapped = false;
    $themed = preg_match('~^/(br|dr|application)(/|$)~', $uri) === 1;
    $htmlCacheable = $method === 'GET' && bdris_html_cacheable($uri);

    if ($method !== 'GET' && $method !== 'HEAD') {
        bdris_html_cache_flush();
    }

    for ($hop = 0; $hop < 6; $hop++) {
        if ($method === 'GET') {
            $result = bdris_request_stream($url, $method, $body, $clientHeaders, $cookieHeader, $uri, $themed, $extraCookies, $htmlCacheable);
            if (($result['streamed'] ?? false) === true) {
                return;
            }
        } else {
            $result = bdris_request($url, $method, $body, $clientHeaders, $cookieHeader);
        }
        $status = $result['status'];
        $location = bdris_header($result['headers'], 'location');

        if (!$bootstrapped && $status === 403 && str_contains($result['body'], 'Please type on browser')) {
            [$bootstrapCookie, $bootstrapSetCookies] = bdris_bootstrap($clientHeaders);
            if ($bootstrapCookie !== '') {
                $cookieHeader = bdris_merge_cookies($cookieHeader, $bootstrapSetCookies);
                $extraCookies = $bootstrapSetCookies;
                $bootstrapped = true;
                continue;
            }
        }

        if ($location !== null && $status >= 300 && $status < 400) {
            $next = bdris_absolute_url($location, $url);
            $cookieHeader = bdris_merge_cookies($cookieHeader, $result['headers']);
            if (bdris_same_host($next)) {
                $url = $next;
                if ($status === 303 || (($status === 301 || $status === 302) && $method === 'POST')) {
                    $method = 'GET';
                    $body = null;
                }
                continue;
            }
            bdris_send_redirect($next, $status);
            return;
        }
        break;
    }

    if ($result === null) {
        http_response_code(502);
        echo 'Upstream unavailable';
        return;
    }

    $cookieHeader = bdris_merge_cookies($cookieHeader, $result['headers']);
    bdris_emit($result, $extraCookies, $uri);
}

function bdris_request_headers(): array
{
    $out = [];
    $skip = [
        'host', 'connection', 'content-length', 'accept-encoding',
        'proxy-connection', 'transfer-encoding', 'te', 'upgrade',
        'keep-alive', 'cookie',
    ];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!in_array($name, $skip, true)) {
                $out[$name] = $value;
            }
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $out['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    foreach (['referer', 'origin'] as $name) {
        if (isset($out[$name])) {
            $out[$name] = bdris_map_upstream_url($out[$name], $name === 'origin');
        }
    }
    return $out;
}

function bdris_map_upstream_url(string $value, bool $originOnly = false): string
{
    $host = parse_url($value, PHP_URL_HOST);
    if ($host === null) {
        return $value;
    }
    $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $isLocal = $host === $requestHost || in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);
    if (!$isLocal) {
        return $value;
    }
    if ($originOnly) {
        return BDRIS_UPSTREAM;
    }
    $path = parse_url($value, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    if ($path === BDRIS_BASE) {
        $path = '/';
    } elseif (str_starts_with($path, BDRIS_BASE . '/')) {
        $path = substr($path, strlen(BDRIS_BASE));
    }
    $query = parse_url($value, PHP_URL_QUERY);
    return BDRIS_UPSTREAM . $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

function bdris_request(string $url, string $method, ?string $body, array $headers, string $cookieHeader): array
{
    $responseHeaders = [];
    $ch = curl_init($url);

    $curlHeaders = ['Expect:'];
    foreach ($headers as $name => $value) {
        $curlHeaders[] = $name . ': ' . $value;
    }
    if ($cookieHeader !== '') {
        $curlHeaders[] = 'Cookie: ' . $cookieHeader;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders): int {
            $trim = trim($line);
            if ($trim === '') {
                return strlen($line);
            }
            if (stripos($trim, 'HTTP/') === 0) {
                $responseHeaders = [];
                return strlen($line);
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                return strlen($line);
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $responseHeaders[] = [$name, $value];
            return strlen($line);
        },
    ]);

    if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($data === false) {
        $data = $error !== '' ? 'Upstream error: ' . $error : 'Upstream error';
        $status = 502;
        $responseHeaders = [['content-type', 'text/plain; charset=utf-8']];
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $data,
        'url' => $url,
    ];
}

function bdris_header(array $headers, string $name): ?string
{
    foreach ($headers as [$key, $value]) {
        if ($key === $name) {
            return $value;
        }
    }
    return null;
}

function bdris_absolute_url(string $location, string $base): string
{
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? 'bdris.gov.bd';
    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $location;
    }
    $path = $parts['path'] ?? '/';
    $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
    return $scheme . '://' . $host . $dir . $location;
}

function bdris_same_host(string $url): bool
{
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    return $host === 'bdris.gov.bd' || $host === 'www.bdris.gov.bd';
}

function bdris_send_redirect(string $url, int $status): void
{
    $location = bdris_rewrite_url($url);
    http_response_code($status);
    header('Location: ' . $location);
}

function bdris_rewrite_url(string $url): string
{
    if (preg_match('~^https?://(?:www\.)?bdris\.gov\.bd(.*)$~i', $url, $m)) {
        return BDRIS_BASE . ($m[1] === '' ? '/' : $m[1]);
    }
    return $url;
}

function bdris_merge_cookies(string $cookieHeader, array $headers): string
{
    $jar = [];
    if ($cookieHeader !== '') {
        foreach (explode(';', $cookieHeader) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $jar[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }
    }
    foreach ($headers as [$name, $value]) {
        if ($name !== 'set-cookie') {
            continue;
        }
        $first = trim(explode(';', $value)[0]);
        $eq = strpos($first, '=');
        if ($eq === false) {
            continue;
        }
        $jar[substr($first, 0, $eq)] = substr($first, $eq + 1);
    }
    $parts = [];
    foreach ($jar as $key => $value) {
        $parts[] = $key . '=' . $value;
    }
    return implode('; ', $parts);
}

function bdris_bootstrap(array $clientHeaders): array
{
    $headers = $clientHeaders;
    $headers['sec-fetch-dest'] = 'document';
    $headers['sec-fetch-mode'] = 'navigate';
    $headers['sec-fetch-site'] = 'none';
    unset($headers['sec-fetch-user']);

    $result = bdris_request(BDRIS_UPSTREAM . '/', 'GET', null, $headers, '');
    $setCookies = [];
    foreach ($result['headers'] as [$name, $value]) {
        if ($name === 'set-cookie') {
            $setCookies[] = [$name, $value];
        }
    }

    return [bdris_merge_cookies('', $setCookies), $setCookies];
}

function bdris_emit(array $result, array $extraCookies = [], string $uri = '', bool $fromCache = false, bool $processed = false): void
{
    $status = (int) $result['status'];
    $headers = $result['headers'];
    $body = $result['body'];
    $contentType = strtolower(bdris_header($headers, 'content-type') ?? '');
    $isHtml = str_contains($contentType, 'text/html');
    $isCss = str_contains($contentType, 'text/css');
    $themed = $isHtml && preg_match('~^/(br|dr|application)(/|$)~', $uri) === 1;

    if (!$processed && $isHtml && $body !== '') {
        $body = bdris_rewrite_html($body, $themed);
    } elseif (!$processed && $isCss && $body !== '') {
        $body = bdris_rewrite_css($body);
    }

    $acceptEncoding = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    $compressible = str_starts_with($contentType, 'text/')
        || str_contains($contentType, 'javascript')
        || str_contains($contentType, 'json')
        || str_contains($contentType, 'svg')
        || str_contains($contentType, 'xml');
    $gzip = $compressible && str_contains($acceptEncoding, 'gzip') && strlen($body) > 1024;

    $cacheable = bdris_is_cacheable($status, $headers, $contentType, $body);
    $etag = '';
    if ($cacheable) {
        $etag = '"' . substr(hash('sha1', $body), 0, 20) . ($gzip ? '-gz' : '') . '"';
        $ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNone) && $ifNone !== '' && str_contains($ifNone, $etag)) {
            http_response_code(304);
            header('Cache-Control: public, max-age=' . BDRIS_CACHE_TTL);
            header('ETag: ' . $etag);
            if ($gzip) {
                header('Vary: Accept-Encoding', false);
            }
            return;
        }
        if (!$fromCache) {
            bdris_cache_put($uri, $status, $headers, $body);
        }
    }

    $drop = [
        'content-length', 'transfer-encoding', 'connection', 'content-encoding',
        'content-security-policy', 'content-security-policy-report-only',
        'x-frame-options', 'strict-transport-security', 'alt-svc', 'report-to',
        'nel', 'server', 'x-powered-by',
    ];
    if ($cacheable) {
        $drop = array_merge($drop, ['cache-control', 'expires', 'pragma', 'etag', 'last-modified', 'age']);
    }

    http_response_code($status);
    if ($processed && $isHtml) {
        header('Cache-Control: no-store');
    }
    $sent = [];
    foreach ($extraCookies as $cookie) {
        $cookieValue = is_array($cookie) ? (string) ($cookie[1] ?? '') : (string) $cookie;
        if ($cookieValue !== '') {
            header('Set-Cookie: ' . bdris_clean_set_cookie($cookieValue), false);
        }
    }
    foreach ($headers as [$name, $value]) {
        if (in_array($name, $drop, true)) {
            continue;
        }
        if ($name === 'set-cookie') {
            if ($cacheable) {
                continue;
            }
            header('Set-Cookie: ' . bdris_clean_set_cookie($value), false);
            continue;
        }
        if ($name === 'location') {
            header('Location: ' . bdris_rewrite_url($value), false);
            continue;
        }
        if (isset($sent[$name])) {
            continue;
        }
        $sent[$name] = true;
        header($name . ': ' . $value);
    }
    if ($cacheable) {
        header('Cache-Control: public, max-age=' . BDRIS_CACHE_TTL);
        header('ETag: ' . $etag);
    }
    if ($gzip) {
        $encoded = gzencode($body, 5);
        if ($encoded !== false) {
            $body = $encoded;
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding', false);
        }
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
}

function bdris_is_cacheable(int $status, array $headers, string $contentType, string $body): bool
{
    if ($status !== 200 || $body === '') {
        return false;
    }
    $ct = strtolower(trim(explode(';', $contentType)[0]));
    $allowed = [
        'text/css', 'application/javascript', 'text/javascript',
        'application/x-javascript', 'application/vnd.ms-fontobject',
    ];
    $isAsset = in_array($ct, $allowed, true)
        || str_starts_with($ct, 'image/')
        || str_starts_with($ct, 'font/')
        || str_starts_with($ct, 'application/font');
    if (!$isAsset) {
        return false;
    }
    return true;
}

function bdris_cache_path(string $uri, string $ext): string
{
    return BDRIS_CACHE_DIR . '/' . hash('sha256', BDRIS_CACHE_VERSION . "\n" . $uri) . '.' . $ext;
}

function bdris_html_cacheable(string $uri): bool
{
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $uri;
    }
    return (bool) preg_match('~^/(?:(?:br|dr)/(?:application|correction|reprint)|application)(?:/|$)~', $path);
}

function bdris_html_cache_path(string $uri, string $ext): string
{
    return BDRIS_CACHE_DIR . '/h' . hash('sha256', BDRIS_CACHE_VERSION . "\nhtml\n" . $uri) . '.' . $ext;
}

function bdris_html_cache_get(string $uri): ?array
{
    $metaPath = bdris_html_cache_path($uri, 'meta');
    $bodyPath = bdris_html_cache_path($uri, 'body');
    if (!is_file($metaPath) || !is_file($bodyPath)) {
        return null;
    }
    $raw = @file_get_contents($metaPath);
    if ($raw === false) {
        return null;
    }
    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['created'])) {
        return null;
    }
    if (time() - (int) $data['created'] > BDRIS_HTML_CACHE_TTL) {
        @unlink($metaPath);
        @unlink($bodyPath);
        return null;
    }
    $body = @file_get_contents($bodyPath);
    if ($body === false) {
        return null;
    }
    return [
        'status' => (int) ($data['status'] ?? 200),
        'headers' => is_array($data['headers'] ?? null) ? $data['headers'] : [],
        'body' => $body,
        'url' => BDRIS_UPSTREAM . $uri,
        'cookies' => is_array($data['cookies'] ?? null) ? $data['cookies'] : [],
    ];
}

function bdris_html_cache_put(string $uri, int $status, array $headers, array $cookies, string $body): void
{
    if ($status !== 200 || $body === '') {
        return;
    }
    if (!is_dir(BDRIS_CACHE_DIR) && !@mkdir(BDRIS_CACHE_DIR, 0775, true) && !is_dir(BDRIS_CACHE_DIR)) {
        return;
    }
    $metaPath = bdris_html_cache_path($uri, 'meta');
    $bodyPath = bdris_html_cache_path($uri, 'body');

    $bodyTmp = @tempnam(BDRIS_CACHE_DIR, 'hbody');
    if ($bodyTmp === false || @file_put_contents($bodyTmp, $body) === false) {
        if ($bodyTmp !== false) {
            @unlink($bodyTmp);
        }
        return;
    }
    if (!@rename($bodyTmp, $bodyPath)) {
        @unlink($bodyTmp);
        return;
    }

    $skip = ['set-cookie', 'content-length', 'transfer-encoding', 'connection', 'content-encoding', 'cache-control', 'expires', 'pragma', 'etag', 'last-modified', 'age', 'date'];
    $storeHeaders = [];
    foreach ($headers as $header) {
        if (!in_array($header[0], $skip, true)) {
            $storeHeaders[] = $header;
        }
    }
    $metaTmp = @tempnam(BDRIS_CACHE_DIR, 'hmeta');
    if ($metaTmp === false) {
        return;
    }
    $serialized = serialize(['status' => $status, 'headers' => $storeHeaders, 'cookies' => $cookies, 'created' => time()]);
    if (@file_put_contents($metaTmp, $serialized) === false) {
        @unlink($metaTmp);
        return;
    }
    @rename($metaTmp, $metaPath);
}

function bdris_html_cache_flush(): void
{
    foreach (glob(BDRIS_CACHE_DIR . '/h*') ?: [] as $file) {
        @unlink($file);
    }
}

function bdris_cache_get(string $uri): ?array
{
    $metaPath = bdris_cache_path($uri, 'meta');
    $bodyPath = bdris_cache_path($uri, 'body');
    if (!is_file($metaPath) || !is_file($bodyPath)) {
        return null;
    }
    $raw = @file_get_contents($metaPath);
    if ($raw === false) {
        return null;
    }
    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['created'])) {
        return null;
    }
    if (time() - (int) $data['created'] > BDRIS_CACHE_TTL) {
        @unlink($metaPath);
        @unlink($bodyPath);
        return null;
    }
    $body = @file_get_contents($bodyPath);
    if ($body === false) {
        return null;
    }
    return [
        'status' => (int) ($data['status'] ?? 200),
        'headers' => is_array($data['headers'] ?? null) ? $data['headers'] : [],
        'body' => $body,
        'url' => BDRIS_UPSTREAM . $uri,
    ];
}

function bdris_cache_put(string $uri, int $status, array $headers, string $body): void
{
    if (!is_dir(BDRIS_CACHE_DIR) && !@mkdir(BDRIS_CACHE_DIR, 0775, true) && !is_dir(BDRIS_CACHE_DIR)) {
        return;
    }
    $metaPath = bdris_cache_path($uri, 'meta');
    $bodyPath = bdris_cache_path($uri, 'body');

    $bodyTmp = @tempnam(BDRIS_CACHE_DIR, 'body');
    if ($bodyTmp === false || @file_put_contents($bodyTmp, $body) === false) {
        if ($bodyTmp !== false) {
            @unlink($bodyTmp);
        }
        return;
    }
    if (!@rename($bodyTmp, $bodyPath)) {
        @unlink($bodyTmp);
        return;
    }

    $metaTmp = @tempnam(BDRIS_CACHE_DIR, 'meta');
    if ($metaTmp === false) {
        return;
    }
    $skip = ['set-cookie', 'content-length', 'transfer-encoding', 'connection', 'content-encoding', 'cache-control', 'expires', 'pragma', 'etag', 'last-modified', 'age', 'date'];
    $storeHeaders = [];
    foreach ($headers as $header) {
        if (!in_array($header[0], $skip, true)) {
            $storeHeaders[] = $header;
        }
    }
    $serialized = serialize(['status' => $status, 'headers' => $storeHeaders, 'created' => time()]);
    if (@file_put_contents($metaTmp, $serialized) === false) {
        @unlink($metaTmp);
        return;
    }
    @rename($metaTmp, $metaPath);
}

function bdris_clean_set_cookie(string $value): string
{
    $parts = explode(';', $value);
    $kept = [array_shift($parts)];
    foreach ($parts as $part) {
        $lower = strtolower(trim($part));
        if (str_starts_with($lower, 'domain=') || $lower === 'secure') {
            continue;
        }
        $kept[] = trim($part);
    }
    return implode('; ', $kept);
}

function bdris_rewrite_html(string $html, bool $themed = false): string
{
    $html = bdris_rewrite_markup($html);
    $html = bdris_inject_shim($html);
    if ($themed) {
        $html = bdris_inject_theme($html);
    }
    return $html;
}

function bdris_rewrite_markup(string $html): string
{
    $html = preg_replace_callback(
        '~\b(href|src|action|poster|data-src|formaction|srcset|content)\s*=\s*(["\'])(.*?)\2~is',
        function (array $m): string {
            $value = $m[3];
            if (in_array(strtolower($m[1]), ['srcset', 'content'], true)) {
                $value = preg_replace_callback(
                    '~([^\s,]+)~',
                    fn (array $x): string => bdris_rewrite_asset_url($x[1]),
                    $value
                );
            } else {
                $value = bdris_rewrite_asset_url($value);
            }
            return $m[1] . '=' . $m[2] . $value . $m[2];
        },
        $html
    );

    $html = preg_replace_callback(
        '~url\(\s*([\'"]?)([^)\'"]*?)\1\s*\)~i',
        fn (array $m): string => 'url(' . $m[1] . bdris_rewrite_asset_url($m[2]) . $m[1] . ')',
        $html
    );

    return bdris_rewrite_absolute($html);
}


function bdris_stream_begin(array $headers, array $extraCookies, string $uri, bool $themed, bool $htmlCacheable): array
{
    $drop = ['content-length', 'transfer-encoding', 'connection', 'content-encoding', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'upgrade'];
    http_response_code(200);
    foreach ($extraCookies as [$cookieName, $cookieValue]) {
        header('Set-Cookie: ' . bdris_clean_set_cookie($cookieValue), false);
    }
    $sent = [];
    foreach ($headers as [$name, $value]) {
        if (in_array($name, $drop, true)) {
            continue;
        }
        if ($name === 'set-cookie') {
            header('Set-Cookie: ' . bdris_clean_set_cookie($value), false);
            continue;
        }
        if ($name === 'location') {
            $value = bdris_rewrite_absolute($value);
        }
        if (isset($sent[$name])) {
            header($name . ': ' . $value, false);
            continue;
        }
        $sent[$name] = true;
        header($name . ': ' . $value, true);
    }

    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $gzip = stripos($accept, 'gzip') !== false;
    if ($gzip) {
        header('Content-Encoding: gzip', true);
        header('Vary: Accept-Encoding', false);
    }
    $storeCookies = [];
    foreach ($extraCookies as [$cookieName, $cookieValue]) {
        $storeCookies[] = bdris_clean_set_cookie($cookieValue);
    }
    foreach ($headers as [$name, $value]) {
        if ($name === 'set-cookie') {
            $storeCookies[] = bdris_clean_set_cookie($value);
        }
    }

    return [
        'gzip' => $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 5]) : null,
        'buffer' => '',
        'themed' => $themed,
        'head' => false,
        'body' => false,
        'footer' => false,
        'cacheable' => $htmlCacheable,
        'uri' => $uri,
        'headers' => $headers,
        'cookies' => $storeCookies,
        'saved' => '',
    ];
}

function bdris_stream_filter(array &$state, string $chunk, bool $final): string
{
    $head = bdris_inject_shim_markup();
    if ($state['themed']) {
        $head .= bdris_theme_head();
    }

    $chunk = bdris_rewrite_markup($chunk);

    if (!$state['head'] && stripos($chunk, '</head>') !== false) {
        $chunk = preg_replace('~</head>~i', $head . '</head>', $chunk, 1);
        $state['head'] = true;
    }

    if ($state['themed']) {
        if (!$state['body'] && preg_match('~<body[^>]*>~i', $chunk)) {
            $chunk = preg_replace('~<body([^>]*)>~i', '<body$1>' . bdris_theme_header(), $chunk, 1);
            $state['body'] = true;
        }
        if (!$state['footer'] && stripos($chunk, '</body>') !== false) {
            $chunk = preg_replace('~</body>~i', bdris_theme_footer() . '</body>', $chunk, 1);
            $state['footer'] = true;
        }
    }

    if ($final) {
        if (!$state['head']) {
            $chunk .= $head;
        }
        if ($state['themed'] && !$state['body']) {
            $chunk = bdris_theme_header() . $chunk;
        }
        if ($state['themed'] && !$state['footer']) {
            $chunk .= bdris_theme_footer();
        }
    }
    if ($state['cacheable']) {
        $state['saved'] .= $chunk;
    }
    return $chunk;
}

function bdris_inject_shim_markup(): string
{
    return '<script>' . bdris_shim_js() . '</script>';
}

function bdris_stream_output(array &$state, string $data): void
{
    if ($data === '') {
        return;
    }
    if ($state['gzip'] !== null) {
        $data = deflate_add($state['gzip'], $data, ZLIB_SYNC_FLUSH);
    }
    if ($data === '') {
        return;
    }
    echo $data;
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

function bdris_stream_write(array &$state, string $data, bool $final = false): void
{
    $state['buffer'] .= $data;
    $length = strlen($state['buffer']);
    $safe = $final ? $length : max(0, $length - 1024);
    if ($safe <= 0) {
        return;
    }
    $chunk = substr($state['buffer'], 0, $safe);
    $state['buffer'] = substr($state['buffer'], $safe);
    bdris_stream_output($state, bdris_stream_filter($state, $chunk, false));
}

function bdris_stream_finish(array &$state): void
{
    if ($state['buffer'] !== '') {
        $chunk = bdris_stream_filter($state, $state['buffer'], true);
        $state['buffer'] = '';
        bdris_stream_output($state, $chunk);
    } elseif ($state['themed'] && (!$state['head'] || !$state['footer'])) {
        $chunk = bdris_stream_filter($state, '', true);
        bdris_stream_output($state, $chunk);
    }
    if ($state['gzip'] !== null) {
        $tail = deflate_add($state['gzip'], '', ZLIB_FINISH);
        if ($tail !== '') {
            echo $tail;
        }
        flush();
    }
    if ($state['cacheable'] && $state['saved'] !== '') {
        bdris_html_cache_put($state['uri'], 200, $state['headers'], $state['cookies'], $state['saved']);
    }
}

function bdris_request_stream(string $url, string $method, ?string $body, array $headers, string $cookieHeader, string $uri, bool $themed, array $extraCookies, bool $htmlCacheable = false): array
{
    $responseHeaders = [];
    $buffer = '';
    $state = null;
    $decided = false;
    $ch = curl_init($url);

    $curlHeaders = ['Expect:'];
    foreach ($headers as $name => $value) {
        $curlHeaders[] = $name . ': ' . $value;
    }
    if ($cookieHeader !== '') {
        $curlHeaders[] = 'Cookie: ' . $cookieHeader;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders): int {
            $trim = trim($line);
            if ($trim === '') {
                return strlen($line);
            }
            if (stripos($trim, 'HTTP/') === 0) {
                $responseHeaders = [];
                return strlen($line);
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                return strlen($line);
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $responseHeaders[] = [$name, $value];
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$responseHeaders, &$buffer, &$state, &$decided, $uri, $themed, $extraCookies, $htmlCacheable): int {
            if (!$decided) {
                $decided = true;
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $contentType = strtolower(bdris_header($responseHeaders, 'content-type') ?? '');
                if ($status === 200 && str_contains($contentType, 'text/html')) {
                    $state = bdris_stream_begin($responseHeaders, $extraCookies, $uri, $themed, $htmlCacheable);
                }
            }
            if ($state !== null) {
                bdris_stream_write($state, $data, false);
            } else {
                $buffer .= $data;
            }
            return strlen($data);
        },
    ]);

    if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($state !== null) {
        bdris_stream_finish($state);
        return ['streamed' => true, 'status' => $status];
    }

    if ($ok === false) {
        $buffer = $error !== '' ? 'Upstream error: ' . $error : 'Upstream error';
        $status = 502;
        $responseHeaders = [['content-type', 'text/plain; charset=utf-8']];
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $buffer,
        'url' => $url,
    ];
}

function bdris_theme_head(): string
{
    return '<link rel="stylesheet" href="/assets/br.css?v=3"><script>' . bdris_theme_js() . '</script>';
}

function bdris_inject_theme(string $html): string
{
    $html = preg_replace('~</head>~i', bdris_theme_head() . '</head>', $html, 1);

    $header = bdris_theme_header();
    $html = preg_replace('~<body([^>]*)>~i', '<body$1>' . $header, $html, 1);
    $html = preg_replace('~</body>~i', bdris_theme_footer() . '</body>', $html, 1);
    return $html;
}

function bdris_theme_header(): string
{
    return <<<'HTML'
<div class="xr-top">
<a class="xr-brand" href="/">
<span class="xr-logo"><img src="/assets/logo.png" alt="" width="34" height="34"></span>
<span class="xr-brand-text"><strong>BDRIS</strong><em>Birth Registration</em></span>
</a>
<nav class="xr-links">
<a href="/">Home</a>
<a href="/site/br/application">Apply</a>
<a href="/site/br/application/status">Status</a>
<a href="/site/login">Sign in</a>
<button type="button" class="xr-lang" onclick="if(window.languageChange){languageChange('en')}">EN</button>
</nav>
</div>
<div class="xr-subbar">
<div class="xr-subbar-inner">
<span class="xr-crumb" id="xr-crumb">Birth registration <b>/ New application</b></span>
<span class="xr-steps" id="xr-steps" hidden>
<span class="xr-step-count" id="xr-step">Step 1</span>
<span class="xr-step-name" id="xr-step-name"></span>
<span class="xr-step-bar"><i id="xr-step-fill"></i></span>
</span>
<a class="xr-help" href="/site/br/application/status">Already applied? Check status</a>
</div>
</div>
HTML;
}

function bdris_theme_footer(): string
{
    return <<<'HTML'
<div class="xr-footer">
<span>Custom frontend over a reverse proxy to bdris.gov.bd. Data is submitted to the official service.</span>
<nav>
<a href="/site/br/application">Apply</a>
<a href="/site/br/application/status">Status</a>
<a href="/site/br/correction">Correction</a>
<a href="/site/br/reprint/add">Reprint</a>
</nav>
</div>
HTML;
}

function bdris_theme_js(): string
{
    return <<<'JS'
(function(){
function label(){
var form=document.querySelector('.multi-step-form');
if(!form){return;}
var fields=[].filter.call(form.querySelectorAll('fieldset[id]'),function(f){return /(^|-)step-\d+$/.test(f.id);});
var active=form.querySelector('fieldset.active');
var box=document.getElementById('xr-steps');
var count=document.getElementById('xr-step');
var name=document.getElementById('xr-step-name');
var fill=document.getElementById('xr-step-fill');
if(!box||!count||!active||!fields.length){return;}
var idx=fields.indexOf(active);
if(idx<0){return;}
box.hidden=false;
count.textContent='Step '+(idx+1)+' of '+fields.length;
var h=active.querySelector('h5');
name.textContent=h?(h.textContent||'').replace(/\s+/g,' ').trim():'';
if(fill){fill.style.width=Math.round(((idx+1)/fields.length)*100)+'%';}
}
function crumb(){
var el=document.getElementById('xr-crumb');
if(!el){return;}
var p=location.pathname.replace(/\/+$/,'');
var map={'/site/br/application':'New application','/site/br/application/status':'Application status','/site/br/correction':'Correction','/site/br/reprint/add':'Reprint','/site/dr/application':'Death registration'};
var key='New application';
for(var k in map){if(p===k||p.indexOf(k+'/')===0){key=map[k];break;}}
var b=el.querySelector('b');
if(b){b.textContent='/ '+key;}
}
document.addEventListener('DOMContentLoaded',function(){
document.body.classList.add('xr-apply');
crumb();
label();
var form=document.querySelector('.multi-step-form');
if(!form||!window.MutationObserver){return;}
new MutationObserver(label).observe(form,{attributes:true,subtree:true,attributeFilter:['class']});
});
})();
JS;
}

function bdris_rewrite_css(string $css): string
{
    $css = preg_replace_callback(
        '~url\(\s*([\'"]?)([^)\'"]*?)\1\s*\)~i',
        fn (array $m): string => 'url(' . $m[1] . bdris_rewrite_asset_url($m[2]) . $m[1] . ')',
        $css
    );
    $css = preg_replace_callback(
        '~@import\s+([\'"])(.*?)\1~i',
        fn (array $m): string => '@import ' . $m[1] . bdris_rewrite_asset_url($m[2]) . $m[1],
        $css
    );
    return bdris_rewrite_absolute($css);
}

function bdris_rewrite_asset_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || $url[0] === '#') {
        return $url;
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return bdris_rewrite_url($url);
    }
    if ($url[0] === '/') {
        return BDRIS_BASE . $url;
    }
    return $url;
}

function bdris_rewrite_absolute(string $text): string
{
    $text = preg_replace('~https?://(?:www\.)?bdris\.gov\.bd~i', BDRIS_BASE, $text);
    return preg_replace('~https?:\\\\/\\\\/(?:www\\\\.)?bdris\\\\.gov\\\\.bd~i', BDRIS_BASE, $text);
}

function bdris_inject_shim(string $html): string
{
    $shim = '<script>' . bdris_shim_js() . '</script>';
    if (stripos($html, '</head>') !== false) {
        return preg_replace('~</head>~i', $shim . '</head>', $html, 1);
    }
    if (stripos($html, '</body>') !== false) {
        return preg_replace('~</body>~i', $shim . '</body>', $html, 1);
    }
    return $html . $shim;
}

function bdris_shim_js(): string
{
    return <<<'JS'
(function(){
var base="/site";
function map(u){
if(typeof u!=="string"){return u;}
if(u.indexOf(base)===0){return u;}
if(u[0]==="/"&&u[1]!=="/"){return base+u;}
try{
var p=new URL(u,location.href);
if(p.origin===location.origin&&p.pathname.indexOf(base)!==0){return base+p.pathname+p.search+p.hash;}
}catch(e){}
return u;
}
var f=window.fetch;
if(f){window.fetch=function(i,o){
try{
if(typeof i==="string"){i=map(i);}
else if(i&&i.url){i=new Request(map(i.url),i);}
}catch(e){}
return f.call(this,i,o);
};}
var xo=XMLHttpRequest.prototype.open;
XMLHttpRequest.prototype.open=function(m,u){var a=[].slice.call(arguments);if(a.length>1){a[1]=map(a[1]);}return xo.apply(this,a);};
if(navigator.sendBeacon){var sb=navigator.sendBeacon;navigator.sendBeacon=function(u,d){try{u=map(u);}catch(e){}return sb.call(navigator,u,d);};}
document.addEventListener("click",function(e){
var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;
if(!a){return;}
try{
var raw=a.getAttribute("href");
if(!raw||raw[0]==="#"||raw.indexOf("javascript:")===0){return;}
var u=new URL(raw,location.href);
if(u.origin===location.origin&&u.pathname.indexOf(base)!==0){a.href=base+u.pathname+u.search+u.hash;}
}catch(err){}
},true);
})();
JS;
}
