# 次元音乐 - 阶段性技术总结

> 更新时间：2025-05-09
> 项目：次元音乐（在线音乐播放器）
> 前端：单文件 index.html（HTML + CSS + JS，二次元赛博星空主题）
> 后端：xm486.kesug.com（PHP，部署 jiuhunwl/music_jx 项目）

---

## 一、项目架构

```
前端 (index.html)
├── UI层：二次元霓虹主题，玻璃态设计，底部Tab导航
├── 页面：首页 / 歌单 / 搜索 / 我的
├── 播放器：迷你播放器 + 全屏播放器 + 歌词滚动
└── 数据层：localStorage 持久化（歌单、历史、设置）

后端 (xm486.kesug.com) [可选，当前前端直连第三方API]
├── index.php（统一入口，酷我+网易云接口）
├── getMusicapi.php（网易云EAPI加密类）
├── 163music.php / kuwo.php / qsmusic.php（原始接口参考）
```

---

## 二、API 调用全景

### 2.1 搜索

| 功能 | 接口地址 | 域名 | 调用方式 | 备注 |
|------|---------|------|---------|------|
| QQ音乐歌曲搜索 | `/cgi-bin/musicu.fcg` | `u.y.qq.com` | JSONP | 模块: `music.search.SearchCgiService` / `DoSearchForQQMusicDesktop` |
| 网易云歌曲搜索 | `/music/cloudsearch?type=1` | `apis.netstart.cn` | fetch (CORS) | 默认type=1(单曲) |
| 网易云歌单搜索 | `/music/cloudsearch?type=1000` | `apis.netstart.cn` | fetch (CORS) | |
| 网易云播客搜索 | `/music/cloudsearch?type=1009` | `apis.netstart.cn` | fetch (CORS) | 返回djRadios数组 |

**搜索类型 type 值参考（网易云 cloudsearch）：**
- 1: 单曲
- 10: 专辑
- 100: 歌手
- 1000: 歌单
- 1002: 用户
- 1004: MV
- 1006: 歌词
- 1009: 电台/播客
- 1014: 视频
- 1018: 综合
- 2000: 声音

### 2.2 播放URL获取

| 来源 | 策略1（优先） | 策略2（回退） |
|------|-------------|-------------|
| QQ音乐 | `u.y.qq.com/cgi-bin/musicu.fcg` (JSONP) | `api.injahow.cn/meting/?server=tencent&type=url` |
| 网易云 | — | `api.injahow.cn/meting/?server=netease&type=url` |

**QQ音乐播放URL获取详情：**
- 模块：`vkey.GetVkeyServer` / `CgiGetVkey`
- 音质回退顺序：用户选择 → 320kbps MP3 → 128kbps MP3 → M4A → 64kbps OGG
- 格式前缀：`M800`(320k) / `M500`(128k) / `C400`(m4a) / `F000`(flac) / `O400`(ogg)
- 播放域名：`dl.stream.qqmusic.qq.com`
- 获取URL后会用 Audio 对象验证可播放性

### 2.3 歌词

| 来源 | 策略1 | 策略2 | 策略3 |
|------|-------|-------|-------|
| 网易云 | `apis.netstart.cn/music/lyric?id=` | `api.injahow.cn/meting/?server=netease&type=lrc` | 模拟歌词数据 |
| QQ音乐 | `u.y.qq.com/cgi-bin/musicu.fcg` (GetPlayLyricInfo) | `api.injahow.cn/meting/?server=tencent&type=lrc` | 模拟歌词数据 |

### 2.4 歌单/电台导入

| 来源 | API | 备注 |
|------|-----|------|
| QQ音乐歌单 | `api.injahow.cn/meting/?server=tencent&type=playlist` | 回退: JSONP `c.y.qq.com` |
| 网易云歌单 | `api.injahow.cn/meting/?server=netease&type=playlist` | |
| 网易云歌单(搜索导入) | `apis.netstart.cn/music/playlist/track/all?id=` | 从搜索结果直接导入 |
| 网易云电台/播客 | `apis.netstart.cn/music/dj/program?rid=` | 分页获取，每期作为独立歌曲 |
| 网易云电台详情 | `apis.netstart.cn/music/dj/detail?rid=` | 获取电台名称等信息 |

---

## 三、第三方 API 服务依赖

| 服务 | 域名 | 用途 | CORS | 稳定性 |
|------|------|------|------|--------|
| QQ音乐官方 | `u.y.qq.com` | 搜索、播放URL、歌词 | JSONP | ⭐⭐⭐ 稳定 |
| QQ音乐官方 | `c.y.qq.com` | 歌单导入(回退) | JSONP | ⭐⭐ 偶尔限制 |
| 网易云代理 | `apis.netstart.cn` | 搜索、歌词、歌单详情、电台 | ✅ 支持 | ⭐⭐⭐ 稳定 |
| Meting API | `api.injahow.cn` | 播放URL、歌词、歌单导入 | ✅ 支持 | ⭐⭐ 偶尔慢 |
| QQ音乐CDN | `y.gtimg.cn` | 专辑封面图片 | ✅ | ⭐⭐⭐ |
| QQ音乐流媒体 | `dl.stream.qqmusic.qq.com` | 音频文件播放 | ✅ | ⭐⭐⭐ |

