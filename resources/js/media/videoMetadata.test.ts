import { readVideoCaptureTime } from './videoMetadata';

const QUICKTIME_EPOCH_OFFSET = 2_082_844_800;

// jsdom's Blob lacks arrayBuffer(); browsers that upload here (Safari 14+) have it.
beforeAll(() => {
  if (typeof Blob.prototype.arrayBuffer === 'function') return;
  Object.defineProperty(Blob.prototype, 'arrayBuffer', {
    configurable: true,
    value(this: Blob): Promise<ArrayBuffer> {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as ArrayBuffer);
        reader.onerror = () => reject(reader.error);
        reader.readAsArrayBuffer(this);
      });
    },
  });
});

function box(type: string, body: Uint8Array): Uint8Array {
  const bytes = new Uint8Array(8 + body.length);
  new DataView(bytes.buffer).setUint32(0, bytes.length);
  bytes.set(Array.from(type, (char) => char.charCodeAt(0)), 4);
  bytes.set(body, 8);
  return bytes;
}

function mvhd(unixSeconds: number, version: 0 | 1 = 0): Uint8Array {
  const body = new Uint8Array(version === 1 ? 32 : 20);
  const view = new DataView(body.buffer);
  view.setUint8(0, version);
  if (version === 1) view.setBigUint64(4, BigInt(unixSeconds + QUICKTIME_EPOCH_OFFSET));
  else view.setUint32(4, unixSeconds + QUICKTIME_EPOCH_OFFSET);
  return box('mvhd', body);
}

function movie(...boxes: Uint8Array[]): Blob {
  return new Blob(boxes.map((part) => part.slice().buffer));
}

const capture = Date.UTC(2026, 9, 24, 23, 0, 0) / 1000;
const signal = (): AbortSignal => new AbortController().signal;

it('reads the movie header after the media data, as iPhone recordings store it', async () => {
  const file = movie(box('ftyp', new Uint8Array(12)), box('mdat', new Uint8Array(4096)), box('moov', new Uint8Array([...box('udta', new Uint8Array(4)), ...mvhd(capture)])));
  expect(await readVideoCaptureTime(file, signal())).toBe('2026-10-24T23:00:00Z');
});

it('supports 64-bit headers and treats zeroed or missing times as unknown', async () => {
  expect(await readVideoCaptureTime(movie(box('moov', mvhd(capture, 1))), signal())).toBe('2026-10-24T23:00:00Z');
  expect(await readVideoCaptureTime(movie(box('moov', mvhd(-QUICKTIME_EPOCH_OFFSET))), signal())).toBeNull();
  expect(await readVideoCaptureTime(movie(box('ftyp', new Uint8Array(12)), box('mdat', new Uint8Array(16))), signal())).toBeNull();
  expect(await readVideoCaptureTime(new Blob(['not a movie']), signal())).toBeNull();
});

it('stops when cancelled', async () => {
  const controller = new AbortController();
  controller.abort();
  await expect(readVideoCaptureTime(movie(box('moov', mvhd(capture))), controller.signal)).rejects.toMatchObject({ name: 'AbortError' });
});
