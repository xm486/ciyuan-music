<?php
// Ciyuan Music account and sync API.
// MySQL backend for InfinityFree: register/login/logout/me/sync/update_profile/captcha/request_email_code/request_reset_code/reset_password.

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_json($data, $http = 200) {
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error($message, $http = 400, $code = -1) {
    api_json(['code' => $code, 'message' => $message], $http);
}

function client_ip() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return preg_replace('/[^0-9a-fA-F:\.]/', '', (string)$ip) ?: 'unknown';
}

function session_rate_limit($key, $limit, $windowSeconds, $message) {
    $now = time();
    $bucketKey = 'ciyuan_rate_' . $key;
    $bucket = $_SESSION[$bucketKey] ?? ['start' => $now, 'count' => 0];
    if (!is_array($bucket) || $now - (int)($bucket['start'] ?? 0) >= $windowSeconds) {
        $bucket = ['start' => $now, 'count' => 0];
    }
    $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;
    $_SESSION[$bucketKey] = $bucket;
    if ($bucket['count'] > $limit) {
        api_error($message, 429, -429);
    }
}

function app_config() {
    $file = __DIR__ . '/db_config.php';
    if (!file_exists($file)) api_error('缺少 db_config.php，请先配置数据库', 500);
    $config = require $file;
    if (!is_array($config) || empty($config['db'])) api_error('数据库配置格式错误', 500);
    return $config;
}

function github_config() {
    $config = app_config();
    $github = $config['github'] ?? [];
    return is_array($github) ? $github : [];
}

function github_enabled() {
    $github = github_config();
    return !empty($github['client_id']) && !empty($github['client_secret']) && !empty($github['redirect_uri']);
}

function github_redirect_uri() {
    $github = github_config();
    return trim((string)($github['redirect_uri'] ?? ''));
}

function github_auth_url($state) {
    $github = github_config();
    $clientId = trim((string)($github['client_id'] ?? ''));
    $redirectUri = github_redirect_uri();
    if (!$clientId || !$redirectUri) api_error('GitHub 登录未配置', 500);
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'read:user user:email',
        'state' => $state,
        'allow_signup' => 'true',
    ]);
    return 'https://github.com/login/oauth/authorize?' . $params;
}

function github_exchange_token($code) {
    $github = github_config();
    $clientId = trim((string)($github['client_id'] ?? ''));
    $clientSecret = trim((string)($github['client_secret'] ?? ''));
    $redirectUri = github_redirect_uri();
    if (!$clientId || !$clientSecret || !$redirectUri) api_error('GitHub 登录未配置', 500);
    $payload = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ]);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: CiyuanMusic/1.0\r\n",
            'content' => $payload,
            'timeout' => 20,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents('https://github.com/login/oauth/access_token', false, $context);
    if ($resp === false) api_error('GitHub Token 获取失败', 500);
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['access_token'])) {
        $message = is_array($data) ? ($data['error_description'] ?? ($data['error'] ?? 'GitHub Token 获取失败')) : 'GitHub Token 获取失败';
        api_error($message, 400);
    }
    return $data['access_token'];
}

function github_api_request($path, $token) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\nUser-Agent: CiyuanMusic/1.0\r\n",
            'timeout' => 20,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents('https://api.github.com' . $path, false, $context);
    if ($resp === false) api_error('GitHub 用户信息获取失败', 500);
    $data = json_decode($resp, true);
    if (!is_array($data)) api_error('GitHub 用户信息解析失败', 500);
    return $data;
}

function github_fetch_user($token) {
    $user = github_api_request('/user', $token);
    $emails = github_api_request('/user/emails', $token);
    $email = '';
    if (is_array($emails)) {
        foreach ($emails as $item) {
            if (!empty($item['primary']) && !empty($item['verified']) && !empty($item['email'])) {
                $email = (string)$item['email'];
                break;
            }
        }
        if (!$email) {
            foreach ($emails as $item) {
                if (!empty($item['email'])) { $email = (string)$item['email']; break; }
            }
        }
    }
    return [
        'id' => (string)($user['id'] ?? ''),
        'login' => (string)($user['login'] ?? ''),
        'avatar_url' => (string)($user['avatar_url'] ?? ''),
        'name' => trim((string)($user['name'] ?? '')),
        'email' => $email,
    ];
}

