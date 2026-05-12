<?php
// 次元音乐管理后台
// 访问 /admin.php，使用 db_config.php 中的 admin 用户名和密码登录。

session_start();

function h($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function config() {
    $file = __DIR__ . '/db_config.php';
    if (!file_exists($file)) die('缺少 db_config.php，请先配置数据库。');
    $config = require $file;
    if (!is_array($config) || empty($config['db'])) die('db_config.php 配置格式错误。');
    return $config;
}

function db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = config()['db'];
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = 'mysql:host=' . ($db['host'] ?? '') . ';dbname=' . ($db['name'] ?? '') . ';charset=' . $charset;
    try {
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        die('数据库连接失败：' . h($e->getMessage()));
    }
}

function is_admin() {
    return !empty($_SESSION['ciyuan_admin_logged_in']);
}

function require_admin() {
    if (!is_admin()) {
        header('Location: admin.php');
        exit;
    }
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
    return [
        'level' => $current['level'],
        'name' => $current['name'],
        'expTotal' => $expTotal,
        'todayExp' => $todayExp,
        'nextLevel' => $next ? $next['level'] : null,
        'needExp' => $next ? max(0, $next['exp'] - $expTotal) : 0,
    ];
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
        // 字段缺失会在读取数据或 db_check.php 中暴露，这里不直接中断后台。
    }
}

function ensure_github_columns_admin() {
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
            db()->exec('ALTER TABLE users ADD UNIQUE KEY uniq_github_id (github_id)');
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // GitHub 字段缺失时不让后台直接白屏，读取数据时会显示具体错误。
    }
}

$cfg = config();
$admin = $cfg['admin'] ?? ['username' => 'admin', 'password' => 'change_this_admin_password'];
$error = '';
$notice = '';

