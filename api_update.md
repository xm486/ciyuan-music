# QQ音乐API更新文档

## 来源
项目：[copws/qq-music-api](https://github.com/copws/qq-music-api)
最后更新：2025年9月

## 核心API

### 1. 获取歌曲URL (getMusicURL)
- **URL**: `https://u.y.qq.com/cgi-bin/musicu.fcg`
- **方法**: POST
- **参数**:
  - `songmid`: 歌曲MID (必填)
  - `quality`: 音质 (可选: m4a, 128, 320)
- **返回**: 音频URL

### 2. 搜索歌曲 (searchWithKeyword)
- **URL**: `https://u.y.qq.com/cgi-bin/musicu.fcg`
- **方法**: POST
- **参数**:
  - `keyword`: 关键词 (必填)
  - `searchType`: 类型 (0=歌曲, 2=专辑, 3=歌单, 7=歌词)
  - `resultNum`: 结果数量 (默认50)
  - `pageNum`: 页码 (默认1)
- **返回**: 搜索结果对象

### 3. 获取歌词 (getSongLyric)
- **URL**: `https://i.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg`
- **方法**: GET
- **参数**:
  - `songmid`: 歌曲MID (必填)
  - `parse`: 是否解析歌词 (默认false)
- **返回**: 歌词文本或解析后的对象

### 4. 获取歌单 (getSongList)
- **URL**: `https://i.y.qq.com/qzone-music/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg`
- **方法**: GET
- **参数**:
  - `categoryID`: 歌单ID (必填)
- **返回**: 歌单歌曲列表

### 5. 获取专辑信息 (getAlbumSongList)
- **URL**: `https://i.y.qq.com/v8/fcg-bin/fcg_v8_album_info_cp.fcg`
- **方法**: GET
- **参数**:
  - `albummid`: 专辑MID (必填)
- **返回**: 专辑歌曲列表

### 6. 获取歌手信息 (getSingerInfo)
- **URL**: `https://u.y.qq.com/cgi-bin/musicu.fcg`
- **方法**: POST
- **参数**:
  - `singermid`: 歌手MID (必填)
- **返回**: 歌手详情

## 歌词解析格式
解析后的歌词对象包含：
- `ti`: 标题
- `ar`: 创作者
- `al`: 专辑
- `offset`: 偏移量
- `count`: 歌词数量
- `haveTrans`: 是否有翻译
- `lyric`: 歌词数组，每项包含:
  - `time`: 时间戳
  - `lyric`: 歌词文本
  - `trans`: 翻译文本

## 使用示例

```javascript
// 获取320kbps歌曲URL
const url = await getMusicURL('0029fwQT2xorQj', '320');

// 搜索歌曲
const results = await searchWithKeyword('櫻ノ詩', 0, 10);

// 获取歌词并解析
const lyric = await getSongLyric('0029fwQT2xorQj', true);

// 获取歌单歌曲
const songs = await getSongList('123456');
```

## 注意事项
1. 所有API均支持 `origin` 参数直接返回原始数据
2. 跨域请求需要代理或使用JSONP（当前搜索接口使用JSONP）
3. 2025年9月已验证可用性
