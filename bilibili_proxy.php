<?php
// Bilibili API proxy for PHP-only hosting.
// Usage:
//   bilibili_proxy.php?endpoint=search&keyword=xxx&page=1
//   bilibili_proxy.php?endpoint=pagelist&bvid=BV...
//   bilibili_proxy.php?endpoint=playurl&bvid=BV...&cid=...&fnval=16&fourk=1
//   bilibili_proxy.php?endpoint=view&bvid=BV...
//   bilibili_proxy.php?endpoint=websearch&keyword=xxx
//   bilibili_proxy.php?endpoint=image&url=https%3A%2F%2Fi0.hdslb.com%2F...
//   bilibili_proxy.php?endpoint=audio&url=https%3A%2F%2F...bilivideo.com%2F...m4s&bvid=BV...

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['code' => $code, 'message' => $message, 'ttl' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

function random_hex($length) {
    try {
        return strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
    } catch (Exception $e) {
        $chars = '0123456789ABCDEF';
        $out = '';
        for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, 15)];
        return $out;
    }
}

function fetch_url($url, $headers, &$httpCode = 502, &$error = '', $timeout = 20) {
    $body = false;
    $httpCode = 502;
    $error = '';
    $timeout = max(5, (int)$timeout);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) $error = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
        if ($body === false) $error = 'file_get_contents failed';
    }

    return $body;
}

function proxy_stream_media($url, $headers, $defaultContentType = 'application/octet-stream', $timeout = 180, $forceContentType = false) {
    @set_time_limit($timeout + 30);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_clean();

    if (!function_exists('curl_init')) {
        $status = 502;
        $error = '';
        $body = fetch_url($url, $headers, $status, $error, $timeout);
        if ($body === false || $body === '') json_error(-502, $error ?: 'media proxy failed', 502);
        http_response_code(($status >= 200 && $status < 300) ? $status : 502);
        header('Content-Type: ' . $defaultContentType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    $sentHeaders = false;
    $upstreamStatus = 200;
    $upstreamContentType = '';
    $allowedHeaders = ['content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_HEADERFUNCTION => function($ch, $headerLine) use (&$upstreamStatus, &$upstreamContentType, $allowedHeaders, $defaultContentType, $forceContentType) {
            $line = trim($headerLine);
            if ($line === '') return strlen($headerLine);

            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $m)) {
                $upstreamStatus = (int)$m[1];
                http_response_code(($upstreamStatus >= 200 && $upstreamStatus < 400) ? $upstreamStatus : 502);
                header('Access-Control-Allow-Origin: *');
                header('Accept-Ranges: bytes');
                header('Cache-Control: no-store');
                header('Content-Type: ' . $defaultContentType);
                return strlen($headerLine);
            }

            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                if ($name === 'content-type') {
                    $upstreamContentType = $value;
                    if (!$forceContentType) header('Content-Type: ' . $value, true);
                } elseif (in_array($name, $allowedHeaders, true)) {
                    header(substr($line, 0, $pos) . ': ' . $value, true);
                }
            }
            return strlen($headerLine);
        },
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$sentHeaders, &$upstreamContentType, $defaultContentType, $forceContentType) {
            if (!$sentHeaders) {
                if ($forceContentType || $upstreamContentType === '') header('Content-Type: ' . $defaultContentType);
                $sentHeaders = true;
            }
            echo $chunk;
            flush();
            return strlen($chunk);
        }
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false && !headers_sent()) {
        json_error(-502, $err ?: 'media proxy failed', 502);
    }
    exit;
}

function html_entity_decode_safe($value) {
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function extract_meta_content($html, $property) {
    $property = preg_quote($property, '/');
    if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $property . '["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        return html_entity_decode_safe($m[1]);
    }
    if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $property . '["\']/i', $html, $m)) {
        return html_entity_decode_safe($m[1]);
    }
    return '';
}

