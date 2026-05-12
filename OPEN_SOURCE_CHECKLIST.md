# 开源发布检查清单

发布到 GitHub 前请确认：

- [ ] 仓库中没有 `db_config.php`
- [ ] 没有真实数据库密码
- [ ] 没有 SMTP 授权码
- [ ] 没有 GitHub OAuth Secret
- [ ] 没有真实用户邮箱、反馈、播放历史等数据
- [ ] 没有受版权保护的音乐文件
- [ ] 已阅读 `DISCLAIMER.md`
- [ ] 已根据需要修改 `README.md` 中的演示地址
- [ ] 已确认 `LICENSE`

## 推荐发布步骤

```bash
cd 开源版
git init
git add .
git commit -m "Initial open source release"
git branch -M main
git remote add origin https://github.com/你的用户名/你的仓库名.git
git push -u origin main
```

## 线上部署提醒

如果你把开源版部署到线上，请记得：

1. 复制 `db_config.example.php` 为 `db_config.php`
2. 修改后台管理员密码
3. 导入 `database.sql`
4. 配置 GitHub OAuth 回调地址
5. 配置 SMTP 邮箱授权码
6. 使用 HTTPS
