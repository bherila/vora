import { decodeHeifInWorker } from '@/media/heifDecoderClient';

import { heifMimeType, normalizeHeifFile, prepareHeifPreview } from './heifPhoto';

jest.mock('@/media/heifDecoderClient', () => ({ decodeHeifInWorker: jest.fn() }));

function photo(brand = 'heic'): File {
  const header = new Uint8Array(24);
  new DataView(header.buffer).setUint32(0, 24);
  for (const [offset, text] of [[4, 'ftyp'], [8, brand], [16, 'mif1'], [20, brand]] as const) for (let i = 0; i < 4; i++) header[offset + i] = text.charCodeAt(i);
  const file = new File([header], 'photo.HEIC', { type: 'image/heic' });
  Object.defineProperty(file, 'slice', { configurable: true, value: () => ({ arrayBuffer: () => Promise.resolve(header.buffer) }) });
  return file;
}

function bitmap(width = 4000, height = 2000): ImageBitmap {
  return { width, height, close: jest.fn() } as unknown as ImageBitmap;
}

const decode = jest.mocked(decodeHeifInWorker);
const drawImage = jest.fn();
let nativeDecode: jest.Mock;

beforeEach(() => {
  Object.defineProperty(globalThis, 'TextDecoder', { configurable: true, value: class { decode(value: ArrayBuffer): string { return String.fromCharCode(...new Uint8Array(value)); } } });
  nativeDecode = jest.fn().mockRejectedValue(new Error('No native HEIC decoder'));
  Object.defineProperty(globalThis, 'createImageBitmap', { configurable: true, value: nativeDecode });
  Object.defineProperty(globalThis, 'Image', { configurable: true, value: class {
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    set src(value: string) { if (value) queueMicrotask(() => this.onerror?.()); }
  } });
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: jest.fn(() => `blob:${window.location.origin}/heic`) });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
  jest.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ fillRect: jest.fn(), drawImage } as unknown as CanvasRenderingContext2D);
  jest.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(new Blob(['jpeg-preview'], { type: 'image/jpeg' })));
  decode.mockReset().mockResolvedValue(bitmap());
  drawImage.mockClear();
});

afterEach(() => jest.restoreAllMocks());

test('uses exact HEIC/HEIF MIME or unknown-MIME extensions while preserving already-converted JPEG', () => {
  expect(heifMimeType(new File(['x'], 'photo.HEIC'))).toBe('image/heic');
  expect(heifMimeType(new File(['x'], 'photo.heif', { type: 'application/octet-stream' }))).toBe('image/heif');
  const jpeg = new File(['jpeg'], 'photo.HEIC', { type: 'image/jpeg' });
  expect(heifMimeType(jpeg)).toBeNull();
  expect(normalizeHeifFile(jpeg)).toBe(jpeg);
  const original = new File(['original'], 'camera.HEIC', { lastModified: 123 });
  const normalized = normalizeHeifFile(original);
  expect(normalized.type).toBe('image/heic');
  expect([normalized.name, normalized.size, normalized.lastModified]).toEqual([original.name, original.size, 123]);
});

test('decodes unsupported native HEIC in a lazy worker, bounds the JPEG to2048 and frees pixels', async () => {
  const pixels = bitmap(); decode.mockResolvedValue(pixels);
  const preview = await prepareHeifPreview(photo(), new AbortController().signal);
  expect(preview.type).toBe('image/jpeg');
  expect(drawImage).toHaveBeenCalledWith(pixels, 0, 0, 2048, 1024);
  expect(pixels.close).toHaveBeenCalledTimes(1);
  expect(URL.revokeObjectURL).toHaveBeenCalled();
});

test('prefers the browser decoder without starting a fallback worker', async () => {
  const pixels = bitmap(1200, 800); nativeDecode.mockResolvedValue(pixels);
  await prepareHeifPreview(photo(), new AbortController().signal);
  expect(decode).not.toHaveBeenCalled();
  expect(pixels.close).toHaveBeenCalledTimes(1);
});

test('refuses image sequences and malformed headers before decoding', async () => {
  await expect(prepareHeifPreview(photo('hevc'), new AbortController().signal)).rejects.toThrow('image sequences');
  const malformed = photo(); Object.defineProperty(malformed, 'slice', { configurable: true, value: () => ({ arrayBuffer: async () => new ArrayBuffer(1) }) });
  await expect(prepareHeifPreview(malformed, new AbortController().signal)).rejects.toThrow('supported HEIC/HEIF');
  expect(decode).not.toHaveBeenCalled();
});

test('cancellation before preparation starts no decoder', async () => {
  const controller = new AbortController(); controller.abort();
  await expect(prepareHeifPreview(photo(), controller.signal)).rejects.toMatchObject({ name: 'AbortError' });
  expect(decode).not.toHaveBeenCalled();
});


test('serializes full-size image decoding across concurrent preparations', async () => {
  let finishFirst: ((image: ImageBitmap) => void) | undefined;
  nativeDecode.mockImplementationOnce(() => new Promise<ImageBitmap>((resolve) => { finishFirst = resolve; })).mockResolvedValue(bitmap());
  const first = prepareHeifPreview(photo(), new AbortController().signal);
  const second = prepareHeifPreview(photo(), new AbortController().signal);
  await new Promise<void>((resolve) => setTimeout(resolve, 0));
  expect(nativeDecode).toHaveBeenCalledTimes(1);
  finishFirst?.(bitmap());
  await Promise.all([first, second]);
  expect(nativeDecode).toHaveBeenCalledTimes(2);
});