---

## 四、已实现功能清单

### 搜索
- [x] QQ音乐歌曲搜索（musicu.fcg 统一接口）
- [x] 网易云歌曲搜索（cloudsearch）
- [x] 聚合搜索（QQ+网易云并行，合并去重）
- [x] 网易云歌单搜索（type=1000）
- [x] 网易云播客/电台搜索（type=1009）
- [x] 搜索类型切换Tab（歌曲/歌单/播客）
- [x] 搜索防抖（500ms）

### 播放
- [x] QQ音乐播放（多音质回退 + URL验证）
- [x] 网易云播放（Meting API）
- [x] 播放/暂停/上一首/下一首
- [x] 进度条拖拽
- [x] 迷你播放器 + 全屏播放器
- [x] 歌词滚动同步

### 歌单管理
- [x] 创建/删除/重命名歌单
- [x] 添加歌曲到歌单
- [x] 从歌单移除歌曲
- [x] QQ音乐歌单导入（链接/ID）
- [x] 网易云歌单导入（链接/ID）
- [x] 网易云电台/播客导入（链接/ID，每期作为独立歌曲）
- [x] 搜索结果一键导入歌单
- [x] 搜索结果一键导入播客

### 其他
- [x] QQ号登录（同步头像昵称）
- [x] 播放历史记录
- [x] 歌曲下载
- [x] 音质选择
- [x] 数据持久化（localStorage）
- [x] 来源标识（QQ/网易云图标badge）

---

## 五、已知问题与限制

| 问题 | 原因 | 状态 |
|------|------|------|
| 网易云播放部分歌曲失败 | Meting API 无VIP Cookie，付费歌曲无法获取URL | 需提供 MUSIC_U Cookie |
| 酷我搜索（PHP后端）返回空 | kw_token 过期，需动态获取 | 已修复代码，待上传服务器 |
| 部分QQ音乐VIP歌曲无法播放 | 官方API对VIP歌曲返回空purl | 回退到Meting，仍可能失败 |
| 网易云电台付费节目 | radioFeeType=2 的节目需付费 | 前端已标注"付费"标签 |

---

## 六、PHP后端（xm486.kesug.com）状态

已部署但**当前前端主要直连第三方API**，PHP后端作为备用/增强方案。

**已验证可用的接口：**
- ✅ `?action=url&source=kuwo&id=xxx` — 酷我播放URL（320k MP3）
- ✅ `?action=detail&source=kuwo&id=xxx` — 酷我歌曲详情
- ✅ `?action=lyric&source=kuwo&id=xxx` — 酷我歌词（LRC格式）
- ✅ `?action=search&source=netease&keywords=xxx` — 网易云搜索

**待修复（需重新上传 index.php）：**
- ❌ `?action=search&source=kuwo` — 需要动态 kw_token（代码已修复，未上传）
- ❌ `?action=url&source=netease` — 需要有效 MUSIC_U Cookie

---

## 七、文件结构

```
ciyuan_music/
├── index.html          ← 主文件（前端全部代码，~254KB，~5900行）
├── .gitignore
├── API_INTEGRATION_PLAN.md  ← 早期API集成计划（可能过时）
├── api_update.md            ← 早期更新记录
└── TECHNICAL_SUMMARY.md     ← 本文件
```

---

## 八、关键技术决策记录

1. **为什么用 JSONP 而不是 fetch 调用QQ音乐？**
   - QQ音乐官方API不支持CORS，但支持JSONP callback
   - 前端纯静态部署，无法做服务端代理

2. **为什么搜索从 c.y.qq.com 切换到 u.y.qq.com？**
   - `c.y.qq.com/soso/fcgi-bin/search_cp` 对外部调用有频率/Referer限制
   - `u.y.qq.com/cgi-bin/musicu.fcg` 是QQ音乐统一数据接口，和播放URL获取同域名，限制策略更宽松
   - 搜索+播放走同一域名，避免"搜索被限但播放正常"的不一致问题

3. **为什么网易云用 apis.netstart.cn 而不是自建后端？**
   - netstart.cn 是 NeteaseCloudMusicApi 的公共部署实例，支持CORS
   - 免费、稳定、无需维护
   - 前端直连，无需经过用户的PHP后端

4. **为什么电台导入把每期节目当独立歌曲？**
   - 用户的使用场景是"个人电台"（每期就是分享一首歌）
   - mainSong.id 就是网易云标准歌曲ID，可以正常通过 Meting 获取播放URL
   - 导入后和普通歌单行为一致，每首歌独立播放
