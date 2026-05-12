<?php
// 次元音乐部署健康检查工具
// 每次更新后访问 /db_check.php，用于检查数据库、SMTP、核心文件和新功能依赖是否正常。
// 注意：正式长期上线后建议删除或改名本文件，避免暴露环境信息。

header('Content-Type: text/html; charset=utf-8');

function h($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

$items = [];
function add_item($group, $name, $ok, $detail = '', $level = 'critical') {
    global $items;
    $items[] = [
        'group' => $group,
        'name' => $name,
        'ok' => (bool)$ok,
        'detail' => (string)$detail,
        'level' => $level,
    ];
}

function has_placeholder($value) {
    $value = (string)$value;
    return $value === ''
        || stripos($value, 'XXX') !== false
        || stripos($value, 'XXXX') !== false
        || stripos($value, 'YOUR_') !== false
        || strpos($value, '你的') !== false
        || strpos($value, '授权码') !== false
        || strpos($value, 'change_this') !== false;
}

function file_contains($file, $needle) {
    if (!is_file($file)) return false;
    $content = file_get_contents($file);
    return is_string($content) && strpos($content, $needle) !== false;
}

function php_syntax_check($file) {
    if (!is_file($file)) return [false, '文件不存在'];
    if (!function_exists('shell_exec')) return [null, '服务器禁用了 shell_exec，跳过语法检查'];
    $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
    $output = shell_exec($cmd);
    if (!is_string($output)) return [null, '无法执行 php -l，跳过'];
    return [strpos($output, 'No syntax errors detected') !== false, trim($output)];
}

$configFile = __DIR__ . '/db_config.php';
$authFile = __DIR__ . '/auth_api.php';
$adminFile = __DIR__ . '/admin.php';
$sqlFile = __DIR__ . '/database.sql';
$indexFile = __DIR__ . '/index.html';
$config = null;
$pdo = null;

add_item('基础环境', 'PHP 版本 >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
add_item('基础环境', 'PDO 扩展', class_exists('PDO'), class_exists('PDO') ? '已启用' : '未启用');
$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
add_item('基础环境', 'pdo_mysql 驱动', in_array('mysql', $drivers, true), $drivers ? implode(', ', $drivers) : '无可用 PDO 驱动');
add_item('基础环境', 'OpenSSL 扩展', extension_loaded('openssl'), extension_loaded('openssl') ? '已启用，SMTP SSL 可用' : '未启用，QQ邮箱 SSL SMTP 可能不可用');
add_item('基础环境', 'stream_socket_client', function_exists('stream_socket_client'), function_exists('stream_socket_client') ? '可用于连接 SMTP' : '函数不可用，SMTP 检测/发送可能失败');

add_item('配置文件', 'db_config.php', file_exists($configFile), file_exists($configFile) ? '已找到' : '未找到，请创建 db_config.php 并填写数据库 / SMTP 配置');

if (file_exists($configFile)) {
    try {
        $config = require $configFile;
        add_item('配置文件', '配置格式', is_array($config), is_array($config) ? 'return array 正常' : '配置文件没有返回数组');
        add_item('配置文件', 'db 节点', is_array($config['db'] ?? null), is_array($config['db'] ?? null) ? '存在' : '缺少 db 配置');
        add_item('配置文件', 'admin 节点', is_array($config['admin'] ?? null), is_array($config['admin'] ?? null) ? '存在' : '缺少 admin 配置');
        add_item('配置文件', 'smtp 节点', is_array($config['smtp'] ?? null), is_array($config['smtp'] ?? null) ? '存在' : '缺少 smtp 配置，邮箱验证码不可用');
        add_item('配置文件', 'github 节点', is_array($config['github'] ?? null), is_array($config['github'] ?? null) ? '存在' : '缺少 github 配置，GitHub 登录/绑定不可用', 'warning');
    } catch (Throwable $e) {
        add_item('配置文件', '读取配置', false, $e->getMessage());
    }
}

if (is_array($config) && is_array($config['db'] ?? null)) {
    $db = $config['db'];
    $host = $db['host'] ?? '';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    $dbPlaceholder = has_placeholder($host) || has_placeholder($name) || has_placeholder($user) || has_placeholder($pass);
    add_item('数据库', '数据库配置已填写', !$dbPlaceholder, $dbPlaceholder ? '仍像模板值，请填写 InfinityFree 数据库信息' : h($name . '@' . $host));
    if (!$dbPlaceholder) {
        try {
            $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            add_item('数据库', '数据库连接', true, $name . '@' . $host);
        } catch (Throwable $e) {
            add_item('数据库', '数据库连接', false, $e->getMessage());
        }
    }
}

$schema = [
    'users' => ['id', 'user_uid', 'email', 'password_hash', 'display_name', 'avatar_url', 'github_id', 'github_login', 'github_avatar', 'github_bound_at', 'created_at', 'updated_at'],
    'user_sync' => ['user_id', 'playlists_json', 'play_history_json', 'listen_seconds', 'today_listen_seconds', 'listen_exp_date', 'updated_at'],
    'email_codes' => ['id', 'email', 'purpose', 'code_hash', 'expires_at', 'created_at', 'used_at', 'send_count'],
];

if ($pdo) {
    foreach ($schema as $table => $columns) {
        try {
            $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            add_item('数据表', '表存在：' . $table, true, '可访问');
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '`');
            $stmt->execute();
            $actual = array_map(function($row) { return $row['Field']; }, $stmt->fetchAll());
            $missing = array_values(array_diff($columns, $actual));
            add_item('数据表', '字段完整：' . $table, empty($missing), empty($missing) ? '字段完整' : '缺少字段：' . implode(', ', $missing));
        } catch (Throwable $e) {
            add_item('数据表', '表存在：' . $table, false, '请导入最新 database.sql；' . $e->getMessage());
        }
    }

    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        add_item('功能数据', '用户数量查询', true, '当前注册用户：' . $count);
    } catch (Throwable $e) {
        add_item('功能数据', '用户数量查询', false, $e->getMessage(), 'warning');
    }

    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM email_codes WHERE used_at IS NULL AND expires_at > UNIX_TIMESTAMP()')->fetchColumn();
        add_item('功能数据', '未过期邮箱验证码查询', true, '当前有效验证码：' . $count);
    } catch (Throwable $e) {
        add_item('功能数据', '未过期邮箱验证码查询', false, $e->getMessage(), 'warning');
    }
}

