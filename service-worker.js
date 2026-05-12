const APP_VERSION = '1.1.0';
const CACHE_NAME = `ciyuan-music-static-v${APP_VERSION}`;
const UPDATE_TITLE = '次元音乐 v1.1.0 更新说明';
const UPDATE_NOTES = [
  '新增 PWA 新版本提示，发现更新后可手动点击刷新。',
  '新增设置页“检查更新”入口，可主动检测最新版本。',
  '新增隐私协议入口，完善登录注册合规提示。',
  '新增访问统计能力，后台可查看今日访问人数和累计访问人次。'
];

const STATIC_ASSETS = [
  './',
  './index.html',
  './manifest.json',
  './image/logo.png',
  './image/icon-192.png',
  './image/icon-512.png',
  './image/favicon.ico'
];

function shouldBypassCache(request) {
  const url = new URL(request.url);

  if (request.method !== 'GET') return true;
  if (url.origin !== self.location.origin) return true;
  if (url.pathname.endsWith('.php')) return true;
  if (url.pathname.includes('/api/')) return true;

  return false;
}

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(STATIC_ASSETS))
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }
  if (event.data && event.data.type === 'GET_UPDATE_INFO') {
    const payload = {
      type: 'UPDATE_INFO',
      version: APP_VERSION,
      title: UPDATE_TITLE,
      notes: UPDATE_NOTES
    };
    if (event.source && event.source.postMessage) {
      event.source.postMessage(payload);
    }
  }
});

self.addEventListener('fetch', event => {
  const request = event.request;

  if (shouldBypassCache(request)) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      caches.match('./index.html').then(cached => {
        const network = fetch(request)
          .then(response => {
            if (response && response.status === 200 && response.type === 'basic') {
              const copy = response.clone();
              caches.open(CACHE_NAME).then(cache => cache.put('./index.html', copy));
            }
            return response;
          })
          .catch(() => cached);

        // 有缓存时直接秒开，同时后台更新；没有缓存才等网络。
        return cached || network;
      })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cached => {
      if (cached) return cached;
      return fetch(request).then(response => {
        if (!response || response.status !== 200 || response.type !== 'basic') return response;
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
        return response;
      });
    })
  );
});