<?php
declare(strict_types=1);

function bdris_warm_pages(): array
{
    return [
        '/site/',
        '/site/login',
        '/site/user/create',
        '/site/user/forgot-password',
        '/site/br/application',
        '/site/br/application/status',
        '/site/br/correction',
        '/site/br/reprint/add',
        '/site/dr/application',
    ];
}

function bdris_warm_ua(): string
{
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
}

function bdris_warm_fetch_page(string $url, string $ua, string $accept): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'accept: ' . $accept,
            'accept-language: en-US,en;q=0.9,bn;q=0.8',
            'referer: ' . $url,
            'upgrade-insecure-requests: 1',
        ],
        CURLOPT_USERAGENT => $ua,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return null;
    }
    return $body;
}

function bdris_warm_extract_refs(string $text): array
{
    $refs = [];
    if (preg_match_all('~\b(?:href|src|action|poster|data-src|formaction)\s*=\s*(["\'])(.*?)\1~is', $text, $m)) {
        foreach ($m[2] as $value) {
            $refs[] = trim($value);
        }
    }
    if (preg_match_all('~\bsrcset\s*=\s*(["\'])(.*?)\1~is', $text, $m)) {
        foreach ($m[2] as $value) {
            foreach (explode(',', $value) as $part) {
                $refs[] = trim(explode(' ', trim($part))[0]);
            }
        }
    }
    if (preg_match_all('~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i', $text, $m)) {
        foreach ($m[2] as $value) {
            $refs[] = trim($value);
        }
    }
    return $refs;
}

function bdris_warm_clean_path(string $url): string
{
    $query = '';
    $pos = strpos($url, '?');
    if ($pos !== false) {
        $query = substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    $out = [];
    foreach (explode('/', $url) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return '/' . implode('/', $out) . $query;
}

function bdris_warm_normalize_ref(string $ref, string $baseUrl): ?string
{
    if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, 'data:')) {
        return null;
    }
    if (preg_match('~^https?://~i', $ref)) {
        if (!preg_match('~^https?://(?:www\.)?127\.0\.0\.1~i', $ref) && !str_contains($ref, 'localhost')) {
            return null;
        }
    }
    if (preg_match('~^(?:mailto|javascript|tel):~i', $ref)) {
        return null;
    }
    if (str_starts_with($ref, '/site')) {
        $url = $ref;
    } elseif (str_starts_with($ref, '//')) {
        return null;
    } elseif (str_starts_with($ref, '/')) {
        $url = '/site' . $ref;
    } else {
        $path = parse_url($baseUrl, PHP_URL_PATH) ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        $url = $dir . $ref;
    }
    $url = preg_replace('~#.*$~', '', $url);
    if (!str_starts_with($url, '/site/')) {
        return null;
    }
    return bdris_warm_clean_path($url);
}

function bdris_warm_is_asset(string $url): bool
{
    $path = parse_url($url, PHP_URL_PATH) ?? $url;
    return (bool) preg_match('~\.(css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|otf|map|bmp|avif)(\?.*)?$~i', $path);
}

function bdris_warm_batch(string $base, array $paths, string $ua, string $accept): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($paths as $path) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'accept: ' . $accept,
                'accept-language: en-US,en;q=0.9,bn;q=0.8',
                'referer: ' . $base . '/site/',
            ],
            CURLOPT_USERAGENT => $ua,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$path] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $path => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($body !== false && $code === 200) {
            $out[$path] = ['body' => $body, 'type' => $type];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function bdris_warm(string $base, callable $log): array
{
    $base = rtrim($base, '/');
    $ua = bdris_warm_ua();
    $acceptHtml = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
    $started = microtime(true);
    $queue = [];
    $seen = [];
    $pagesDone = 0;

    $mark = function (string $line) use ($log, $started): void {
        $log(sprintf('[%.1fs] %s', microtime(true) - $started, $line));
    };

    $mark('looking for pages');
    foreach (bdris_warm_pages() as $page) {
        $mark('page ' . $page);
        $html = bdris_warm_fetch_page($base . $page, $ua, $acceptHtml);
        if ($html === null) {
            $mark('failed ' . $page);
            continue;
        }
        $pagesDone++;
        $found = 0;
        foreach (bdris_warm_extract_refs($html) as $ref) {
            $url = bdris_warm_normalize_ref($ref, $base . $page);
            if ($url === null || !bdris_warm_is_asset($url) || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $queue[] = $url;
            $found++;
        }
        $mark('page ok ' . $page . ' (' . $found . ' new refs)');
    }

    $total = count($queue);
    $mark('queued ' . $total . ' assets');
    $done = 0;
    $cssBodies = [];

    while ($queue) {
        $batch = [];
        while ($queue && count($batch) < 6) {
            $batch[] = array_shift($queue);
        }
        $results = bdris_warm_batch($base, $batch, $ua, 'text/css,text/javascript,image/*,*/*;q=0.8');
        foreach ($results as $path => $data) {
            $done++;
            if (stripos($data['type'], 'text/css') !== false || str_ends_with((string) strtok($path, '?'), '.css')) {
                $cssBodies[$path] = $data['body'];
            }
        }
        $mark('assets ' . $done . '/' . $total);
    }

    $cssAssets = 0;
    foreach ($cssBodies as $cssPath => $css) {
        foreach (bdris_warm_extract_refs($css) as $ref) {
            $url = bdris_warm_normalize_ref($ref, $base . $cssPath);
            if ($url === null || !bdris_warm_is_asset($url) || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $queue[] = $url;
            $cssAssets++;
        }
    }
    if ($cssAssets > 0) {
        $mark('found ' . $cssAssets . ' assets inside css');
    }

    while ($queue) {
        $batch = [];
        while ($queue && count($batch) < 6) {
            $batch[] = array_shift($queue);
        }
        $results = bdris_warm_batch($base, $batch, $ua, 'text/css,text/javascript,image/*,*/*;q=0.8');
        $done += count($results);
        $mark('assets ' . $done . '/' . $total);
    }

    $elapsed = microtime(true) - $started;
    $summary = sprintf('done: %d pages, %d assets in %.1fs', $pagesDone, $done, $elapsed);
    $mark($summary);
    return ['pages' => $pagesDone, 'assets' => $done, 'seconds' => $elapsed];
}

function bdris_warm_stream(): void
{
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');

    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8099';
    $base = 'http://' . $host;
    echo 'warm start ' . $base . "\n";
    flush();

    bdris_warm($base, function (string $line): void {
        echo $line . "\n";
        flush();
    });
}
