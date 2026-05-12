# ciyuan_music/次元音乐

<p align="center">
  <img src="image/logo.png" width="120" alt="次元音乐 Logo">
</p>

<p align="center">
  一个二次元风格的轻量级 PWA 音乐播放器。  
  支持账号系统、云同步、GitHub 登录、后台管理、公告系统、访问统计和 PWA 更新提示。
</p>

<p align="center">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-5.7%2B-orange.svg">
  <img alt="PWA" src="https://img.shields.io/badge/PWA-ready-00c853.svg">
</p>

---

## 在线演示

> https://xm486.kesug.com/

如果演示站不可访问，可能是免费空间休眠、维护或第三方接口波动导致。

---

## 项目简介

**次元音乐** 是一个基于 `PHP + MySQL + 原生前端` 的音乐播放器项目，目标是用尽量低的部署门槛实现一个完整的个人音乐站 / PWA 音乐应用。

它不是单纯的静态播放器，而是包含：

- 前端播放器界面
- 账号注册登录
- 邮箱验证码
- GitHub OAuth 登录
- 本地状态保存
- 云端同步
- 后台管理
- 公告系统
- 问题反馈
- 友情链接
- 访问统计
- PWA 缓存与更新提示

适合用于：

- PHP + MySQL 项目学习
- PWA 音乐播放器实践
- 个人音乐站二次开发
- 免费虚拟主机部署实验
- 前后端一体化小项目参考

---

## 功能特性

### 音乐播放

- 歌曲搜索与播放
- 播放 / 暂停 / 上一首 / 下一首
- 迷你播放器
- 全屏播放器
- 播放队列
- 播放历史
- 喜欢 / 收藏歌曲
- 本地歌单管理
- 播放进度保存
- 多端基础状态同步

### 账号系统

- 邮箱注册
- 邮箱登录
- 邮箱验证码
- 找回密码
- GitHub OAuth 登录
- GitHub 账号绑定 / 解绑
- 个人昵称与头像
- 登录状态恢复
- 退出登录本地状态清理

### 云同步

- 歌单同步
- 播放历史同步
- 听歌时长同步
- 等级经验统计
- 手动同步
- 同步冲突检测
- 云端 / 本地合并处理

### PWA 能力

- Service Worker 静态缓存
- 首页秒开缓存策略
- PHP 接口不缓存
- 第三方音频不缓存
- 发现新版本提示
- 手动检查更新
- 更新后首次弹出更新说明
- 版本更新记录自动同步到公告系统

### 后台管理

- 用户列表
- 用户改名
- 删除用户
- 清空用户同步数据
- 重置用户经验
- GitHub 绑定状态查看
- 问题反馈管理
- 反馈状态更新
- 友情链接管理
- 公告管理
- 访问统计
- 听歌数据统计

### 运营与合规

- 隐私协议入口
- 公告与更新记录
- 问题反馈入口
- 访问人数统计
- 友情链接展示
- 开源免责声明

---

## 技术栈

| 模块 | 技术 |
| --- | --- |
| 前端 | HTML / CSS / 原生 JavaScript |
| 后端 | PHP |
| 数据库 | MySQL |
| PWA | Service Worker / Manifest |
| 邮件 | SMTP |
| 登录扩展 | GitHub OAuth |
| 部署环境 | PHP 虚拟主机、InfinityFree、普通 LAMP 环境 |

> 项目没有使用复杂构建工具，适合直接上传到 PHP 空间运行。

---

## 环境要求

推荐环境：

- PHP 7.4+
- MySQL 5.7+ 或 MariaDB
- 支持 PDO MySQL
- 支持 PHP Session
- 支持 HTTPS，PWA 推荐必须 HTTPS
- 可用 SMTP 邮箱账号

如果使用 InfinityFree 一类免费空间，请确认：

- MySQL 数据库可用
- PHP 可连接外部 SMTP
- 文件上传大小限制足够
- 免费空间不会拦截必要请求

---

## 目录结构

```txt
.
├── image/                    # 图标与静态图片
├── index.html                # 前端主应用
├── auth_api.php              # 账号、同步、反馈、公告、访问统计接口
├── admin.php                 # 管理后台
├── bilibili_proxy.php        # Bilibili 音源辅助接口
├── db_check.php              # 部署检查工具
├── database.sql              # 数据库初始化脚本
├── db_config.example.php     # 配置示例，复制为 db_config.php 使用
├── manifest.json             # PWA Manifest
├── service-worker.js         # PWA 缓存、更新提示、版本说明
├── README.md                 # 项目说明
├── LICENSE                   # MIT 协议
├── SECURITY.md               # 安全说明
├── CONTRIBUTING.md           # 贡献指南
├── DISCLAIMER.md             # 免责声明
└── OPEN_SOURCE_CHECKLIST.md  # 开源发布检查清单
```

---

## 快速部署

### 1. 下载源码

```bash
git clone https://github.com/你的用户名/你的仓库名.git
cd 你的仓库名
```

如果你是直接下载 ZIP，解压后上传文件即可。

### 2. 上传文件

将项目文件上传到网站根目录，例如：

```txt
/public_html/
```

确保以下文件可访问：

```txt
index.html
auth_api.php
admin.php
service-worker.js
manifest.json
```

### 3. 创建配置文件

复制：

```txt
db_config.example.php
```

为：

```txt
db_config.php
```

然后填写真实配置。

### 4. 导入数据库

在 phpMyAdmin 中执行：

```txt
database.sql
```

如果数据库表已存在，可重复执行 `CREATE TABLE IF NOT EXISTS` 部分。