if (is_array($config) && is_array($config['github'] ?? null)) {
    $github = $config['github'];
    $clientId = $github['client_id'] ?? '';
    $clientSecret = $github['client_secret'] ?? '';
    $redirectUri = $github['redirect_uri'] ?? '';
    $githubPlaceholder = has_placeholder($clientId) || has_placeholder($clientSecret) || has_placeholder($redirectUri);
    $callbackOk = is_string($redirectUri) && preg_match('#^https://.+/auth_api\.php\?action=github_callback$#', $redirectUri);
    add_item('GitHub OAuth', 'GitHub 配置已填写', !$githubPlaceholder, $githubPlaceholder ? '仍像模板值；请填写 Client ID / Client Secret / redirect_uri' : 'Client ID：' . substr((string)$clientId, 0, 8) . '***', 'warning');
    add_item('GitHub OAuth', '回调地址格式', (bool)$callbackOk, $callbackOk ? $redirectUri : '建议：https://你的域名/auth_api.php?action=github_callback', 'warning');
} else {
    add_item('GitHub OAuth', 'GitHub 配置已填写', false, '缺少 github 配置；不用 GitHub 登录可忽略', 'warning');
}

if ($pdo) {
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE github_id IS NOT NULL AND github_id <> ""')->fetchColumn();
        add_item('GitHub OAuth', 'GitHub 绑定数量查询', true, '当前已绑定 GitHub 用户：' . $count, 'warning');
    } catch (Throwable $e) {
        add_item('GitHub OAuth', 'GitHub 绑定数量查询', false, '通常是 users 表缺少 GitHub 字段：' . $e->getMessage(), 'warning');
    }
}

if (is_array($config) && is_array($config['smtp'] ?? null)) {
    $smtp = $config['smtp'];
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 0);
    $username = $smtp['username'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $fromEmail = $smtp['from_email'] ?? '';
    $smtpPlaceholder = has_placeholder($host) || has_placeholder($username) || has_placeholder($pass) || has_placeholder($fromEmail) || !$port;
    add_item('邮箱SMTP', 'SMTP 配置已填写', !$smtpPlaceholder, $smtpPlaceholder ? '仍像模板值；QQ邮箱 pass 要填 SMTP 授权码，不是QQ密码' : $username . ' / ' . $host . ':' . $port);
    if (!$smtpPlaceholder && function_exists('stream_socket_client')) {
        $remote = $host;
        if (strpos($remote, 'ssl://') !== 0 && $port === 465) $remote = 'ssl://' . $remote;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT);
        if ($fp) {
            fclose($fp);
            add_item('邮箱SMTP', 'SMTP 端口连接', true, '可以连接 ' . $remote . ':' . $port);
        } else {
            add_item('邮箱SMTP', 'SMTP 端口连接', false, '连接失败：' . $errstr . ' (' . $errno . ')');
        }
    }
}

