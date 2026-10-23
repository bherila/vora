import { createHeifDecoderWorker } from '@/media/heifDecoderFactory';

/** Lazy fallback: a dedicated worker can be terminated on cancellation or timeout. */
export async function decodeHeifInWorker(file: Blob, signal: AbortSignal): Promise<ImageBitmap> {
  signal.throwIfAborted();
  let worker: Worker;
  try { worker = createHeifDecoderWorker(); } catch { throw new Error('Could not prepare this HEIC/HEIF photo in this browser. Export it as JPEG and try again.'); }
  return await new Promise<ImageBitmap>((resolve, reject) => {
    const timeout = setTimeout(() => finish(new Error('Preparing this HEIC/HEIF photo took too long. Export it as JPEG and try again.')), 20000);
    const cleanup = (): void => { clearTimeout(timeout); signal.removeEventListener('abort', aborted); worker.onmessage = null; worker.onerror = null; worker.terminate(); };
    const finish = (error: unknown): void => { cleanup(); reject(error); };
    const aborted = (): void => finish(signal.reason ?? new DOMException('Upload canceled.', 'AbortError'));
    worker.onmessage = (event: MessageEvent<{ bitmap?: ImageBitmap; error?: string }>) => {
      const bitmap = event.data.bitmap;
      cleanup();
      if (bitmap !== undefined && !signal.aborted) resolve(bitmap);
      else { bitmap?.close(); reject(signal.aborted ? signal.reason : new Error('Could not decode this HEIC/HEIF photo. Export it as JPEG and try again.')); }
    };
    worker.onerror = () => finish(new Error('Could not prepare this HEIC/HEIF photo in this browser. Export it as JPEG and try again.'));
    signal.addEventListener('abort', aborted, { once: true });
    if (signal.aborted) { aborted(); return; }
    try { worker.postMessage({ file }); } catch { finish(new Error('Could not prepare this HEIC/HEIF photo in this browser. Export it as JPEG and try again.')); }
  });
}