### 5. 访问部署检查工具

访问：

```txt
https://你的域名/db_check.php
```

检查：

- 数据库连接
- 数据表是否存在
- 邮件配置是否填写
- GitHub OAuth 是否填写

部署完成后，建议删除或限制访问 `db_check.php`。

### 6. 访问前台

```txt
https://你的域名/
```

### 7. 访问后台

```txt
https://你的域名/admin.php
```

后台账号密码来自：

```php
'admin' => [
    'username' => 'admin',
    'password' => 'change_this_admin_password',
]
```

请务必修改默认密码。

---

## 配置说明

`db_config.php` 示例：

```php
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

    'github' => [
        'client_id' => 'YOUR_GITHUB_CLIENT_ID',
        'client_secret' => 'YOUR_GITHUB_CLIENT_SECRET',
        'redirect_uri' => 'https://你的域名/auth_api.php?action=github_callback',
    ],

    'smtp' => [
        'host' => 'ssl://smtp.qq.com',
        'port' => 465,
        'username' => '你的QQ号@qq.com',
        'pass' => '你的QQ邮箱SMTP授权码',
        'from_email' => '你的QQ号@qq.com',
        'from_name' => '次元音乐',
    ],
];
```

### GitHub OAuth 配置

1. 打开 GitHub Developer Settings
2. 创建 OAuth App
3. Authorization callback URL 填写：

```txt
https://你的域名/auth_api.php?action=github_callback
```

4. 将 `client_id` 和 `client_secret` 填入 `db_config.php`

### SMTP 配置

如果使用 QQ 邮箱：

1. QQ 邮箱设置中开启 SMTP
2. 生成授权码
3. `pass` 填授权码，不是 QQ 登录密码

---

## PWA 更新机制

项目使用 `service-worker.js` 缓存核心静态资源。

默认不会缓存：

- PHP 接口
- 第三方音频
- 外部 API 请求

当你发布新版本时，需要修改：

```js
const APP_VERSION = '1.1.0';
const UPDATE_TITLE = '次元音乐 v1.1.0 更新说明';
const UPDATE_NOTES = [
  '新增 xxx 功能。',
  '优化 xxx 体验。'
];
```

用户访问时会：

1. 检测到新的 Service Worker
2. 底部出现“发现新版本”提示
3. 用户点击“立即更新”
4. 新 Service Worker 接管
5. 页面刷新
6. 首次弹出本次更新说明
7. 更新记录自动同步到公告系统

---

## 后台管理说明

后台地址：

```txt
/admin.php
```

主要能力：

- 查看注册用户
- 查看 GitHub 绑定状态
- 查看等级经验
- 清空同步数据
- 重置经验
- 删除用户
- 管理问题反馈
- 管理友情链接
- 管理公告与更新记录
- 查看今日访问人数
- 查看累计访问人次

安全建议：

- 修改默认管理员密码
- 不要公开后台地址
- 生产环境建议限制 IP 或增加额外鉴权
- 定期备份数据库

---

## 数据库表说明

| 表名 | 说明 |
| --- | --- |
| `users` | 用户账号信息 |
| `user_sync` | 用户歌单、历史、听歌时长同步数据 |
| `email_codes` | 邮箱验证码 |
| `feedback` | 用户反馈 |
| `friend_links` | 友情链接 |
| `site_visits` | 访问统计 |
| `announcements` | 公告与版本更新记录 |

---

## 常见问题

### 登录注册邮件发不出去？

检查：

- SMTP 是否开启
- 授权码是否正确
- 端口是否可用
- 免费空间是否禁用外部 SMTP

### GitHub 登录失败？

检查：

- callback URL 是否完全一致
- `client_id` 是否正确
- `client_secret` 是否正确
- 站点是否使用 HTTPS

### 更新后页面还是旧版本？

PWA 会缓存页面。可以尝试：

- 点击设置里的“检查更新”
- 刷新页面
- 关闭标签页重新打开
- 清除站点缓存

### 后台打不开？

检查：

- `db_config.php` 是否存在
- admin 用户名密码是否配置
- PHP Session 是否可用
- 数据库连接是否正常

### 播放失败？

第三方音源可能失效、跨域或限流。项目不保证第三方接口长期可用。

---

## 开源发布注意事项

请不要提交：

```txt
db_config.php
真实数据库密码
SMTP 授权码
GitHub OAuth Secret
真实用户数据
日志文件
版权音乐文件
```

本仓库已经提供 `.gitignore`，默认忽略：

```txt
db_config.php
data/
uploads/
logs/
cache/
tmp/
```

发布前建议阅读：

```txt
OPEN_SOURCE_CHECKLIST.md
```

---

## 路线图

后续可继续扩展：

- 歌词显示
- 播放失败自动换源
- 睡眠定时
- 后台访问趋势图
- 更细粒度同步合并
- 前端文件拆分
- 更完善的安全防护
- Docker 部署支持

---

## 贡献

欢迎提交 Issue 和 Pull Request。

提交前请阅读：

```txt
CONTRIBUTING.md
SECURITY.md
```

---

## 免责声明

本项目仅提供播放器框架和相关管理系统示例，不托管、不分发任何版权音乐文件。

使用者应自行确保：

- 遵守所在地法律法规
- 遵守第三方平台服务条款
- 不将本项目用于侵犯版权的用途
- 不公开传播未经授权的音乐资源

更多说明见：

```txt
DISCLAIMER.md
```

---

## 许可证

本项目采用 MIT License，详见：

```txt
LICENSE
```
