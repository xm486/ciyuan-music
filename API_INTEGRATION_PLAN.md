# 次元音乐 - 完整API集成计划

## 可用的音乐API源

### 方案A: QQ音乐 API (推荐)
**优势**: 无需代理，可直接在浏览器中调用（通过JSONP或CORS）
**来源**: [copws/qq-music-api](https://github.com/copws/qq-music-api) (2025.9验证可用)

### 方案B: 网易云音乐 API
**劣势**: 需要后端代理，无法直接在浏览器调用
**来源**: [NeteaseCloudMusicApi](https://github.com/NeteaseCloudMusicApiEnhanced/api-enhanced)

---

## 实施计划

### 第1步: 实现真实歌词获取 ✅

```javascript
// 获取歌词
async fetchLyrics(songmid) {
    try {
        const url = `https://i.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?songmid=${songmid}&format=json&inCharset=utf-8&outCharset=utf-8&nobase64=1`;
        
        // 使用JSONP避免CORS
        return new Promise((resolve, reject) => {
            const callbackName = 'LyricCb' + Date.now();
            const timeout = setTimeout(() => {
                delete window[callbackName];
                script.remove();
                reject(new Error('timeout'));
            }, 5000);
            
            window[callbackName] = (data) => {
                clearTimeout(timeout);
                delete window[callbackName];
                script.remove();
                
                if (data && data.lyric) {
                    resolve(this.parseLyricText(data.lyric));
                } else {
                    resolve([]);
                }
            };
            
            const script = document.createElement('script');
            script.src = `${url}&jsonpCallback=${callbackName}`;
            script.onerror = () => {
                clearTimeout(timeout);
                delete window[callbackName];
                script.remove();
                reject(new Error('load failed'));
            };
            document.head.appendChild(script);
        });
    } catch (error) {
        console.error('获取歌词失败:', error);
        return [];
    }
}

// 解析LRC歌词
parseLyricText(lyricText) {
    const lines = lyricText.split('\n');
    const result = [];
    
    for (const line of lines) {
        const match = line.match(/\[(\d{2}):(\d{2})\.(\d{2,3})\](.*)/);
        if (match) {
            const minutes = parseInt(match[1]);
            const seconds = parseInt(match[2]);
            const ms = parseInt(match[3].padEnd(3, '0'));
            const time = minutes * 60 + seconds + ms / 1000;
            const text = match[4].trim();
            
            if (text && !text.startsWith('[')) {
                result.push({ time, text });
            }
        }
    }
    
    return result.sort((a, b) => a.time - b.time);
}
```

### 第2步: 优化播放URL获取 ✅

```javascript
// 尝试多种音质格式
async getSongUrl(songmid, preferredQuality = '320') {
    const qualityOrder = {
        'flac': ['F000', '.flac'],
        '320': ['M800', '.mp3'],
        '128': ['M500', '.mp3'],
        'm4a': ['C400', '.m4a']
    };
    
    // 先尝试首选音质，再降级
    const qualities = [preferredQuality, '320', '128', 'm4a'];
    
    for (const q of qualities) {
        const [prefix, ext] = qualityOrder[q];
        const url = await this.fetchSongUrl(songmid, prefix, ext);
        if (url) return url;
    }
    
    return null;
}

// 获取特定格式的URL (使用新版API格式)
fetchSongUrl(songmid, prefix, ext) {
    return new Promise((resolve) => {
        const callbackName = 'MusicuCb' + Date.now() + Math.floor(Math.random()*1000);
        const filename = `${prefix}${songmid}${ext}`;
        
        const timeout = setTimeout(() => {
            delete window[callbackName];
            resolve(null);
        }, 3000);
        
        window[callbackName] = (data) => {
            clearTimeout(timeout);
            delete window[callbackName];
            
            if (data?.req_1?.data?.sip?.[0] && data.req_1.data.midurlinfo?.[0]?.purl) {
                const url = data.req_1.data.sip[0] + data.req_1.data.midurlinfo[0].purl;
                resolve(url.replace('http://', 'https://'));
            } else {
                resolve(null);
            }
        };
        
        const body = JSON.stringify({
            req_1: {
                module: "vkey.GetVkeyServer",
                method: "CgiGetVkey",
                param: {
                    filename: [filename],
                    guid: "10000",
                    songmid: [songmid],
                    songtype: [0],
                    uin: "0",
                    loginflag: 1,
                    platform: "20"
                }
            },
            comm: { uin: "0", format: "json", ct: 24, cv: 0 }
        });
        
        const script = document.createElement('script');
        script.src = `https://u.y.qq.com/cgi-bin/musicu.fcg?callback=${callbackName}&data=${encodeURIComponent(body)}`;
        script.onerror = () => {
            clearTimeout(timeout);
            delete window[callbackName];
            resolve(null);
        };
        document.head.appendChild(script);
    });
}
```

### 第3步: 添加音质选择功能

```html
<!-- 音质选择UI -->
<div class="quality-selector">
    <button class="quality-btn" data-quality="128">标准</button>
    <button class="quality-btn active" data-quality="320">高品</button>
    <button class="quality-btn" data-quality="flac">无损</button>
</div>
```

### 第4步: 歌单导入优化

```javascript
// 通过歌单ID导入QQ音乐歌单
async importPlaylist(disstid) {
    const url = `https://i.y.qq.com/qzone-music/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?disstid=${disstid}&format=json&utf8=1`;
    
    // 使用JSONP
    return new Promise((resolve, reject) => {
        const callbackName = 'PlaylistCb' + Date.now();
        
        window[callbackName] = (data) => {
            delete window[callbackName];
            if (data?.cdlist?.[0]) {
                const playlist = data.cdlist[0];
                resolve({
                    name: playlist.dissname,
                    songs: playlist.songlist.map(s => ({
                        name: s.songname,
                        artist: s.singer.map(a => a.name).join('/'),
                        albummid: s.albummid,
                        songmid: s.songmid
                    }))
                });
            } else {
                reject(new Error('获取歌单失败'));
            }
        };
        
        const script = document.createElement('script');
        script.src = `${url}&jsonpCallback=${callbackName}`;
        document.head.appendChild(script);
    });
}
```

---

## 完整功能清单

### ✅ 已实现
- [x] 播放URL获取（多格式回退）
- [x] 歌曲搜索（JSONP）
- [x] 模拟歌词显示

### 🔧 需要集成
- [ ] 真实歌词API
- [ ] 音质选择功能
- [ ] 歌单ID导入
- [ ] 专辑封面获取

### 🎯 优先级
1. **高**: 真实歌词 - 提升用户体验
2. **高**: 音质选择 - 基础功能
3. **中**: 歌单导入 - 扩展功能
4. **低**: 专辑信息 - 增强功能

---

## 技术注意事项

1. **JSONP限制**: 需要动态创建script标签，不能使用fetch
2. **回调函数名**: 必须唯一，建议使用时间戳+随机数
3. **超时处理**: 每个请求设置5秒超时
4. **错误处理**: 优雅降级，失败时使用模拟数据
5. **频率控制**: 避免短时间内大量请求

---

## 下一步行动

立即集成真实歌词API，然后添加音质选择功能。
