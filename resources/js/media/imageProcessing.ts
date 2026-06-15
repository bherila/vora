/**
 * Client-side derivative generation for media uploads.
 *
 * Thumbnails (and video poster frames) are produced in the browser so the app
 * server never decodes images. Downscaling uses a stepped OffscreenCanvas
 * (recursive halving with high-quality smoothing), which avoids the aliasing a
 * single-pass drawImage produces for large reductions — no third-party resize
 * library required. Photos additionally get a perceptual (blockhash) hash for
 * future near-duplicate detection in exploration/search.
 */

import { bmvbhash } from 'blockhash-core';

/** Longest-edge bound for the generated thumbnail/poster JPEG. */
const THUMBNAIL_MAX_EDGE = 480;
const THUMBNAIL_QUALITY = 0.78;

/** Blocks per side for the perceptual hash: 16×16 = 256 bits = 32 bytes. */
const PHASH_BITS = 16;

/**
 * Cap the canvas used for hashing. blockhash is scale-invariant, so bounding to
 * 256px avoids allocating a width×height×4 RGBA buffer for large originals.
 */
const PHASH_MAX_EDGE = 256;

export interface PhotoDerivatives {
  /** JPEG thumbnail. */
  thumbnail: Blob;
  /** Base64-encoded 32-byte perceptual hash of the normalized image. */
  perceptualHash: string;
}

/**
 * Whether the browser supports the APIs this module needs. Callers should treat
 * derivative generation as best-effort and upload without a thumbnail when this
 * is false (older Safari lacks OffscreenCanvas.convertToBlob).
 */
export function supportsClientDerivatives(): boolean {
  return (
    typeof createImageBitmap === 'function' &&
    typeof OffscreenCanvas === 'function' &&
    typeof new OffscreenCanvas(1, 1).convertToBlob === 'function'
  );
}

/**
 * Generate a thumbnail and perceptual hash for an image file. Both are derived
 * from a single decode of the source bitmap.
 */
export async function generatePhotoDerivatives(file: File): Promise<PhotoDerivatives> {
  const bitmap = await createImageBitmap(file);
  try {
    const [thumbnail, perceptualHash] = await Promise.all([
      resizeToMaxEdge(bitmap, THUMBNAIL_MAX_EDGE, THUMBNAIL_QUALITY),
      computePerceptualHash(bitmap),
    ]);
    return { thumbnail, perceptualHash };
  } finally {
    bitmap.close();
  }
}

/**
 * Capture a poster frame from a video file as a JPEG thumbnail. Seeks a little
 * past the start to skip black leader frames. Resolves null if the video can't
 * be decoded/drawn (e.g. an unsupported codec), so the caller falls back to a
 * posterless upload.
 */
export async function generateVideoPoster(file: File): Promise<Blob | null> {
  const url = URL.createObjectURL(file);
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;
  video.preload = 'auto';
  video.src = url;

  try {
    await waitForEvent(video, 'loadedmetadata');
    // A short offset avoids an all-black first frame; clamp to the duration.
    const target = Number.isFinite(video.duration) ? Math.min(0.5, video.duration / 2) : 0;
    video.currentTime = target;
    await waitForEvent(video, 'seeked');

    const { videoWidth: w, videoHeight: h } = video;
    if (w === 0 || h === 0) {
      return null;
    }

    const [targetWidth, targetHeight] = fitWithin(w, h, THUMBNAIL_MAX_EDGE);
    const canvas = new OffscreenCanvas(targetWidth, targetHeight);
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return null;
    }
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, 0, 0, targetWidth, targetHeight);

    return await canvas.convertToBlob({ type: 'image/jpeg', quality: THUMBNAIL_QUALITY });
  } catch {
    return null;
  } finally {
    video.removeAttribute('src');
    video.load();
    URL.revokeObjectURL(url);
  }
}

/**
 * Compute a 256-bit perceptual hash from an ImageBitmap, returned as a
 * base64-encoded 32-byte string. The bitmap is downscaled to PHASH_MAX_EDGE
 * first to cap memory.
 */
export async function computePerceptualHash(bitmap: ImageBitmap): Promise<string> {
  const [w, h] = fitWithin(bitmap.width, bitmap.height, PHASH_MAX_EDGE);
  const canvas = new OffscreenCanvas(w, h);
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get 2d context from OffscreenCanvas');
  }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bitmap, 0, 0, w, h);
  const imageData = ctx.getImageData(0, 0, w, h);

  // bmvbhash returns a 64-char hex string (256 bits for bits=16).
  const hexHash = bmvbhash(imageData, PHASH_BITS);
  const bytes = new Uint8Array(32);
  for (let i = 0; i < 32; i++) {
    bytes[i] = parseInt(hexHash.substring(i * 2, i * 2 + 2), 16);
  }
  return btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join(''));
}

/**
 * Compute target dimensions that fit `width`×`height` within `maxEdge` on the
 * longest side, preserving aspect ratio and never upscaling.
 */
function fitWithin(width: number, height: number, maxEdge: number): [number, number] {
  if (width <= maxEdge && height <= maxEdge) {
    return [width, height];
  }
  if (width >= height) {
    return [maxEdge, Math.max(1, Math.round((height / width) * maxEdge))];
  }
  return [Math.max(1, Math.round((width / height) * maxEdge)), maxEdge];
}

/**
 * Resize an ImageBitmap to fit within `maxEdge`, downscaling in steps of at most
 * 50% per pass for quality, then encode as JPEG.
 */
async function resizeToMaxEdge(source: ImageBitmap, maxEdge: number, quality: number): Promise<Blob> {
  const [targetWidth, targetHeight] = fitWithin(source.width, source.height, maxEdge);

  let currentWidth = source.width;
  let currentHeight = source.height;
  let current: ImageBitmap | OffscreenCanvas = source;

  while (currentWidth > targetWidth * 2 || currentHeight > targetHeight * 2) {
    const nextWidth = Math.max(targetWidth, Math.floor(currentWidth / 2));
    const nextHeight = Math.max(targetHeight, Math.floor(currentHeight / 2));
    current = drawTo(current, nextWidth, nextHeight);
    currentWidth = nextWidth;
    currentHeight = nextHeight;
  }

  const finalCanvas = drawTo(current, targetWidth, targetHeight);
  return await finalCanvas.convertToBlob({ type: 'image/jpeg', quality });
}

function drawTo(source: ImageBitmap | OffscreenCanvas, width: number, height: number): OffscreenCanvas {
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get 2d context from OffscreenCanvas');
  }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(source, 0, 0, width, height);
  return canvas;
}

function waitForEvent(target: HTMLMediaElement, event: string): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const onDone = (): void => {
      cleanup();
      resolve();
    };
    const onError = (): void => {
      cleanup();
      reject(new Error(`Video failed to ${event}.`));
    };
    const cleanup = (): void => {
      target.removeEventListener(event, onDone);
      target.removeEventListener('error', onError);
    };
    target.addEventListener(event, onDone, { once: true });
    target.addEventListener('error', onError, { once: true });
  });
}