function ensure_github_columns() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $stmt = db()->query('SHOW COLUMNS FROM users');
        $cols = array_map(function($row) { return $row['Field']; }, $stmt->fetchAll());
        if (!in_array('github_id', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN github_id VARCHAR(64) NULL AFTER avatar_url');
        }
        if (!in_array('github_login', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN github_login VARCHAR(100) NULL AFTER github_id');
        }
        if (!in_array('github_avatar', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN github_avatar VARCHAR(255) NULL AFTER github_login');
        }
        if (!in_array('github_bound_at', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN github_bound_at INT UNSIGNED NULL AFTER github_avatar');
        }
        try {
            db()->exec('CREATE UNIQUE INDEX uniq_github_id ON users (github_id)');
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // 老库或权限受限时不阻断主流程
    }
}

function db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = app_config()['db'];
    $host = $db['host'] ?? '';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    if (!$host || !$name || !$user) api_error('数据库配置未填写完整', 500);
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        api_error('数据库连接失败：' . $e->getMessage(), 500);
    }
}

function get_input() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) return $json;
    return $_POST ?: $_GET;
}

function normalize_email($email) {
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        api_error('请输入正确的邮箱地址');
    }
    return $email;
}

function text_len($text) {
    $text = (string)$text;
    if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
    return strlen($text);
}

function make_captcha_code($length = 5) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function build_captcha_svg($code) {
    $safe = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $noise = '';
    for ($i = 0; $i < 8; $i++) {
        $x1 = random_int(0, 130); $y1 = random_int(0, 44);
        $x2 = random_int(0, 130); $y2 = random_int(0, 44);
        $color = sprintf('#%02x%02x%02x', random_int(80,180), random_int(80,200), random_int(150,255));
        $noise .= "<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='$color' stroke-width='1' opacity='0.35'/>";
    }
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='130' height='44' viewBox='0 0 130 44'>".
        "<defs><linearGradient id='g' x1='0' x2='1'><stop stop-color='#1a0a2e'/><stop offset='1' stop-color='#0d1b2a'/></linearGradient></defs>".
        "<rect width='130' height='44' rx='14' fill='url(#g)'/>".
        $noise.
        "<text x='65' y='29' text-anchor='middle' font-size='23' font-family='Verdana,Arial,sans-serif' font-weight='800' letter-spacing='5' fill='#00f5d4'>$safe</text>".
        "</svg>";
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function code_len($code) {
    $code = trim((string)$code);
    if (function_exists('mb_strlen')) return mb_strlen($code, 'UTF-8');
    return strlen($code);
}

function current_user_id() {
    return isset($_SESSION['ciyuan_user_id']) ? (int)$_SESSION['ciyuan_user_id'] : 0;
}

function level_info($listenSeconds, $todayListenSeconds = 0) {
    $expTotal = max(0, intdiv((int)$listenSeconds, 60));
    $todayExp = min(300, max(0, intdiv((int)$todayListenSeconds, 60)));
    $levels = [
        ['level' => 1, 'exp' => 0, 'name' => '初听者'],
        ['level' => 2, 'exp' => 100, 'name' => '乐迷'],
        ['level' => 3, 'exp' => 300, 'name' => '音律学徒'],
        ['level' => 4, 'exp' => 800, 'name' => '旋律旅人'],
        ['level' => 5, 'exp' => 1800, 'name' => '资深听众'],
        ['level' => 6, 'exp' => 4000, 'name' => '次元乐神'],
    ];
    $current = $levels[0];
    foreach ($levels as $item) {
        if ($expTotal >= $item['exp']) $current = $item;
    }
    $next = null;
    foreach ($levels as $item) {
        if ($item['exp'] > $expTotal) { $next = $item; break; }
    }
    $span = $next ? max(1, $next['exp'] - $current['exp']) : 1;
    $gained = max(0, $expTotal - $current['exp']);
    $percent = $next ? max(0, min(100, round($gained / $span * 100, 1))) : 100;
    return [
        'level' => $current['level'],
        'levelName' => $current['name'],
        'expTotal' => $expTotal,
        'expCurrent' => $next ? $gained : $expTotal,
        'expNext' => $next ? $span : $expTotal,
        'nextLevel' => $next ? $next['level'] : null,
        'nextTotalExp' => $next ? $next['exp'] : $current['exp'],
        'needExp' => $next ? max(0, $next['exp'] - $expTotal) : 0,
        'expPercent' => $percent,
        'todayExp' => $todayExp,
    ];
}

function public_user($user) {
    if (!$user) return null;
    return [
        'id' => (string)$user['id'],
        'email' => $user['email'] ?? '',
        'username' => $user['email'] ?? '',
        'displayName' => $user['display_name'] ?: ($user['email'] ?? '次元用户'),
        'avatarUrl' => $user['avatar_url'] ?: '',
        'github' => [
            'bound' => !empty($user['github_id']),
            'id' => $user['github_id'] ?? '',
            'login' => $user['github_login'] ?? '',
            'avatarUrl' => $user['github_avatar'] ?? '',
            'boundAt' => (int)($user['github_bound_at'] ?? 0),
        ],
        'createdAt' => (int)($user['created_at'] ?? 0),
        'updatedAt' => (int)($user['updated_at'] ?? 0),
    ];
}

function find_user_by_id($id) {
    if (!$id) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function find_user_by_email($email) {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([normalize_email($email)]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function ensure_level_columns() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $stmt = db()->query('SHOW COLUMNS FROM user_sync');
        $cols = array_map(function($row) { return $row['Field']; }, $stmt->fetchAll());
        if (!in_array('today_listen_seconds', $cols, true)) {
            db()->exec('ALTER TABLE user_sync ADD COLUMN today_listen_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER listen_seconds');
        }
        if (!in_array('listen_exp_date', $cols, true)) {
            db()->exec('ALTER TABLE user_sync ADD COLUMN listen_exp_date DATE NULL AFTER today_listen_seconds');
        }
    } catch (Throwable $e) {
        // InfinityFree/MySQL 权限异常时不阻断注册登录；db_check.php 会提示字段缺失。
    }
}
function ensure_announcement_tables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(160) NOT NULL,
            content TEXT NOT NULL,
            version VARCHAR(32) NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_announcements_active_created (is_active, created_at),
            KEY idx_announcements_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // 仅公告功能受影响，不阻断主站。
    }
}

