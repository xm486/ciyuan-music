# 次元音乐 InfinityFree + MySQL 部署说明

本项目现在已经把账号系统从本地 JSON 文件改成 MySQL 存储，并新增了管理后台。

## 新增文件

```txt
db_config.php       数据库与后台账号配置
database.sql        MySQL 建表脚本
admin.php           管理后台
db_check.php        数据库环境检查工具，上线稳定后建议删除
auth_api.php        已改为 MySQL 版账号接口
```

## 1. 在 InfinityFree 创建 MySQL 数据库

进入 InfinityFree 控制面板：

```txt
Control Panel -> MySQL Databases
```

创建数据库后记录这些信息：

```txt
MySQL Host Name，例如 sqlXXX.infinityfree.com
Database Name，例如 if0_XXXXXXX_ciyuan_music
Username，例如 if0_XXXXXXX
Password，你设置的数据库密码
```

## 2. 导入 database.sql

进入 phpMyAdmin：

```txt
Control Panel -> phpMyAdmin -> 选择你的数据库 -> SQL
```

复制 `database.sql` 里的内容执行。

成功后应该有两张表：

```txt
users
user_sync
```

## 3. 修改 db_config.php

把模板内容改成真实数据库信息：

```php
return [
    'db' => [
        'host' => 'sqlXXX.infinityfree.com',
        'name' => 'if0_XXXXXXX_ciyuan_music',
        'user' => 'if0_XXXXXXX',
        'pass' => '你的数据库密码',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        'username' => 'admin',
        'password' => '换成强密码',
    ],
];
```

强烈建议修改后台密码。

## 4. 上传文件

把这些文件上传到网站根目录：

```txt
index.html
auth_api.php
admin.php
db_config.php
database.sql 可不上传，但保留也没关系
db_check.php 测试后建议删除
bilibili_proxy.php
```

## 5. 检查数据库连接

访问：

```txt
https://你的域名/db_check.php
```

如果全部通过，说明配置正确。

稳定上线后建议删除：

```txt
db_check.php
```

避免暴露环境信息。

## 6. 管理后台

访问：

```txt
https://你的域名/admin.php
```

用 `db_config.php` 里配置的后台账号密码登录。

后台目前支持：

```txt
查看注册用户数
查看历史记录总数
查看累计听歌时长
查看用户列表
修改用户昵称
清空用户同步数据
删除用户
```

## 7. 前端接口不需要改

前端仍然使用：

```txt
auth_api.php?action=register
auth_api.php?action=login
auth_api.php?action=logout
auth_api.php?action=me
auth_api.php?action=sync
auth_api.php?action=update_profile
auth_api.php?action=captcha
```

所以 `index.html` 基本不用因为 MySQL 改动而修改。

## 8. 注意事项

### 头像

当前自定义头像仍可能以 dataURL 形式存入数据库。免费数据库空间有限，建议长期改成：

```txt
头像文件上传到 /uploads/avatar/
数据库只保存头像 URL
```

目前为了改造量小，先保持兼容。

### 数据备份

InfinityFree 免费空间不适合长期承载重要数据。建议定期从 phpMyAdmin 导出数据库备份。

### 安全

上线前至少做这些：

```txt
修改 db_config.php 的 admin 密码
测试完成后删除 db_check.php
不要公开 db_config.php 的真实内容
```