function json_decode_initial_state($html) {
    if (!preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{[\s\S]*?\})\s*;\s*\(function/i', $html, $m)) return null;
    $data = json_decode($m[1], true);
    return is_array($data) ? $data : null;
}

function build_view_response_from_html($html, $inputBvid = '', $inputAid = '') {
    $state = json_decode_initial_state($html);
    $video = null;
    if (is_array($state)) {
        if (isset($state['videoData']) && is_array($state['videoData'])) $video = $state['videoData'];
        elseif (isset($state['videoInfo']) && is_array($state['videoInfo'])) $video = $state['videoInfo'];
    }

    $title = '';
    $pic = '';
    $desc = '';
    $ownerName = '';
    $duration = 0;
    $bvid = $inputBvid;
    $aid = $inputAid;
    $tname = '';
    $view = 0;

    if (is_array($video)) {
        $title = isset($video['title']) ? $video['title'] : '';
        $pic = isset($video['pic']) ? $video['pic'] : '';
        $desc = isset($video['desc']) ? $video['desc'] : '';
        $duration = isset($video['duration']) ? (int)$video['duration'] : 0;
        $bvid = isset($video['bvid']) ? $video['bvid'] : $bvid;
        $aid = isset($video['aid']) ? (string)$video['aid'] : $aid;
        $tname = isset($video['tname']) ? $video['tname'] : '';
        if (isset($video['owner']) && is_array($video['owner']) && isset($video['owner']['name'])) $ownerName = $video['owner']['name'];
        if (isset($video['stat']) && is_array($video['stat']) && isset($video['stat']['view'])) $view = (int)$video['stat']['view'];
    }

    if ($title === '') $title = extract_meta_content($html, 'og:title');
    if ($pic === '') $pic = extract_meta_content($html, 'og:image');
    if ($desc === '') $desc = extract_meta_content($html, 'description') ?: extract_meta_content($html, 'og:description');

    $title = trim(preg_replace('/_哔哩哔哩_bilibili$/u', '', $title));
    if ($title === '') return null;
    if ($pic && strpos($pic, '//') === 0) $pic = 'https:' . $pic;

    return [
        'code' => 0,
        'message' => '0',
        'ttl' => 1,
        'data' => [
            'bvid' => $bvid,
            'aid' => $aid,
            'title' => $title,
            'tname' => $tname ?: 'Bilibili 视频',
            'duration' => $duration,
            'pic' => $pic,
            'desc' => $desc,
            'owner' => ['name' => $ownerName ?: 'Bilibili'],
            'stat' => ['view' => $view],
        ],
        'fallback' => 'html'
    ];
}

function fetch_bilibili_view_from_html($bvid = '', $aid = '') {
    $videoId = $bvid ? $bvid : ($aid ? ('av' . $aid) : '');
    if ($videoId === '') return null;
    $url = 'https://www.bilibili.com/video/' . rawurlencode($videoId);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Referer: https://www.bilibili.com/'
    ];
    $status = 502;
    $err = '';
    $html = fetch_url($url, $headers, $status, $err, 20);
    if ($html === false || $html === '') return null;
    return build_view_response_from_html($html, $bvid, $aid);
}

$endpoint = isset($_GET['endpoint']) ? strtolower(trim($_GET['endpoint'])) : '';
$params = $_GET;
unset($params['endpoint'], $params['callback'], $params['jsonp']);