$files = [
    'auth_api.php' => $authFile,
    'admin.php' => $adminFile,
    'database.sql' => $sqlFile,
    'index.html' => $indexFile,
];
foreach ($files as $name => $file) {
    add_item('核心文件', $name, is_file($file), is_file($file) ? '已找到，大小 ' . filesize($file) . ' bytes' : '缺失');
}

foreach (['auth_api.php' => $authFile, 'admin.php' => $adminFile, 'db_check.php' => __FILE__] as $name => $file) {
    list($ok, $detail) = php_syntax_check($file);
    if ($ok === null) add_item('语法检查', $name, true, $detail, 'warning');
    else add_item('语法检查', $name, $ok, $detail);
}

add_item('功能依赖', 'auth_api：邮箱验证码接口', file_contains($authFile, 'request_email_code') && file_contains($authFile, 'request_reset_code'), '检查 request_email_code / request_reset_code');
add_item('功能依赖', 'auth_api：重置密码接口', file_contains($authFile, 'reset_password') && file_contains($authFile, 'verify_email_code'), '检查 reset_password / verify_email_code');
add_item('功能依赖', 'database.sql：email_codes 表', file_contains($sqlFile, 'CREATE TABLE IF NOT EXISTS email_codes'), '检查邮箱验证码表结构');
add_item('功能依赖', 'index：忘记密码入口', file_contains($indexFile, 'forgotPasswordLink') && file_contains($indexFile, 'setAuthMode(\'forgot\')'), '检查登录弹窗底部浅色找回密码入口');
add_item('功能依赖', 'index：发送邮箱验证码逻辑', file_contains($indexFile, 'sendEmailCode()') && file_contains($indexFile, 'request_email_code'), '检查前端发送验证码逻辑');
add_item('功能依赖', 'index：重置密码逻辑', file_contains($indexFile, 'reset_password'), '检查前端重置密码逻辑');
add_item('功能依赖', 'auth_api：等级经验计算', file_contains($authFile, 'function level_info') && file_contains($authFile, 'today_listen_seconds'), '检查听歌经验 / Lv1-Lv6 数据逻辑');
add_item('功能依赖', 'database.sql：等级经验字段', file_contains($sqlFile, 'today_listen_seconds') && file_contains($sqlFile, 'listen_exp_date'), '检查 user_sync 等级经验字段');
add_item('功能依赖', 'index：等级卡展示', file_contains($indexFile, 'profileLevelCard') && file_contains($indexFile, 'renderLevelCard()'), '检查个人中心等级卡 UI');
add_item('功能依赖', 'auth_api：GitHub OAuth 接口', file_contains($authFile, 'github_login') && file_contains($authFile, 'github_bind') && file_contains($authFile, 'github_callback') && file_contains($authFile, 'github_unbind'), '检查 GitHub 登录 / 绑定 / 回调 / 解绑接口');
add_item('功能依赖', 'auth_api：GitHub state 校验', file_contains($authFile, 'ciyuan_github_state') && file_contains($authFile, 'hash_equals'), '检查 OAuth state 防 CSRF');
add_item('功能依赖', 'database.sql：GitHub 字段', file_contains($sqlFile, 'github_id') && file_contains($sqlFile, 'github_login') && file_contains($sqlFile, 'uniq_github_id'), '检查 users 表 GitHub 绑定字段和唯一索引');
add_item('功能依赖', 'index：GitHub 登录入口', file_contains($indexFile, 'githubLoginBtn') && file_contains($indexFile, 'loginWithGithub()'), '检查登录弹窗 GitHub 快捷登录按钮');
add_item('功能依赖', 'index：GitHub 绑定入口', file_contains($indexFile, 'githubBindBtn') && file_contains($indexFile, 'bindGithubAccount()') && file_contains($indexFile, 'unbindGithubAccount()'), '检查个人中心绑定/解绑 GitHub');

$criticalTotal = 0;
$criticalOk = 0;
$warningFailed = 0;
foreach ($items as $item) {
    if ($item['level'] === 'critical') {
        $criticalTotal++;
        if ($item['ok']) $criticalOk++;
    } elseif (!$item['ok']) {
        $warningFailed++;
    }
}
$allCriticalOk = $criticalTotal === $criticalOk;
$percent = $criticalTotal > 0 ? round($criticalOk / $criticalTotal * 100) : 100;

