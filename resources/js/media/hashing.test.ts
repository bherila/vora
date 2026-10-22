import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex } from '@noble/hashes/utils.js';

import { computeFileHash } from '@/media/imageProcessing';

// Use tiny backing slices with a reported large size: verify streaming without
// allocating a multi-gigabyte file or ever reading the original in one go.
function chunkedFile(chunks: Uint8Array<ArrayBuffer>[]): File {
  return {
    size: chunks.length * 8 * 1024 * 1024,
    arrayBuffer: jest.fn(() => { throw new Error('Whole-file read forbidden'); }),
    slice: jest.fn((offset: number) => ({
      arrayBuffer: async () => chunks[offset / (8 * 1024 * 1024)]!.buffer,
    })),
  } as unknown as File;
}

describe('bounded-memory file hashing', () => {
  const realCrypto = globalThis.crypto;
  afterEach(() => {
    Object.defineProperty(globalThis, 'crypto', { value: realCrypto, configurable: true });
    jest.restoreAllMocks();
  });

  it('hashes files above the old 512 MiB cap using bounded slices', async () => {
    const chunks = Array.from({ length: 65 }, (_, i) => new Uint8Array([i, i + 1]));
    const file = chunkedFile(chunks);
    const progress: number[] = [];
    const digest = await computeFileHash(file, (fraction) => progress.push(fraction));
    expect(digest).toBe(bytesToHex(sha256(new Uint8Array(chunks.flatMap((chunk) => Array.from(chunk))))));
    expect(file.arrayBuffer).not.toHaveBeenCalled();
    expect(file.slice).toHaveBeenCalledTimes(65);
    expect(progress).toHaveLength(65);
    expect(progress.every((value) => value > 0 && value <= 1)).toBe(true);
  });

  it('uses Web Crypto for small files when available', async () => {
    const data = new Uint8Array([1, 2, 3]);
    const digest = jest.fn().mockResolvedValue(sha256(data).buffer);
    Object.defineProperty(globalThis, 'crypto', { value: { subtle: { digest } }, configurable: true });
    const progress = jest.fn();
    const file = { size: 3, arrayBuffer: async () => data.buffer } as File;
    await expect(computeFileHash(file, progress)).resolves.toBe(bytesToHex(sha256(data)));
    expect(digest).toHaveBeenCalledWith('SHA-256', data.buffer);
    expect(progress).toHaveBeenCalledWith(1);
  });

  it('streams small files when Web Crypto is unavailable', async () => {
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });
    const file = chunkedFile([new Uint8Array([1, 2, 3])]);
    await expect(computeFileHash(file)).resolves.toBe(bytesToHex(sha256(new Uint8Array([1, 2, 3]))));
  });

  it('stops between chunks when canceled instead of silently skipping hashing', async () => {
    const controller = new AbortController();
    const file = chunkedFile(Array.from({ length: 9 }, () => new Uint8Array([1])));
    await expect(computeFileHash(file, () => controller.abort(), controller.signal))
      .rejects.toMatchObject({ name: 'AbortError' });
    expect(file.slice).toHaveBeenCalledTimes(1);
  });

  it('returns null if the file cannot be read', async () => {
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });
    const file = { size: 1, slice: () => { throw new Error('File unavailable'); } } as unknown as File;
    await expect(computeFileHash(file)).resolves.toBeNull();
  });
});