function ensure_feedback_tables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            content TEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            admin_reply TEXT NULL,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_feedback_user (user_id),
            KEY idx_feedback_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS friend_links (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            url VARCHAR(255) NOT NULL,
            description VARCHAR(255) NULL,
            icon_url VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_friend_links_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // 正式建表请使用 database.sql；这里尽力自动补齐，不阻断其它功能。
    }
}

function ensure_visit_tables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS site_visits (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_key VARCHAR(80) NOT NULL,
            visit_path VARCHAR(255) NOT NULL DEFAULT '/',
            visit_date DATE NOT NULL,
            visited_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_site_visits_date (visit_date),
            KEY idx_site_visits_visitor_date (visitor_key, visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // 访问统计不影响主流程。
    }
}


function get_user_sync($userId) {
    ensure_level_columns();
    $stmt = db()->prepare('SELECT * FROM user_sync WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['playlists' => new stdClass(), 'playHistory' => [], 'listenSeconds' => 0, 'todayListenSeconds' => 0, 'listenExpDate' => date('Y-m-d'), 'levelInfo' => level_info(0, 0), 'updatedAt' => 0];
    }
    $playlists = json_decode($row['playlists_json'] ?: '{}', true);
    $history = json_decode($row['play_history_json'] ?: '[]', true);
    $listenSeconds = (int)($row['listen_seconds'] ?? 0);
    $todayDate = date('Y-m-d');
    $listenExpDate = (string)($row['listen_exp_date'] ?? '');
    $todayListenSeconds = $listenExpDate === $todayDate ? (int)($row['today_listen_seconds'] ?? 0) : 0;
    return [
        'playlists' => is_array($playlists) ? $playlists : new stdClass(),
        'playHistory' => is_array($history) ? $history : [],
        'listenSeconds' => $listenSeconds,
        'todayListenSeconds' => $todayListenSeconds,
        'listenExpDate' => $todayDate,
        'levelInfo' => level_info($listenSeconds, $todayListenSeconds),
        'updatedAt' => (int)($row['updated_at'] ?? 0),
    ];
}

function smtp_config() {
    $config = app_config();
    return is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
}

function send_mail_smtp($toEmail, $subject, $body) {
    $smtp = smtp_config();
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 465);
    $username = $smtp['username'] ?? '';
    $password = $smtp['pass'] ?? '';
    $fromEmail = $smtp['from_email'] ?? $username;
    $fromName = $smtp['from_name'] ?? 'Ciyuan Music';
    if (!$host || !$username || !$password || !$fromEmail) api_error('未配置 QQ 邮箱 SMTP', 500);

    $remote = $host;
    if (strpos($remote, 'ssl://') !== 0 && $port === 465) $remote = 'ssl://' . $remote;
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) api_error('SMTP 连接失败：' . $errstr, 500);
    stream_set_timeout($fp, 20);

    $read = function() use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $data;
    };
    $send = function($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function($prefix) use ($read) {
        $resp = $read();
        if (strpos($resp, $prefix) !== 0) {
            throw new Exception('SMTP 响应异常：' . trim($resp));
        }
        return $resp;
    };

    $expect('220');
    $send('EHLO ciyuanmusic');
    $expect('250');
    $send('AUTH LOGIN');
    $expect('334');
    $send(base64_encode($username));
    $expect('334');
    $send(base64_encode($password));
    $expect('235');
    $send('MAIL FROM:<' . $fromEmail . '>');
    $expect('250');
    $send('RCPT TO:<' . $toEmail . '>');
    $expect('250');
    $send('DATA');
    $expect('354');
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $send($message);
    $expect('250');
    $send('QUIT');
    fclose($fp);
    return true;
}

