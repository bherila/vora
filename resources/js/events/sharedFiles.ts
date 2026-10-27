/**
 * Files shared to the installed app from Android's share sheet. The service
 * worker (public/sw.js) receives the share POST and keeps the files here, in
 * IndexedDB, until the gallery opens and takes them. Keep these names in step
 * with public/sw.js.
 */
const DB_NAME = 'vora-share-target';
const STORE = 'shares';
const KEY = 'pending';

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB unavailable'));
  });
}

/** Read and clear the pending share, so each share is offered once. */
export async function takeSharedFiles(): Promise<File[]> {
  if (typeof indexedDB === 'undefined') return [];
  const db = await openDatabase();
  try {
    return await new Promise<File[]>((resolve, reject) => {
      const transaction = db.transaction(STORE, 'readwrite');
      const store = transaction.objectStore(STORE);
      const read = store.get(KEY);
      let files: File[] = [];
      read.onsuccess = () => {
        const value: unknown = read.result;
        files = Array.isArray(value) ? value.filter((file): file is File => file instanceof Blob && 'name' in file) : [];
        store.delete(KEY);
      };
      transaction.oncomplete = () => resolve(files);
      transaction.onerror = () => reject(transaction.error ?? new Error('Shared files could not be read'));
    });
  } finally {
    db.close();
  }
}

/** Register the worker that receives shares; harmless where unsupported. */
export function registerShareTarget(): void {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker.register('/sw.js').catch(() => undefined);
}