$targetPath = '';
if ($endpoint === 'image' || $endpoint === 'audio') {
    if ($endpoint === 'audio') {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');
        @ini_set('memory_limit', '256M');
    }
    if (empty($params['url'])) json_error(-400, 'missing url');
    $mediaUrl = $params['url'];
    if (!preg_match('/^https?:\/\//i', $mediaUrl)) json_error(-400, 'invalid media url');
    $host = parse_url($mediaUrl, PHP_URL_HOST);
    $allowed = false;
    if ($host && preg_match('/(^|\.)hdslb\.com$/i', $host)) $allowed = true;
    if ($host && preg_match('/(^|\.)bilivideo\.com$/i', $host)) $allowed = true;
    if (!$allowed) json_error(-403, 'media host not allowed', 403);

    $bvidForMedia = isset($params['bvid']) ? $params['bvid'] : '';
    $mediaReferer = $bvidForMedia ? 'https://www.bilibili.com/video/' . rawurlencode($bvidForMedia) : 'https://www.bilibili.com/';
    $mediaHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Origin: https://www.bilibili.com',
        'Referer: ' . $mediaReferer,
        // B站 m4s/CDN 对 Range 更兼容；没有浏览器 Range 时默认请求完整起点分段。
        isset($_SERVER['HTTP_RANGE']) ? ('Range: ' . $_SERVER['HTTP_RANGE']) : 'Range: bytes=0-',
        'Cache-Control: no-cache',
        'Pragma: no-cache'
    ];

    if ($endpoint === 'image') {
        $httpCode = 502;
        $error = '';
        $body = fetch_url($mediaUrl, $mediaHeaders, $httpCode, $error, 20);
        if ($body === false || $body === '') json_error(-502, $error ?: 'media proxy failed', 502);

        $path = parse_url($mediaUrl, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'png') header('Content-Type: image/png');
        elseif ($ext === 'webp') header('Content-Type: image/webp');
        else header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    // 音频必须使用流式代理：让 <audio> 自己处理 Range/缓冲，并强制标记为 audio/mp4，避免 B站 video/mp4 被移动浏览器按视频后台策略处理。
    proxy_stream_media($mediaUrl, $mediaHeaders, 'audio/mp4', 180, true);
} elseif ($endpoint === 'websearch') {
    if (empty($params['keyword'])) json_error(-400, 'missing keyword');
    $keyword = $params['keyword'];
    $query = 'site:bilibili.com/video ' . $keyword;
    $searchUrl = 'https://www.baidu.com/s?wd=' . rawurlencode($query);
    $html = fetch_url($searchUrl, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Referer: https://www.baidu.com/'
    ], $status, $err);
    if ($html === false || $html === '') {
        echo json_encode(['code' => 0, 'message' => 'websearch unavailable', 'ttl' => 1, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    preg_match_all('/BV[0-9A-Za-z]{10}/i', $html, $matches);
    $bvids = [];
    foreach (($matches[0] ?? []) as $bv) {
        $bv = strtoupper(substr($bv, 0, 2)) . substr($bv, 2);
        if (!in_array($bv, $bvids, true)) $bvids[] = $bv;
        if (count($bvids) >= 10) break;
    }
    echo json_encode(['code' => 0, 'message' => 'OK', 'ttl' => 1, 'data' => $bvids], JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($endpoint === 'search') {
    $targetPath = '/x/web-interface/search/type';
    if (empty($params['search_type'])) $params['search_type'] = 'video';
    if (empty($params['keyword'])) json_error(-400, 'missing keyword');
    if (empty($params['page'])) $params['page'] = '1';
} elseif ($endpoint === 'view') {
    $targetPath = '/x/web-interface/view';
    if (empty($params['bvid']) && empty($params['aid'])) json_error(-400, 'missing bvid or aid');
} elseif ($endpoint === 'pagelist') {
    $targetPath = '/x/player/pagelist';
    if (empty($params['bvid'])) json_error(-400, 'missing bvid');
} elseif ($endpoint === 'playurl') {
    $targetPath = '/x/player/playurl';
    if (empty($params['bvid'])) json_error(-400, 'missing bvid');
    if (empty($params['cid'])) json_error(-400, 'missing cid');
    if (empty($params['fnval'])) $params['fnval'] = '16';
    if (empty($params['fourk'])) $params['fourk'] = '1';
} else {
    json_error(-404, 'unknown endpoint', 404);
}

$query = http_build_query($params);
$target = 'https://api.bilibili.com' . $targetPath . '?' . $query;

$bvid = isset($params['bvid']) ? $params['bvid'] : '';
$keyword = isset($params['keyword']) ? $params['keyword'] : '';
$referer = $bvid
    ? 'https://www.bilibili.com/video/' . rawurlencode($bvid)
    : ($keyword ? 'https://search.bilibili.com/all?keyword=' . rawurlencode($keyword) : 'https://www.bilibili.com/');

$buvid = random_hex(32);
$bnut = time();
$cookie = implode('; ', [
    'buvid3=' . $buvid,
    'buvid4=' . $buvid,
    'b_nut=' . $bnut,
    'CURRENT_FNVAL=4048',
    '_uuid=' . $buvid,
    'b_lsid=' . random_hex(16) . '_' . strtoupper(dechex((int)(microtime(true) * 1000)))
]);

$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    'Origin: https://www.bilibili.com',
    'Referer: ' . $referer,
    'Cookie: ' . $cookie,
    'Cache-Control: no-cache',
    'Pragma: no-cache'
];

$httpCode = 502;
$error = '';
$body = fetch_url($target, $headers, $httpCode, $error);

if ($endpoint === 'view') {
    $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    $officialOk = is_array($decoded) && isset($decoded['code']) && (int)$decoded['code'] === 0 && isset($decoded['data']) && is_array($decoded['data']);
    if (!$officialOk) {
        $htmlFallback = fetch_bilibili_view_from_html(isset($params['bvid']) ? $params['bvid'] : '', isset($params['aid']) ? $params['aid'] : '');
        if ($htmlFallback) {
            http_response_code(200);
            echo json_encode($htmlFallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

if ($body === false || $body === '') {
    json_error(-502, $error ?: 'Bilibili proxy failed', 502);
}

http_response_code($httpCode ?: 200);
echo $body;
