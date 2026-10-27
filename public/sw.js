// No caches live here, so a new version can take over open pages at once.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let payload = {};

  if (event.data) {
    try {
      payload = event.data.json();
    } catch {
      payload = { title: 'Vora', body: event.data.text() };
    }
  }

  const title = payload.title || 'Vora';
  const options = {
    body: payload.body || '',
    icon: payload.icon || '/favicon.ico',
    badge: payload.badge || '/favicon.ico',
    data: payload.data || {},
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const targetUrl = event.notification.data?.url || '/';
  const absoluteUrl = new URL(targetUrl, self.location.origin).href;

  event.waitUntil((async () => {
    const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clientList) {
      if (client.url === absoluteUrl && 'focus' in client) {
        return client.focus();
      }
    }

    return clients.openWindow(absoluteUrl);
  })());
});

// Android share sheet: the web app manifest's share_target posts shared photos
// and videos here. Keep them in IndexedDB for the gallery to pick up, then open
// it. Names match resources/js/events/sharedFiles.ts.
const SHARE_PATH = '/events/share';

function openShareDatabase() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('vora-share-target', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('shares');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function keepSharedFiles(files) {
  const db = await openShareDatabase();
  try {
    await new Promise((resolve, reject) => {
      const transaction = db.transaction('shares', 'readwrite');
      transaction.objectStore('shares').put(files, 'pending');
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
  } finally {
    db.close();
  }
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'POST' || url.origin !== self.location.origin || url.pathname !== SHARE_PATH) return;

  event.respondWith((async () => {
    try {
      const form = await event.request.formData();
      const files = form.getAll('media').filter((value) => value instanceof File && value.size > 0);
      await keepSharedFiles(files);
      return Response.redirect('/events?shared=1', 303);
    } catch {
      return Response.redirect('/events', 303);
    }
  })());
});