if (isset($_GET['logout'])) {
    unset($_SESSION['ciyuan_admin_logged_in']);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (hash_equals((string)$admin['username'], $username) && hash_equals((string)$admin['password'], $password)) {
        $_SESSION['ciyuan_admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = '管理员账号或密码错误';
}

if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                $notice = '用户已删除';
            }
        } elseif ($action === 'reset_sync') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                ensure_level_columns();
                db()->prepare('UPDATE user_sync SET playlists_json = ?, play_history_json = ?, listen_seconds = 0, today_listen_seconds = 0, listen_exp_date = ?, updated_at = ? WHERE user_id = ?')->execute(['{}', '[]', date('Y-m-d'), time(), $id]);
                $notice = '该用户同步数据与等级经验已清空';
            }
        } elseif ($action === 'reset_exp') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                ensure_level_columns();
                db()->prepare('UPDATE user_sync SET listen_seconds = 0, today_listen_seconds = 0, listen_exp_date = ?, updated_at = ? WHERE user_id = ?')->execute([date('Y-m-d'), time(), $id]);
                $notice = '该用户等级经验已重置';
            }
        } elseif ($action === 'rename_user') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['display_name'] ?? ''));
            if ($id > 0 && $name !== '') {
                if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') > 24 : strlen($name) > 72) {
                    $error = '昵称过长';
                } else {
                    db()->prepare('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?')->execute([$name, time(), $id]);
                    $notice = '昵称已修改';
                }
            }
        } elseif ($action === 'delete_feedback') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM feedback WHERE id = ?')->execute([$id]);
                $notice = '反馈已删除';
            }
        } elseif ($action === 'add_announcement' || $action === 'update_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $version = trim((string)($_POST['version'] ?? ''));
            $source = trim((string)($_POST['source'] ?? 'manual'));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($title === '' || $content === '') {
                $error = '请填写公告标题和内容';
            } else {
                if ($action === 'update_announcement') {
                    if ($id <= 0) {
                        $error = '公告ID无效';
                    } else {
                        db()->prepare('UPDATE announcements SET title = ?, content = ?, version = ?, source = ?, is_active = ?, updated_at = ? WHERE id = ?')
                            ->execute([$title, $content, $version !== '' ? $version : null, $source !== '' ? $source : 'manual', $isActive, time(), $id]);
                        $notice = '公告已更新';
                    }
                } else {
                    db()->prepare('INSERT INTO announcements (title, content, version, source, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$title, $content, $version !== '' ? $version : null, $source !== '' ? $source : 'manual', $isActive, time(), time()]);
                    $notice = '公告已添加';
                }
            }
        } elseif ($action === 'delete_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
                $notice = '公告已删除';
            }
        } elseif ($action === 'toggle_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('UPDATE announcements SET is_active = IF(is_active = 1, 0, 1), updated_at = ? WHERE id = ?')->execute([time(), $id]);
                $notice = '公告状态已切换';
            }
        } elseif ($action === 'update_feedback_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'pending'));
            $allowed = ['pending', 'processing', 'resolved', 'closed'];
            if ($id > 0 && in_array($status, $allowed, true)) {
                db()->prepare('UPDATE feedback SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, time(), $id]);
                $notice = '反馈状态已更新';
            }
        } elseif ($action === 'add_friend_link' || $action === 'update_friend_link') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $iconUrl = trim((string)($_POST['icon_url'] ?? ''));
            $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
            $sortOrder = ($sortOrderRaw === '') ? 0 : (int)$sortOrderRaw;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '' || $url === '') {
                $error = '请填写站点名称和链接';
            } else {
                if (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . ltrim($url, '/');
                }
                if ($iconUrl === '') {
                    $parts = parse_url($url);
                    if (!empty($parts['scheme']) && !empty($parts['host'])) {
                        $iconUrl = $parts['scheme'] . '://' . $parts['host'] . '/favicon.ico';
                    }
                }
                if ($action === 'update_friend_link') {
                    if ($id <= 0) {
                        $error = '友情链接ID无效';
                    } else {
                        if ($sortOrder <= 0) $sortOrder = next_friend_link_sort_order();
                        db()->prepare('UPDATE friend_links SET name = ?, url = ?, description = ?, icon_url = ?, sort_order = ?, is_active = ?, updated_at = ? WHERE id = ?')
                            ->execute([$name, $url, $description ?: null, $iconUrl ?: null, $sortOrder, $isActive, time(), $id]);
                        $notice = '友情链接已更新';
                    }
                } else {
                    if ($sortOrder <= 0) $sortOrder = next_friend_link_sort_order();
                    db()->prepare('INSERT INTO friend_links (name, url, description, icon_url, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                        ->execute([$name, $url, $description ?: null, $iconUrl ?: null, $sortOrder, time(), time()]);
                    normalize_friend_link_sort_orders();
                    $notice = '友情链接已添加';
                }
            }
        } elseif ($action === 'delete_friend_link') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM friend_links WHERE id = ?')->execute([$id]);
                normalize_friend_link_sort_orders();
                $notice = '友情链接已删除';
            }
        } elseif ($action === 'toggle_friend_link') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('UPDATE friend_links SET is_active = IF(is_active = 1, 0, 1), updated_at = ? WHERE id = ?')->execute([time(), $id]);
                $notice = '友情链接状态已切换';
            }
        }
    } catch (Throwable $e) {
        $error = '操作失败：' . $e->getMessage();
    }
}

function normalize_friend_link_sort_orders() {
    try {
        $rows = db()->query('SELECT id, sort_order FROM friend_links ORDER BY sort_order ASC, id ASC')->fetchAll();
        $next = 10;
        foreach ($rows as $row) {
            if ((int)$row['sort_order'] !== $next) {
                db()->prepare('UPDATE friend_links SET sort_order = ?, updated_at = ? WHERE id = ?')->execute([$next, time(), (int)$row['id']]);
            }
            $next += 10;
        }
    } catch (Throwable $e) {}
}

