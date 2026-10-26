/** Seconds between the QuickTime/MP4 epoch (1904-01-01) and the Unix epoch. */
const QUICKTIME_EPOCH_OFFSET = 2_082_844_800;
const MAX_BOXES = 64;
const EARLIEST = Date.UTC(1990, 0, 1);

interface Box {
  start: number;
  headerSize: number;
  size: number;
}

async function read(file: Blob, start: number, length: number, signal: AbortSignal): Promise<DataView> {
  signal.throwIfAborted();
  return new DataView(await file.slice(start, start + length).arrayBuffer());
}

/** Walk sibling box headers only; a long video's media data is skipped, never read. */
async function findBox(file: Blob, start: number, end: number, type: string, signal: AbortSignal): Promise<Box | null> {
  let offset = start;
  for (let index = 0; index < MAX_BOXES && offset + 8 <= end; index++) {
    const header = await read(file, offset, 16, signal);
    if (header.byteLength < 8) return null;
    let size = header.getUint32(0);
    const name = String.fromCharCode(header.getUint8(4), header.getUint8(5), header.getUint8(6), header.getUint8(7));
    let headerSize = 8;
    if (size === 1) {
      if (header.byteLength < 16) return null;
      size = Number(header.getBigUint64(8));
      headerSize = 16;
    } else if (size === 0) {
      size = end - offset;
    }
    if (size < headerSize) return null;
    if (name === type) return { start: offset, headerSize, size };
    offset += size;
  }
  return null;
}

/**
 * Read the movie header's creation time (UTC) from an MP4 or QuickTime file.
 * Best effort: anything missing, zeroed or implausible is unknown.
 */
export async function readVideoCaptureTime(file: Blob, signal: AbortSignal): Promise<string | null> {
  try {
    const movie = await findBox(file, 0, file.size, 'moov', signal);
    if (movie === null) return null;
    const header = await findBox(file, movie.start + movie.headerSize, movie.start + movie.size, 'mvhd', signal);
    if (header === null) return null;
    const fields = await read(file, header.start + header.headerSize, 12, signal);
    if (fields.byteLength < 8) return null;
    const version = fields.getUint8(0);
    if (version === 1 && fields.byteLength < 12) return null;
    const seconds = version === 1 ? Number(fields.getBigUint64(4)) : fields.getUint32(4);
    const milliseconds = (seconds - QUICKTIME_EPOCH_OFFSET) * 1000;
    if (!Number.isFinite(milliseconds) || milliseconds < EARLIEST || milliseconds > Date.now() + 86_400_000) return null;
    return new Date(milliseconds).toISOString().replace(/\.\d{3}Z$/, 'Z');
  } catch {
    signal.throwIfAborted();
    return null;
  }
}
