<?php
// 次元音乐 MySQL 配置示例
// 使用方法：复制本文件为 db_config.php，然后填入 InfinityFree 数据库信息。

return [
    'db' => [
        'host' => 'sqlXXX.infinityfree.com',
        'name' => 'if0_XXXXXXX_ciyuan_music',
        'user' => 'if0_XXXXXXX',
        'pass' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'admin' => [
        'username' => 'admin',
        'password' => 'change_this_admin_password',
    ],

    // GitHub OAuth 配置：用于 GitHub 快捷登录和已登录账号绑定。
    // GitHub OAuth App 回调地址建议填写：
    // https://你的域名/auth_api.php?action=github_callback
    'github' => [
        'client_id' => 'YOUR_GITHUB_CLIENT_ID',
        'client_secret' => 'YOUR_GITHUB_CLIENT_SECRET',
        'redirect_uri' => 'https://你的域名/auth_api.php?action=github_callback',
    ],

    // QQ 邮箱 SMTP 配置：用于注册邮箱验证码和找回密码
    // QQ邮箱后台开启 SMTP 后，pass 填“授权码”，不是QQ登录密码。
    'smtp' => [
        'host' => 'ssl://smtp.qq.com',
        'port' => 465,
        'username' => '你的QQ号@qq.com',
        'pass' => '你的QQ邮箱SMTP授权码',
        'from_email' => '你的QQ号@qq.com',
        'from_name' => '次元音乐',
    ],
];