function generate_email_code($email, $purpose) {
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $now = time();
    $expiresAt = $now + 10 * 60;
    db()->prepare('DELETE FROM email_codes WHERE email = ? AND purpose = ? AND used_at IS NULL')->execute([$email, $purpose]);
    db()->prepare('INSERT INTO email_codes (email, purpose, code_hash, expires_at, created_at, used_at, send_count) VALUES (?, ?, ?, ?, ?, NULL, 1)')
        ->execute([$email, $purpose, $hash, $expiresAt, $now]);
    return $code;
}

function verify_email_code($email, $purpose, $code) {
    $stmt = db()->prepare('SELECT * FROM email_codes WHERE email = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();
    if (!$row) api_error('请先获取邮箱验证码');
    if ((int)$row['expires_at'] < time()) api_error('邮箱验证码已过期，请重新发送');
    if (!password_verify(trim((string)$code), $row['code_hash'] ?? '')) api_error('邮箱验证码错误');
    db()->prepare('UPDATE email_codes SET used_at = ? WHERE id = ?')->execute([time(), (int)$row['id']]);
}

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$input = get_input();
if (!$action && isset($input['action'])) $action = strtolower(trim((string)$input['action']));

if ($action === 'captcha') {
    session_rate_limit('captcha_' . client_ip(), 20, 300, '验证码刷新过于频繁，请稍后再试');
    $code = make_captcha_code(5);
    $_SESSION['ciyuan_captcha_code'] = $code;
    $_SESSION['ciyuan_captcha_time'] = time();
    api_json(['code' => 0, 'message' => 'captcha', 'captcha' => build_captcha_svg($code)]);
}

if ($action === 'request_email_code' || $action === 'request_reset_code') {
    $email = normalize_email($input['email'] ?? '');
    $purpose = $action === 'request_reset_code' ? 'reset_password' : 'register';
    $rateKey = $purpose . '_' . sha1($email . '|' . client_ip());
    session_rate_limit('email_code_' . $rateKey, 3, 600, '验证码发送过于频繁，请 10 分钟后再试');
    $lastKey = 'ciyuan_last_email_code_' . $rateKey;
    $nowForRate = time();
    if (!empty($_SESSION[$lastKey]) && $nowForRate - (int)$_SESSION[$lastKey] < 60) {
        api_error('请等待 60 秒后再发送验证码', 429, -429);
    }
    $_SESSION[$lastKey] = $nowForRate;
    $user = find_user_by_email($email);
    if ($purpose === 'register' && $user) api_error('该邮箱已注册', 409);
    if ($purpose === 'reset_password' && !$user) api_error('该邮箱未注册', 404);
    $code = generate_email_code($email, $purpose);
    $subject = $purpose === 'reset_password' ? '次元音乐重置密码验证码' : '次元音乐注册验证码';
    $body = '<div style="font-family:Arial,sans-serif;line-height:1.8;color:#222">'
        . '<h2 style="margin:0 0 12px;">次元音乐邮箱验证码</h2>'
        . '<p>你的验证码是：</p>'
        . '<div style="font-size:28px;font-weight:700;letter-spacing:6px;color:#005fdb;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p>验证码 10 分钟内有效，请勿泄露。</p>'
        . '</div>';
    send_mail_smtp($email, $subject, $body);
    api_json(['code' => 0, 'message' => '验证码已发送']);
}

if ($action === 'register') {
    $email = normalize_email($input['email'] ?? ($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $displayName = trim((string)($input['displayName'] ?? ''));
    $emailCode = trim((string)($input['emailCode'] ?? ''));
    $captcha = strtoupper(trim((string)($input['captcha'] ?? '')));
    $captchaCode = strtoupper((string)($_SESSION['ciyuan_captcha_code'] ?? ''));
    $captchaTime = (int)($_SESSION['ciyuan_captcha_time'] ?? 0);
    if ($captchaCode === '' || time() - $captchaTime > 300) api_error('验证码已过期，请刷新验证码');
    if ($captcha === '' || !hash_equals($captchaCode, $captcha)) api_error('图片验证码错误');
    unset($_SESSION['ciyuan_captcha_code'], $_SESSION['ciyuan_captcha_time']);
    if (text_len($password) < 6) api_error('密码至少需要 6 位');
    if ($displayName === '') $displayName = preg_replace('/@.*/', '', $email);
    if (text_len($displayName) > 24) api_error('昵称长度需为 1-24 位');
    verify_email_code($email, 'register', $emailCode);

    ensure_level_columns();
    ensure_github_columns();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) api_error('该邮箱已注册', 409);

    $now = time();
    $uid = bin2hex(random_bytes(12));
    $stmt = $pdo->prepare('INSERT INTO users (user_uid, email, password_hash, display_name, avatar_url, github_id, github_login, github_avatar, github_bound_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?)');
    $stmt->execute([$uid, $email, password_hash($password, PASSWORD_DEFAULT), $displayName, '', $now, $now]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_sync (user_id, playlists_json, play_history_json, listen_seconds, today_listen_seconds, listen_exp_date, updated_at) VALUES (?, ?, ?, 0, 0, ?, ?)')
        ->execute([$userId, '{}', '[]', date('Y-m-d'), $now]);
    $_SESSION['ciyuan_user_id'] = $userId;
    api_json(['code' => 0, 'message' => 'registered', 'user' => public_user(find_user_by_id($userId)), 'sync' => ['playlists' => new stdClass(), 'playHistory' => [], 'listenSeconds' => 0, 'todayListenSeconds' => 0, 'listenExpDate' => date('Y-m-d'), 'levelInfo' => level_info(0, 0)]]);
}

if ($action === 'login') {
    $email = normalize_email($input['email'] ?? ($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $loginKey = 'login_' . sha1($email . '|' . client_ip());
    session_rate_limit($loginKey, 8, 600, '登录尝试过于频繁，请 10 分钟后再试');
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) api_error('该邮箱未注册', 404);
    if (!password_verify($password, $user['password_hash'] ?? '')) api_error('密码错误', 401);
    unset($_SESSION['ciyuan_rate_' . $loginKey]);
    ensure_github_columns();
    $_SESSION['ciyuan_user_id'] = (int)$user['id'];
    api_json(['code' => 0, 'message' => 'logged in', 'user' => public_user($user), 'sync' => get_user_sync((int)$user['id'])]);
}

if ($action === 'github_login' || $action === 'github_bind') {
    if (!github_enabled()) api_error('GitHub 登录未配置', 500);
    if ($action === 'github_bind') {
        $userId = current_user_id();
        if (!$userId) api_error('请先登录后再绑定 GitHub', 401);
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['ciyuan_github_state'] = $state;
    $_SESSION['ciyuan_github_mode'] = $action === 'github_bind' ? 'bind' : 'login';
    $_SESSION['ciyuan_github_user_id'] = $action === 'github_bind' ? current_user_id() : 0;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $url = github_auth_url($state);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>跳转中...</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;line-height:1.8;">正在跳转到 GitHub 授权页面…<script>location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');</script><noscript><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">点击继续</a></noscript></body></html>';
    exit;
}

if ($action === 'github_callback') {
    if (!github_enabled()) api_error('GitHub 登录未配置', 500);
    $state = trim((string)($_GET['state'] ?? $input['state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? $input['code'] ?? ''));
    if (!$state || !$code || empty($_SESSION['ciyuan_github_state']) || !hash_equals((string)$_SESSION['ciyuan_github_state'], $state)) {
        api_error('GitHub 授权状态校验失败', 400);
    }
    unset($_SESSION['ciyuan_github_state']);
    $mode = (string)($_SESSION['ciyuan_github_mode'] ?? 'login');
    $bindUserId = (int)($_SESSION['ciyuan_github_user_id'] ?? 0);
    unset($_SESSION['ciyuan_github_mode'], $_SESSION['ciyuan_github_user_id']);

    $token = github_exchange_token($code);
    $gh = github_fetch_user($token);
    if (empty($gh['id'])) api_error('GitHub 账号信息获取失败', 500);
    ensure_github_columns();

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE github_id = ? LIMIT 1');
    $stmt->execute([$gh['id']]);
    $boundUser = $stmt->fetch();

    if ($mode === 'bind') {
        if (!$bindUserId) api_error('绑定会话已失效，请重新登录', 401);
        if ($boundUser && (int)$boundUser['id'] !== $bindUserId) api_error('该 GitHub 账号已绑定其他用户', 409);
        $now = time();
        $displayName = trim((string)($gh['name'] ?: $gh['login'] ?: '次元用户'));
        $displayName = $displayName !== '' ? $displayName : '次元用户';
        $pdo->prepare('UPDATE users SET github_id = ?, github_login = ?, github_avatar = ?, github_bound_at = ?, display_name = IF(display_name = "", ?, display_name), updated_at = ? WHERE id = ?')
            ->execute([$gh['id'], $gh['login'], $gh['avatar_url'], $now, $displayName, $now, $bindUserId]);
        $_SESSION['ciyuan_user_id'] = $bindUserId;
        $user = find_user_by_id($bindUserId);
        $target = './index.html?github=bind_success';
        header('Location: ' . $target, true, 302);
        echo '绑定成功，正在返回…';
        exit;
    }

    if ($boundUser) {
        $_SESSION['ciyuan_user_id'] = (int)$boundUser['id'];
        header('Location: ./index.html?github=login_success', true, 302);
        echo '登录成功，正在返回…';
        exit;
    }

    $now = time();
    $uid = bin2hex(random_bytes(12));
    $displayName = trim((string)($gh['name'] ?: $gh['login'] ?: 'GitHub用户'));
    if ($displayName === '') $displayName = 'GitHub用户';
    $email = '';
    if (!empty($gh['email'])) $email = normalize_email($gh['email']);
    $baseName = $displayName;
    if ($email === '') {
        $email = 'github_' . $gh['id'] . '@github.local';
    }

    // GitHub 账号未绑定，但 GitHub 邮箱已经注册过本站账号时，直接合并到该邮箱账号。
    // 否则 INSERT 会因为 users.email 唯一约束报 500，浏览器就会显示 HTTP_RESPONSE_CODE_FAILURE。
    $emailUser = find_user_by_email($email);
    if ($emailUser) {
        if (!empty($emailUser['github_id']) && (string)$emailUser['github_id'] !== (string)$gh['id']) {
            api_error('该邮箱账号已绑定其他 GitHub', 409);
        }
        $pdo->prepare('UPDATE users SET github_id = ?, github_login = ?, github_avatar = ?, github_bound_at = COALESCE(github_bound_at, ?), avatar_url = IF(avatar_url = "", ?, avatar_url), display_name = IF(display_name = "", ?, display_name), updated_at = ? WHERE id = ?')
            ->execute([$gh['id'], $gh['login'], $gh['avatar_url'], $now, $gh['avatar_url'], $displayName, $now, (int)$emailUser['id']]);
        $_SESSION['ciyuan_user_id'] = (int)$emailUser['id'];
        header('Location: ./index.html?github=login_success', true, 302);
        echo '登录成功，正在返回…';
        exit;
    }

    $pdo->prepare('INSERT INTO users (user_uid, email, password_hash, display_name, avatar_url, github_id, github_login, github_avatar, github_bound_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $email, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $displayName, $gh['avatar_url'], $gh['id'], $gh['login'], $gh['avatar_url'], $now, $now, $now]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_sync (user_id, playlists_json, play_history_json, listen_seconds, today_listen_seconds, listen_exp_date, updated_at) VALUES (?, ?, ?, 0, 0, ?, ?)')
        ->execute([$userId, '{}', '[]', date('Y-m-d'), $now]);
    $_SESSION['ciyuan_user_id'] = $userId;
    header('Location: ./index.html?github=login_success', true, 302);
    echo '登录成功，正在返回…';
    exit;
}

if ($action === 'github_unbind') {
    $userId = current_user_id();
    if (!$userId) api_error('请先登录', 401);
    ensure_github_columns();
    $user = find_user_by_id($userId);
    if (!$user) api_error('登录状态已失效', 401);
    if (!empty($user['github_id'])) {
        db()->prepare('UPDATE users SET github_id = NULL, github_login = NULL, github_avatar = NULL, github_bound_at = NULL, updated_at = ? WHERE id = ?')->execute([time(), $userId]);
    }
    api_json(['code' => 0, 'message' => 'github unbound', 'user' => public_user(find_user_by_id($userId))]);
}

if ($action === 'github_status') {
    $userId = current_user_id();
    if (!$userId) api_json(['code' => 0, 'bound' => false]);
    $user = find_user_by_id($userId);
    if (!$user) api_json(['code' => 0, 'bound' => false]);
    api_json(['code' => 0, 'bound' => !empty($user['github_id']), 'github' => $user ? [
        'id' => $user['github_id'] ?? '',
        'login' => $user['github_login'] ?? '',
        'avatarUrl' => $user['github_avatar'] ?? '',
        'boundAt' => (int)($user['github_bound_at'] ?? 0),
    ] : null]);
}

if ($action === 'reset_password') {
    $email = normalize_email($input['email'] ?? '');
    $emailCode = trim((string)($input['emailCode'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if (text_len($password) < 6) api_error('新密码至少 6 位');
    verify_email_code($email, 'reset_password', $emailCode);
    $user = find_user_by_email($email);
    if (!$user) api_error('该邮箱未注册', 404);
    db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE email = ?')->execute([password_hash($password, PASSWORD_DEFAULT), time(), $email]);
    $_SESSION['ciyuan_user_id'] = (int)$user['id'];
    api_json(['code' => 0, 'message' => 'password reset', 'user' => public_user(find_user_by_email($email)), 'sync' => get_user_sync((int)$user['id'])]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    api_json(['code' => 0, 'message' => 'logged out']);
}

$userId = current_user_id();
if ($action === 'me') {
    if (!$userId) api_json(['code' => 0, 'user' => null, 'sync' => null]);
    $user = find_user_by_id($userId);
    if (!$user) api_json(['code' => 0, 'user' => null, 'sync' => null]);
    api_json(['code' => 0, 'user' => public_user($user), 'sync' => get_user_sync($userId)]);
}

if ($action === 'friend_links') {
    ensure_feedback_tables();
    try {
        $rows = db()->query('SELECT id, name, url, description, icon_url, sort_order FROM friend_links WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
        api_json(['code' => 0, 'links' => $rows ?: []]);
    } catch (Throwable $e) {
        api_json(['code' => 0, 'links' => []]);
    }
}

if ($action === 'announcements') {
    ensure_announcement_tables();
    try {
        $rows = db()->query('SELECT id, title, content, version, source, created_at FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 20')->fetchAll();
        api_json(['code' => 0, 'announcements' => $rows ?: []]);
    } catch (Throwable $e) {
        api_json(['code' => 0, 'announcements' => []]);
    }
}

if ($action === 'record_update_announcement') {
    ensure_announcement_tables();
    $version = trim((string)($input['version'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    $notes = $input['notes'] ?? [];
    if ($version === '' || strlen($version) > 32) api_error('版本号无效');
    if ($title === '' || strlen($title) > 160) $title = '次元音乐 v' . $version . ' 更新说明';
    if (!is_array($notes)) $notes = [];
    $cleanNotes = [];
    foreach ($notes as $note) {
        $note = trim((string)$note);
        if ($note !== '') $cleanNotes[] = mb_substr($note, 0, 200, 'UTF-8');
        if (count($cleanNotes) >= 20) break;
    }
    if (!$cleanNotes) api_error('更新说明为空');
    $content = implode("\n", array_map(function($n) { return '• ' . $n; }, $cleanNotes));
    $now = time();
    try {
        $stmt = db()->prepare('SELECT id FROM announcements WHERE source = ? AND version = ? LIMIT 1');
        $stmt->execute(['update', $version]);
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            db()->prepare('UPDATE announcements SET title = ?, content = ?, is_active = 1, updated_at = ? WHERE id = ?')
                ->execute([$title, $content, $now, $existingId]);
        } else {
            db()->prepare('INSERT INTO announcements (title, content, version, source, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
                ->execute([$title, $content, $version, 'update', $now, $now]);
        }
    } catch (Throwable $e) {}
    api_json(['code' => 0, 'message' => 'recorded']);
}

if ($action === 'track_visit') {
    ensure_visit_tables();
    $visitorId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['visitorId'] ?? ''));
    if ($visitorId === '' || strlen($visitorId) > 64) $visitorId = bin2hex(random_bytes(12));
    $ipHash = substr(hash('sha256', client_ip()), 0, 16);
    $visitorKey = substr($visitorId . '_' . $ipHash, 0, 80);
    $path = trim((string)($input['path'] ?? '/'));
    if ($path === '') $path = '/';
    if (strlen($path) > 255) $path = substr($path, 0, 255);
    $today = date('Y-m-d');
    try {
        db()->prepare('INSERT INTO site_visits (visitor_key, visit_path, visit_date, visited_at) VALUES (?, ?, ?, ?)')
            ->execute([$visitorKey, $path, $today, time()]);
    } catch (Throwable $e) {}
    api_json(['code' => 0, 'message' => 'tracked']);
}

if (!$userId) api_error('请先登录', 401);
$user = find_user_by_id($userId);
if (!$user) api_error('登录状态已失效', 401);

if ($action === 'sync') {
    ensure_level_columns();
    $playlists = $input['playlists'] ?? new stdClass();
    $playHistory = $input['playHistory'] ?? [];
    $listenSeconds = $input['listenSeconds'] ?? 0;
    $todayListenSeconds = $input['todayListenSeconds'] ?? 0;
    $baseUpdatedAt = isset($input['baseUpdatedAt']) && is_numeric($input['baseUpdatedAt']) ? (int)$input['baseUpdatedAt'] : 0;
    $force = !empty($input['force']);
    $listenExpDate = trim((string)($input['listenExpDate'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listenExpDate)) $listenExpDate = date('Y-m-d');
    if ($listenExpDate !== date('Y-m-d')) $todayListenSeconds = 0;
    if (!is_array($playlists) && !is_object($playlists)) api_error('歌单数据格式错误');
    if (!is_array($playHistory)) $playHistory = [];
    $listenSeconds = is_numeric($listenSeconds) ? max(0, (int)$listenSeconds) : 0;
    $todayListenSeconds = is_numeric($todayListenSeconds) ? min(300 * 60, max(0, (int)$todayListenSeconds)) : 0;
    $oldSync = get_user_sync($userId);
    $serverUpdatedAt = (int)($oldSync['updatedAt'] ?? 0);
    if (!$force && $baseUpdatedAt > 0 && $serverUpdatedAt > 0 && $baseUpdatedAt < $serverUpdatedAt) {
        api_json([
            'code' => 409,
            'message' => 'sync conflict',
            'conflict' => [
                'serverUpdatedAt' => $serverUpdatedAt,
                'serverSync' => $oldSync,
                'clientBaseUpdatedAt' => $baseUpdatedAt
            ]
        ], 409);
    }
    $listenSeconds = max($listenSeconds, (int)($oldSync['listenSeconds'] ?? 0));
    if (($oldSync['listenExpDate'] ?? '') === date('Y-m-d')) {
        $todayListenSeconds = max($todayListenSeconds, (int)($oldSync['todayListenSeconds'] ?? 0));
    }
    $levelInfo = level_info($listenSeconds, $todayListenSeconds);
    $payload = [
        'playlists' => $playlists,
        'playHistory' => array_slice($playHistory, 0, 80),
        'listenSeconds' => $listenSeconds,
        'todayListenSeconds' => $todayListenSeconds,
        'listenExpDate' => date('Y-m-d'),
        'levelInfo' => $levelInfo,
        'updatedAt' => time(),
    ];
    $playlistsJson = json_encode($payload['playlists'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $historyJson = json_encode($payload['playHistory'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare('INSERT INTO user_sync (user_id, playlists_json, play_history_json, listen_seconds, today_listen_seconds, listen_exp_date, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE playlists_json = VALUES(playlists_json), play_history_json = VALUES(play_history_json), listen_seconds = VALUES(listen_seconds), today_listen_seconds = VALUES(today_listen_seconds), listen_exp_date = VALUES(listen_exp_date), updated_at = VALUES(updated_at)');
    $stmt->execute([$userId, $playlistsJson, $historyJson, $listenSeconds, $todayListenSeconds, $payload['listenExpDate'], $payload['updatedAt']]);
    api_json(['code' => 0, 'message' => 'synced', 'sync' => $payload, 'levelInfo' => $levelInfo]);
}

if ($action === 'submit_feedback') {
    ensure_feedback_tables();
    $title = trim((string)($input['title'] ?? ''));
    $content = trim((string)($input['content'] ?? ''));
    if ($title === '') api_error('请输入问题标题');
    if ($content === '') api_error('请输入问题详情');
    if (text_len($title) > 100) api_error('问题标题不能超过 100 字');
    if (text_len($content) > 2000) api_error('问题详情不能超过 2000 字');
    $now = time();
    db()->prepare('INSERT INTO feedback (user_id, title, content, status, admin_reply, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $title, $content, 'pending', null, $now, $now]);
    api_json(['code' => 0, 'message' => '反馈已提交，感谢你的帮助']);
}

if ($action === 'update_profile') {
    $displayName = trim((string)($input['displayName'] ?? ''));
    $hasAvatarUrl = array_key_exists('avatarUrl', $input);
    $avatarUrl = $hasAvatarUrl ? trim((string)$input['avatarUrl']) : null;
    if ($displayName !== '' && text_len($displayName) > 24) api_error('昵称长度需为 1-24 位');
    if ($hasAvatarUrl && $avatarUrl !== '' && strlen($avatarUrl) > 2000000) api_error('头像数据过大');
    $sets = ['updated_at = ?'];
    $params = [time()];
    if ($displayName !== '') { $sets[] = 'display_name = ?'; $params[] = $displayName; }
    if ($hasAvatarUrl) { $sets[] = 'avatar_url = ?'; $params[] = $avatarUrl; }
    $params[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
    db()->prepare($sql)->execute($params);
    api_json(['code' => 0, 'message' => 'profile updated', 'user' => public_user(find_user_by_id($userId))]);
}

api_error('unknown action', 404);