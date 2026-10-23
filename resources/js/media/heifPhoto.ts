import { safeLocalBlobUrl } from '@/security/dom-url';

const PREVIEW_MAX_EDGE = 2048;
const PREVIEW_MAX_BYTES = 5 * 1024 * 1024;
const SOURCE_MAX_BYTES = 64 * 1024 * 1024;
const MAX_DECODED_PIXELS = 64 * 1024 * 1024;

export function isHeifMimeType(mime: string): boolean {
  return ['image/heic', 'image/heif'].includes(mime.toLowerCase());
}

/** An iPhone may already export JPEG bytes despite the original .HEIC name. */
export function heifMimeType(file: File): 'image/heic' | 'image/heif' | null {
  const mime = file.type.toLowerCase();
  if (mime === 'image/heic' || mime === 'image/heif') return mime;
  if (mime !== '' && mime !== 'application/octet-stream') return null;
  if (/\.heic$/i.test(file.name)) return 'image/heic';
  if (/\.heif$/i.test(file.name)) return 'image/heif';
  return null;
}

export function normalizeHeifFile(file: File): File {
  const mime = heifMimeType(file);
  return mime === null || file.type === mime ? file : new File([file], file.name, { type: mime, lastModified: file.lastModified });
}

async function verifyStillImage(file: File): Promise<void> {
  if (file.size > SOURCE_MAX_BYTES) throw new Error('This HEIC/HEIF photo is too large to prepare on this device. Choose a smaller photo or export it as JPEG.');
  const bytes = await file.slice(0, 4096).arrayBuffer();
  const view = new DataView(bytes);
  const text = new TextDecoder('ascii');
  if (bytes.byteLength < 16 || text.decode(bytes.slice(4, 8)) !== 'ftyp') throw new Error('This file is not a supported HEIC/HEIF photo.');
  const size = view.getUint32(0);
  if (size < 16 || size > bytes.byteLength || size % 4 !== 0) throw new Error('This HEIC/HEIF photo has an invalid file header.');
  const brands = [text.decode(bytes.slice(8, 12))];
  for (let offset = 16; offset < size; offset += 4) brands.push(text.decode(bytes.slice(offset, offset + 4)));
  if (brands.some((brand) => ['msf1', 'hevc', 'hevx'].includes(brand))) throw new Error('HEIC/HEIF image sequences are not supported. Choose a single photo or export it as JPEG.');
  if (!brands.some((brand) => ['heic', 'heix'].includes(brand))) throw new Error('This HEIC/HEIF photo uses an unsupported format. Export it as JPEG and try again.');
}

interface DecodedPhoto {
  source: CanvasImageSource;
  width: number;
  height: number;
  close: () => void;
}

async function nativePhoto(file: File, signal: AbortSignal): Promise<DecodedPhoto> {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file);
      if (signal.aborted) { bitmap.close(); signal.throwIfAborted(); }
      return { source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close() };
    } catch { signal.throwIfAborted(); }
  }
  const raw = URL.createObjectURL(file);
  const url = safeLocalBlobUrl(raw);
  if (url === null) { URL.revokeObjectURL(raw); throw new Error('Local photo preview is unavailable.'); }
  return await new Promise<DecodedPhoto>((resolve, reject) => {
    const image = new Image();
    const timeout = setTimeout(() => fail(new Error('Native HEIC decoding timed out.')), 8000);
    const cleanup = (): void => { clearTimeout(timeout); signal.removeEventListener('abort', aborted); image.onload = null; image.onerror = null; };
    const fail = (error: unknown): void => { cleanup(); image.src = ''; URL.revokeObjectURL(raw); reject(error); };
    const aborted = (): void => fail(signal.reason ?? new DOMException('Upload canceled.', 'AbortError'));
    image.onload = () => { cleanup(); resolve({ source: image, width: image.naturalWidth, height: image.naturalHeight, close: () => { image.src = ''; URL.revokeObjectURL(raw); } }); };
    image.onerror = () => fail(new Error('Native HEIC decoding is unavailable.'));
    signal.addEventListener('abort', aborted, { once: true });
    if (signal.aborted) { aborted(); return; }
    image.src = url;
  });
}

async function decodePhoto(file: File, signal: AbortSignal): Promise<DecodedPhoto> {
  try { return await nativePhoto(file, signal); } catch { signal.throwIfAborted(); }
  const { decodeHeifInWorker } = await import('@/media/heifDecoderClient');
  signal.throwIfAborted();
  const bitmap = await decodeHeifInWorker(file, signal);
  return { source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close() };
}

let previousConversion: Promise<void> = Promise.resolve();

/** Only one conversion holds full-size pixels, even if several dialogs are open. */
export async function prepareHeifPreview(file: File, signal: AbortSignal): Promise<Blob> {
  let release = (): void => {};
  const previous = previousConversion;
  previousConversion = new Promise<void>((resolve) => { release = resolve; });
  await previous;
  try {
    signal.throwIfAborted();
    await verifyStillImage(file);
    const decoded = await decodePhoto(file, signal);
    try {
      signal.throwIfAborted();
      if (decoded.width < 1 || decoded.height < 1 || decoded.width * decoded.height > MAX_DECODED_PIXELS) throw new Error('This HEIC/HEIF photo is too large to prepare on this device. Export it as JPEG and try again.');
      const scale = Math.min(1, PREVIEW_MAX_EDGE / Math.max(decoded.width, decoded.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(decoded.width * scale));
      canvas.height = Math.max(1, Math.round(decoded.height * scale));
      try {
        const context = canvas.getContext('2d');
        if (context === null) throw new Error('Photo previews are not available in this browser.');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(decoded.source, 0, 0, canvas.width, canvas.height);
        const preview = await new Promise<Blob>((resolve, reject) => canvas.toBlob((blob) => {
          if (blob === null) reject(new Error('Could not prepare this HEIC/HEIF photo. Export it as JPEG and try again.'));
          else resolve(blob);
        }, 'image/jpeg', 0.88));
        signal.throwIfAborted();
        if (preview.size < 1 || preview.size > PREVIEW_MAX_BYTES || preview.type !== 'image/jpeg') throw new Error('The HEIC/HEIF preview is too large. Export this photo as JPEG and try again.');
        return preview;
      } finally { canvas.width = 1; canvas.height = 1; }
    } finally { decoded.close(); }
  } finally { release(); }
}