$groups = [];
foreach ($items as $item) {
    $groups[$item['group']][] = $item;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>次元音乐部署健康检查</title>
<style>
:root{color-scheme:dark;--bg:#070916;--card:rgba(255,255,255,.075);--line:rgba(255,255,255,.13);--text:#f8fbff;--sub:rgba(255,255,255,.62);--ok:#34c759;--bad:#ff3b30;--warn:#ffcc00;--a:#00f5d4;--blue:#007aff}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,"Microsoft YaHei",sans-serif;background:radial-gradient(circle at 12% 10%,rgba(0,245,212,.20),transparent 28%),radial-gradient(circle at 85% 0,rgba(0,122,255,.20),transparent 30%),var(--bg);color:var(--text)}.wrap{max-width:980px;margin:0 auto;padding:26px}.hero{display:flex;gap:18px;align-items:stretch;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px}.title{font-size:28px;font-weight:950;margin-bottom:8px}.sub{color:var(--sub);line-height:1.65}.score{min-width:190px;border:1px solid var(--line);border-radius:24px;background:var(--card);padding:16px;text-align:center}.num{font-size:38px;font-weight:950;color:var(--a)}.summary{margin-top:8px;color:var(--sub);font-size:13px}.alert{margin:14px 0 18px;padding:13px 15px;border-radius:18px;border:1px solid var(--line);background:rgba(255,255,255,.07);line-height:1.65}.alert.ok{border-color:rgba(52,199,89,.25);background:rgba(52,199,89,.10)}.alert.bad{border-color:rgba(255,59,48,.25);background:rgba(255,59,48,.10)}.group{margin:16px 0;border:1px solid var(--line);border-radius:22px;background:var(--card);overflow:hidden}.group-title{padding:14px 16px;font-weight:900;background:rgba(255,255,255,.06);border-bottom:1px solid var(--line)}.row{display:grid;grid-template-columns:210px 86px 1fr;gap:12px;padding:13px 16px;border-bottom:1px solid var(--line);align-items:center}.row:last-child{border-bottom:none}.badge{display:inline-flex;align-items:center;justify-content:center;height:28px;border-radius:999px;font-size:12px;font-weight:900}.pass{background:rgba(52,199,89,.16);color:#9dffb7}.fail{background:rgba(255,59,48,.16);color:#ffb4ad}.detail{color:var(--sub);font-size:13px;word-break:break-all;line-height:1.55}.tips{margin-top:18px;padding:16px;border-radius:20px;border:1px solid rgba(0,245,212,.18);background:rgba(0,245,212,.08);line-height:1.75;color:rgba(255,255,255,.82)}code{color:var(--a)}a{color:var(--a)}@media(max-width:700px){.row{grid-template-columns:1fr;gap:7px}.score{width:100%}.wrap{padding:18px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div>
      <div class="title">次元音乐部署健康检查</div>
      <div class="sub">每次更新后访问本页，检查数据库、邮箱验证码、GitHub OAuth、后台和核心文件是否正常。<br>当前检查时间：<?=date('Y-m-d H:i:s')?></div>
    </div>
    <div class="score">
      <div class="num"><?=$percent?>%</div>
      <div class="summary">关键项 <?=$criticalOk?> / <?=$criticalTotal?> 通过</div>
    </div>
  </div>

  <div class="alert <?=$allCriticalOk ? 'ok' : 'bad'?>">
    <?=$allCriticalOk ? '关键检查已通过：注册、登录、同步、邮箱验证码和 GitHub OAuth 所需核心依赖基本正常。' : '仍有关键检查失败：请按下面失败项修复后再测试注册/登录/GitHub 登录。'?>
    <?php if ($warningFailed): ?> 另有 <?=$warningFailed?> 个非关键警告项。<?php endif; ?>
  </div>

  <?php foreach ($groups as $group => $rows): ?>
    <section class="group">
      <div class="group-title"><?=h($group)?></div>
      <?php foreach ($rows as $item): ?>
        <div class="row">
          <strong><?=h($item['name'])?></strong>
          <span class="badge <?=$item['ok'] ? 'pass' : 'fail'?>"><?=$item['ok'] ? '通过' : '失败'?></span>
          <span class="detail"><?=h($item['detail'])?></span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <div class="tips">
    <strong>使用建议：</strong><br>
    1. 每次上传新版本后，先访问 <code>/db_check.php</code>。<br>
    2. 如果 <code>email_codes</code> 表失败，说明需要重新导入最新版 <code>database.sql</code>。<br>
    3. 如果 <code>users</code> 表缺少 <code>github_id</code> 等字段，请在 phpMyAdmin 执行最新版 <code>database.sql</code> 里的 GitHub 字段补丁。<br>
    4. 如果 GitHub OAuth 配置失败，检查 <code>db_config.php</code> 的 <code>github.client_id</code>、<code>github.client_secret</code> 和回调地址。<br>
    5. 如果 SMTP 配置失败，检查 QQ 邮箱是否开启 SMTP，以及 <code>pass</code> 是否填的是“授权码”。<br>
    6. 正式稳定后建议删除或改名本文件，避免暴露服务器环境信息。
  </div>
</div>
</body>
</html>