function next_friend_link_sort_order() {
    try {
        $max = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM friend_links')->fetchColumn();
        return $max + 10;
    } catch (Throwable $e) {
        return 10;
    }
}

function ensure_visit_tables_admin() {
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
    } catch (Throwable $e) {}
}

function ensure_support_tables_admin() {
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
    } catch (Throwable $e) {}
}

$stats = ['users' => 0, 'github' => 0, 'history' => 0, 'today_visit_users' => 0, 'visit_total' => 0, 'listen' => 0, 'exp' => 0, 'lv6' => 0, 'feedback' => 0, 'links' => 0, 'announcements' => 0];
$users = [];
$feedbackRows = [];
$friendLinks = [];
$announcements = [];
if (is_admin()) {
    try {
        ensure_level_columns();
        ensure_visit_tables_admin();
        ensure_support_tables_admin();
        ensure_github_columns_admin();
        $stats['users'] = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $stats['github'] = (int)db()->query('SELECT COUNT(*) FROM users WHERE github_id IS NOT NULL AND github_id <> ""')->fetchColumn();
        $today = date('Y-m-d');
        $visitStmt = db()->prepare('SELECT COUNT(DISTINCT visitor_key) FROM site_visits WHERE visit_date = ?');
        $visitStmt->execute([$today]);
        $stats['today_visit_users'] = (int)$visitStmt->fetchColumn();
        $stats['visit_total'] = (int)db()->query('SELECT COUNT(*) FROM site_visits')->fetchColumn();
        $stats['listen'] = (int)db()->query('SELECT COALESCE(SUM(listen_seconds), 0) FROM user_sync')->fetchColumn();
        $stats['exp'] = intdiv($stats['listen'], 60);
        $stats['lv6'] = (int)db()->query('SELECT COUNT(*) FROM user_sync WHERE listen_seconds >= 4000 * 60')->fetchColumn();
        $rows = db()->query('SELECT u.*, s.playlists_json, s.play_history_json, s.listen_seconds, s.today_listen_seconds, s.listen_exp_date, s.updated_at AS sync_updated_at FROM users u LEFT JOIN user_sync s ON s.user_id = u.id ORDER BY u.id DESC LIMIT 200')->fetchAll();
        foreach ($rows as $row) {
            $playlists = json_decode($row['playlists_json'] ?: '{}', true);
            $history = json_decode($row['play_history_json'] ?: '[]', true);
            $row['playlist_count'] = is_array($playlists) ? count($playlists) : 0;
            $row['history_count'] = is_array($history) ? count($history) : 0;
            $todaySeconds = ($row['listen_exp_date'] ?? '') === date('Y-m-d') ? (int)($row['today_listen_seconds'] ?? 0) : 0;
            $row['level_info'] = level_info((int)($row['listen_seconds'] ?? 0), $todaySeconds);
            $stats['history'] += $row['history_count'];
            $users[] = $row;
        }
        $stats['feedback'] = (int)db()->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
        $stats['links'] = (int)db()->query('SELECT COUNT(*) FROM friend_links')->fetchColumn();
        $stats['announcements'] = (int)db()->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        $feedbackRows = db()->query('SELECT f.*, u.display_name, u.email FROM feedback f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.id DESC LIMIT 100')->fetchAll();
        $friendLinks = db()->query('SELECT * FROM friend_links ORDER BY sort_order ASC, id ASC LIMIT 100')->fetchAll();
        $announcements = db()->query('SELECT * FROM announcements ORDER BY id DESC LIMIT 100')->fetchAll();
    } catch (Throwable $e) {
        $error = '读取数据失败：' . $e->getMessage();
    }
}

function format_seconds($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . '时' . $m . '分';
    return $m . '分';
}

function format_time($ts) {
    $ts = (int)$ts;
    return $ts > 0 ? date('Y-m-d H:i', $ts) : '-';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>次元音乐管理后台</title>
<style>
:root { color-scheme: dark; --bg:#080b18; --card:rgba(255,255,255,.08); --line:rgba(255,255,255,.12); --text:#f8fbff; --sub:rgba(255,255,255,.62); --a:#00f5d4; --b:#7c4dff; --danger:#ff3b30; }
* { box-sizing:border-box; }
body { margin:0; min-height:100vh; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,"Microsoft YaHei",sans-serif; color:var(--text); background:radial-gradient(circle at 15% 10%, rgba(0,245,212,.18), transparent 30%), radial-gradient(circle at 90% 0%, rgba(124,77,255,.22), transparent 32%), var(--bg); }
.wrap { max-width:1180px; margin:0 auto; padding:24px; }
.header { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:22px; }
.title { font-size:26px; font-weight:900; letter-spacing:.5px; background:linear-gradient(135deg,#fff,var(--a),#ff4fd8); -webkit-background-clip:text; color:transparent; }
.card { border:1px solid var(--line); border-radius:24px; background:var(--card); backdrop-filter:blur(18px); box-shadow:0 18px 50px rgba(0,0,0,.28); }
.login { max-width:420px; margin:12vh auto; padding:24px; }
.input { width:100%; height:46px; margin:8px 0; padding:0 14px; border-radius:15px; border:1px solid var(--line); background:rgba(0,0,0,.26); color:var(--text); outline:none; }
.btn { height:36px; padding:0 14px; border-radius:999px; border:1px solid var(--line); color:#fff; background:linear-gradient(135deg,rgba(0,122,255,.88),rgba(0,245,212,.55)); cursor:pointer; font-weight:800; }
.btn.ghost { background:rgba(255,255,255,.08); color:var(--sub); }
.btn.danger { background:rgba(255,59,48,.16); color:#ffb4ad; border-color:rgba(255,59,48,.28); }
.msg { margin:12px 0; padding:10px 12px; border-radius:14px; background:rgba(0,122,255,.14); color:#ddecff; border:1px solid rgba(0,122,255,.24); }
.msg.err { background:rgba(255,59,48,.14); border-color:rgba(255,59,48,.24); color:#ffd2cd; }
.stats { display:grid; grid-template-columns:repeat(8,1fr); gap:12px; margin-bottom:18px; }
.stat { padding:18px; }
.stat .num { font-size:26px; font-weight:900; color:var(--a); }
.stat .lab { margin-top:4px; color:var(--sub); font-size:13px; }
.table-card { overflow:hidden; }
table { width:100%; border-collapse:collapse; }
th,td { padding:12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:middle; font-size:13px; }
th { color:var(--sub); font-weight:800; background:rgba(255,255,255,.04); }
.avatar { width:38px; height:38px; border-radius:12px; object-fit:cover; background:linear-gradient(135deg,var(--b),var(--a)); vertical-align:middle; margin-right:8px; }
.user-line { display:flex; align-items:center; min-width:220px; }
.email { color:var(--sub); margin-top:2px; font-size:12px; }
.inline-form { display:inline-flex; gap:6px; align-items:center; margin:2px; }
.name-input { width:118px; height:32px; border-radius:10px; border:1px solid var(--line); background:rgba(0,0,0,.22); color:var(--text); padding:0 8px; }
.small { color:var(--sub); font-size:12px; }
.lv-badge { display:inline-flex; align-items:center; justify-content:center; height:28px; min-width:48px; padding:0 10px; border-radius:999px; color:#fff; font-weight:950; background:linear-gradient(135deg,#007aff,#00f5d4); box-shadow:0 6px 18px rgba(0,122,255,.22); }
.lv-badge.lv-5 { background:linear-gradient(135deg,#ffcc00,#ff2d55,#7c4dff); }
.lv-badge.lv-6 { color:#2b1800; background:linear-gradient(135deg,#fff1a8,#ffcc00,#ff9500); }
.level-cell { min-width:120px; }
.github-cell { min-width:150px; }
.github-badge { display:inline-flex; align-items:center; gap:6px; height:28px; padding:0 11px; border-radius:999px; font-weight:900; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.07); color:var(--sub); }
.github-badge.bound { color:#fff; background:linear-gradient(135deg,rgba(36,41,47,.88),rgba(0,122,255,.34)); border-color:rgba(0,245,212,.28); box-shadow:0 6px 18px rgba(0,245,212,.12); }
.github-badge svg { width:15px; height:15px; fill:currentColor; }
.github-id { margin-top:4px; color:rgba(255,255,255,.46); font-size:11px; }
@media (max-width:980px){ .stats{grid-template-columns:repeat(2,1fr);} }
@media (max-width:760px){ .wrap{padding:14px;} .header{align-items:flex-start; flex-direction:column;} .stats{grid-template-columns:1fr;} .table-card{overflow:auto;} th,td{white-space:nowrap;} }
</style>
</head>
<body>
<div class="wrap">
<?php if (!is_admin()): ?>
  <form class="card login" method="post">
    <input type="hidden" name="action" value="login">
    <div class="title">次元音乐管理后台</div>
    <p class="small">请输入 db_config.php 中配置的管理员账号。</p>
    <?php if ($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>
    <input class="input" name="username" placeholder="管理员用户名" autocomplete="username">
    <input class="input" name="password" type="password" placeholder="管理员密码" autocomplete="current-password">
    <button class="btn" type="submit" style="width:100%;height:44px;margin-top:10px;">登录后台</button>
  </form>
<?php else: ?>
  <div class="header">
    <div>
      <div class="title">次元音乐管理后台</div>
      <div class="small">用户、同步数据、听歌等级与经验概览</div>
    </div>
    <a class="btn ghost" href="?logout=1" style="text-decoration:none;display:inline-flex;align-items:center;">退出后台</a>
  </div>
  <?php if ($notice): ?><div class="msg"><?=h($notice)?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>
  <div class="stats">
    <div class="card stat"><div class="num"><?=h($stats['users'])?></div><div class="lab">注册用户</div></div>
    <div class="card stat"><div class="num"><?=h($stats['github'])?></div><div class="lab">GitHub 绑定</div></div>
<div class="card stat"><div class="num"><?=h($stats['today_visit_users'])?></div><div class="lab">今日访问人数</div></div>
<div class="card stat"><div class="num"><?=h($stats['visit_total'])?></div><div class="lab">累计访问人次</div></div>
<div class="card stat"><div class="num"><?=h(format_seconds($stats['listen']))?></div><div class="lab">累计听歌时长</div></div>
    <div class="card stat"><div class="num"><?=h($stats['exp'])?></div><div class="lab">累计经验 XP</div></div>
    <div class="card stat"><div class="num"><?=h($stats['lv6'])?></div><div class="lab">Lv6 用户</div></div>
    <div class="card stat"><div class="num"><?=h($stats['feedback'])?></div><div class="lab">问题反馈</div></div>
<div class="card stat"><div class="num"><?=h($stats['links'])?></div><div class="lab">友情链接</div></div>
<div class="card stat"><div class="num"><?=h($stats['announcements'])?></div><div class="lab">公告数量</div></div>
  </div>
  <div class="card table-card">
    <table>
      <thead><tr><th>用户</th><th>GitHub</th><th>等级</th><th>经验</th><th>今日</th><th>歌单</th><th>历史</th><th>听歌时长</th><th>注册时间</th><th>最近同步</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="11" class="small">暂无用户</td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="user-line">
              <?php if (!empty($u['avatar_url'])): ?><img class="avatar" src="<?=h($u['avatar_url'])?>" onerror="this.style.display='none'" alt="头像"><?php endif; ?>
              <div><strong><?=h($u['display_name'])?></strong><div class="email"><?=h($u['email'])?></div></div>
            </div>
          </td>
          <td class="github-cell">
            <?php if (!empty($u['github_id'])): ?>
              <span class="github-badge bound" title="GitHub ID: <?=h($u['github_id'])?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.58 2 12.26c0 4.52 2.87 8.36 6.84 9.72.5.1.68-.22.68-.49 0-.24-.01-.89-.01-1.75-2.78.62-3.37-1.37-3.37-1.37-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.89 1.56 2.34 1.11 2.91.85.09-.66.35-1.11.63-1.37-2.22-.26-4.56-1.14-4.56-5.06 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.71 0 0 .84-.28 2.75 1.05A9.3 9.3 0 0 1 12 6.98c.85 0 1.7.12 2.5.34 1.9-1.33 2.74-1.05 2.74-1.05.55 1.41.2 2.45.1 2.71.64.72 1.03 1.63 1.03 2.75 0 3.93-2.34 4.79-4.57 5.05.36.32.68.94.68 1.9 0 1.37-.01 2.48-.01 2.81 0 .27.18.6.69.49A10.08 10.08 0 0 0 22 12.26C22 6.58 17.52 2 12 2Z" /></svg>
                <?=h($u['github_login'] ?: '已绑定')?>
              </span>
              <div class="github-id">ID <?=h($u['github_id'])?> · <?=h(format_time($u['github_bound_at'] ?? 0))?></div>
            <?php else: ?>
              <span class="github-badge">未绑定</span>
            <?php endif; ?>
          </td>
          <td class="level-cell"><span class="lv-badge lv-<?=h($u['level_info']['level'])?>">Lv<?=h($u['level_info']['level'])?></span><div class="small"><?=h($u['level_info']['name'])?></div></td>
          <td><?=h($u['level_info']['expTotal'])?> XP<?php if ($u['level_info']['nextLevel']): ?><div class="small">差 <?=h($u['level_info']['needExp'])?> XP 到 Lv<?=h($u['level_info']['nextLevel'])?></div><?php else: ?><div class="small">已满级</div><?php endif; ?></td>
          <td>+<?=h($u['level_info']['todayExp'])?> XP</td>
          <td><?=h($u['playlist_count'])?></td>
          <td><?=h($u['history_count'])?></td>
          <td><?=h(format_seconds($u['listen_seconds'] ?? 0))?></td>
          <td><?=h(format_time($u['created_at']))?></td>
          <td><?=h(format_time($u['sync_updated_at'] ?? 0))?></td>
          <td>
            <form class="inline-form" method="post">
              <input type="hidden" name="action" value="rename_user"><input type="hidden" name="id" value="<?=h($u['id'])?>">
              <input class="name-input" name="display_name" value="<?=h($u['display_name'])?>"><button class="btn ghost" type="submit">改名</button>
            </form>
            <form class="inline-form" method="post" onsubmit="return confirm('确定清空该用户同步数据？歌单、历史和等级经验都会清空。');">
              <input type="hidden" name="action" value="reset_sync"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn ghost" type="submit">清空同步</button>
            </form>
            <form class="inline-form" method="post" onsubmit="return confirm('确定只重置该用户等级经验？歌单和历史会保留。');">
              <input type="hidden" name="action" value="reset_exp"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn ghost" type="submit">重置经验</button>
            </form>
            <form class="inline-form" method="post" onsubmit="return confirm('确定删除该用户？此操作不可恢复。');">
              <input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin:22px 0 12px;font-size:20px;">公告管理</h2>
  <div class="small" style="margin:-6px 0 10px;color:var(--sub);">前台更新说明会自动写入这里；也可以手动新增、编辑、删除。</div>
  <form class="card" method="post" style="padding:14px;margin-bottom:12px;display:grid;grid-template-columns:1fr 1.6fr .7fr .7fr auto auto;gap:8px;align-items:center;">
    <input type="hidden" name="action" value="add_announcement">
    <input class="input" name="title" placeholder="公告标题">
    <input class="input" name="content" placeholder="公告内容（支持纯文本）">
    <input class="input" name="version" placeholder="版本号，例如 1.1.0">
    <input class="input" name="source" placeholder="来源，如 manual / update" value="manual">
    <label class="small"><input type="checkbox" name="is_active" value="1" checked> 显示</label>
    <button class="btn" type="submit">添加公告</button>
  </form>
  <div class="card table-card">
    <table>
      <thead><tr><th>标题</th><th>内容</th><th>版本</th><th>来源</th><th>时间</th><th>显示</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$announcements): ?><tr><td colspan="7" class="small">暂无公告</td></tr><?php endif; ?>
      <?php foreach ($announcements as $a): ?>
        <?php $annoFormId = 'announcementForm' . (int)$a['id']; ?>
        <tr>
          <td>
            <form id="<?=h($annoFormId)?>" method="post">
              <input type="hidden" name="action" value="update_announcement">
              <input type="hidden" name="id" value="<?=h($a['id'])?>">
            </form>
            <input form="<?=h($annoFormId)?>" class="input" name="title" value="<?=h($a['title'])?>" style="min-width:150px;">
          </td>
          <td><textarea form="<?=h($annoFormId)?>" class="input" name="content" style="min-width:280px;min-height:70px;resize:vertical;line-height:1.6;"><?=h($a['content'])?></textarea></td>
          <td><input form="<?=h($annoFormId)?>" class="input" name="version" value="<?=h($a['version'] ?? '')?>" style="width:100px;"></td>
          <td><input form="<?=h($annoFormId)?>" class="input" name="source" value="<?=h($a['source'] ?? 'manual')?>" style="width:100px;"></td>
          <td><?=h(format_time($a['created_at']))?></td>
          <td style="white-space:nowrap;"><label class="small"><input form="<?=h($annoFormId)?>" type="checkbox" name="is_active" value="1" <?=((int)$a['is_active'] === 1) ? 'checked' : ''?>> 显示</label></td>
          <td style="white-space:nowrap;">
            <button form="<?=h($annoFormId)?>" class="btn" type="submit">保存</button>
            <form class="inline-form" method="post"><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="id" value="<?=h($a['id'])?>"><button class="btn ghost" type="submit">切换</button></form>
            <form class="inline-form" method="post" onsubmit="return confirm('确定删除这条公告？');"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="id" value="<?=h($a['id'])?>"><button class="btn danger" type="submit">删除</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin:22px 0 12px;font-size:20px;">问题反馈</h2>
  <div class="card table-card">
    <table>
      <thead><tr><th>用户</th><th>标题</th><th>详情</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$feedbackRows): ?><tr><td colspan="6" class="small">暂无反馈</td></tr><?php endif; ?>
      <?php foreach ($feedbackRows as $f): ?>
        <tr>
          <td><strong><?=h($f['display_name'] ?: '未知用户')?></strong><div class="email"><?=h($f['email'] ?? '')?></div></td>
          <td><?=h($f['title'])?></td>
          <td style="max-width:360px;white-space:normal;line-height:1.6;"><?=nl2br(h($f['content']))?></td>
          <td>
            <form class="inline-form" method="post">
              <input type="hidden" name="action" value="update_feedback_status"><input type="hidden" name="id" value="<?=h($f['id'])?>">
              <select class="name-input" name="status" style="width:112px;">
                <?php foreach (['pending'=>'待处理','processing'=>'处理中','resolved'=>'已解决','closed'=>'已关闭'] as $k=>$v): ?>
                  <option value="<?=h($k)?>" <?=$f['status']===$k?'selected':''?>><?=h($v)?></option>
                <?php endforeach; ?>
              </select><button class="btn ghost" type="submit">保存</button>
            </form>
          </td>
          <td><?=h(format_time($f['created_at']))?></td>
          <td><form class="inline-form" method="post" onsubmit="return confirm('确定删除这条反馈？');"><input type="hidden" name="action" value="delete_feedback"><input type="hidden" name="id" value="<?=h($f['id'])?>"><button class="btn danger" type="submit">删除</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin:22px 0 12px;font-size:20px;">友情链接</h2>
  <div class="small" style="margin:-6px 0 10px;color:var(--muted);">排序数字越小越靠前；新增时排序留空会自动排到最后，并按 10、20、30 自动分配序号。</div>
  <form class="card" method="post" style="padding:14px;margin-bottom:12px;display:grid;grid-template-columns:1fr 1.4fr 1.4fr 1.4fr .6fr auto;gap:8px;align-items:center;">
    <input type="hidden" name="action" value="add_friend_link">
    <input class="input" name="name" placeholder="站点名称">
    <input class="input" name="url" placeholder="https://example.com">
    <input class="input" name="description" placeholder="描述（选填）">
    <input class="input" name="icon_url" placeholder="图标URL（选填，默认 /favicon.ico）">
    <input class="input" name="sort_order" type="number" placeholder="排序，留空排最后" value="">
    <button class="btn" type="submit">添加</button>
  </form>
  <div class="card table-card">
    <table>
      <thead><tr><th>图标</th><th>名称</th><th>链接</th><th>描述</th><th>图标URL</th><th>排序</th><th>显示</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$friendLinks): ?><tr><td colspan="8" class="small">暂无友情链接</td></tr><?php endif; ?>
      <?php foreach ($friendLinks as $l): ?>
        <?php $linkFormId = 'friendLinkForm' . (int)$l['id']; ?>
        <tr>
          <td>
            <form id="<?=h($linkFormId)?>" method="post">
              <input type="hidden" name="action" value="update_friend_link">
              <input type="hidden" name="id" value="<?=h($l['id'])?>">
            </form>
            <?php if (!empty($l['icon_url'])): ?><img src="<?=h($l['icon_url'])?>" alt="" style="width:28px;height:28px;border-radius:8px;object-fit:cover;background:#1f2937;" onerror="this.style.display='none';"><?php endif; ?>
          </td>
          <td><input form="<?=h($linkFormId)?>" class="input" name="name" value="<?=h($l['name'])?>" style="min-width:110px;"></td>
          <td><input form="<?=h($linkFormId)?>" class="input" name="url" value="<?=h($l['url'])?>" style="min-width:180px;"></td>
          <td><input form="<?=h($linkFormId)?>" class="input" name="description" value="<?=h($l['description'] ?? '')?>" style="min-width:150px;"></td>
          <td><input form="<?=h($linkFormId)?>" class="input" name="icon_url" value="<?=h($l['icon_url'] ?? '')?>" placeholder="留空自动生成" style="min-width:180px;"></td>
          <td><input form="<?=h($linkFormId)?>" class="input" name="sort_order" type="number" value="<?=h($l['sort_order'])?>" style="width:72px;"></td>
          <td style="white-space:nowrap;"><label class="small"><input form="<?=h($linkFormId)?>" type="checkbox" name="is_active" value="1" <?=((int)$l['is_active'] === 1) ? 'checked' : ''?>> 显示</label></td>
          <td style="white-space:nowrap;">
            <button form="<?=h($linkFormId)?>" class="btn" type="submit">保存</button>
            <form class="inline-form" method="post"><input type="hidden" name="action" value="toggle_friend_link"><input type="hidden" name="id" value="<?=h($l['id'])?>"><button class="btn ghost" type="submit">切换</button></form>
            <form class="inline-form" method="post" onsubmit="return confirm('确定删除这个友情链接？');"><input type="hidden" name="action" value="delete_friend_link"><input type="hidden" name="id" value="<?=h($l['id'])?>"><button class="btn danger" type="submit">删除</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
</body>
</html>