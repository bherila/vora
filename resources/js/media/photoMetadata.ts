export interface PhotoMetadataSummary {
  has_gps: boolean;
  has_capture_time: boolean;
  has_camera: boolean;
  /** Wall-clock `YYYY-MM-DDTHH:mm:ss`, plus the EXIF offset when the camera recorded one. */
  captured_at: string | null;
}

const CHUNK_BYTES = 65536;
const MAX_READ_BYTES = 8 * CHUNK_BYTES;

/** A metadata-only view of a File. Original upload bytes never pass through it. */
class MetadataFile extends File {
  private remaining = MAX_READ_BYTES;
  exceeded = false;

  constructor(file: File, private readonly signal: AbortSignal) {
    super([file], file.name, { type: file.type, lastModified: file.lastModified });
  }

  override slice(start = 0, end = this.size, contentType?: string): Blob {
    this.signal.throwIfAborted();
    const from = Math.max(0, start < 0 ? this.size + start : Math.min(start, this.size));
    const to = Math.max(from, end < 0 ? this.size + end : Math.min(end, this.size));
    const length = to - from;
    if (length > CHUNK_BYTES || length > this.remaining) { this.exceeded = true; throw new Error('Photo metadata read limit reached.'); }
    this.remaining -= length;
    return super.slice(from, to, contentType);
  }

  override async arrayBuffer(): Promise<ArrayBuffer> {
    this.exceeded = true;
    throw new Error('Photo metadata must use bounded slices.');
  }
}

function hasText(value: unknown): boolean {
  return typeof value === 'string' && value.trim() !== '';
}

const pad = (value: number): string => String(value).padStart(2, '0');

/**
 * Exifr revives EXIF's zone-less wall-clock strings as Dates in the browser's
 * own zone, so the local getters return the camera's digits unchanged.
 */
export function exifCaptureTime(value: unknown, offset: unknown): string | null {
  let wallClock: string | null = null;
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    wallClock = `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
  } else if (typeof value === 'string') {
    const match = value.trim().match(/^(\d{4})[:-](\d{2})[:-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
    if (match) wallClock = `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${match[6]}`;
  }
  if (wallClock === null) return null;
  return typeof offset === 'string' && /^[+-]\d{2}:\d{2}$/.test(offset.trim()) ? `${wallClock}${offset.trim()}` : wallClock;
}

/**
 * Read summary flags plus the capture time, which orders the Event gallery.
 * Coordinates and camera names stay in the original.
 */
export async function readPhotoMetadata(file: File, signal: AbortSignal): Promise<PhotoMetadataSummary | null> {
  signal.throwIfAborted();
  try {
    const { parse } = await import('exifr');
    signal.throwIfAborted();
    const input = new MetadataFile(file, signal);
    // Exifr's published Options omits its supported chunked-reader controls.
    const options = {
      chunked: true, firstChunkSize: CHUNK_BYTES, chunkSize: CHUNK_BYTES, chunkLimit: 5,
      multiSegment: false, makerNote: false, userComment: false,
      xmp: false, icc: false, iptc: false,
      pick: ['GPSLatitude', 'GPSLongitude', 'DateTimeOriginal', 'CreateDate', 'OffsetTimeOriginal', 'OffsetTimeDigitized', 'Make', 'Model'],
    };
    const parsed = await parse(input, options) as Record<string, unknown> | undefined;
    signal.throwIfAborted();
    if (input.exceeded || parsed === undefined) return null;
    const data = parsed;
    const hasGps = (Number.isFinite(data.latitude) && Number.isFinite(data.longitude))
      || (Array.isArray(data.GPSLatitude) && Array.isArray(data.GPSLongitude)
        && data.GPSLatitude.length === 3 && data.GPSLongitude.length === 3
        && [...data.GPSLatitude, ...data.GPSLongitude].every((value) => typeof value === 'number' && Number.isFinite(value)));
    const original = data.DateTimeOriginal !== undefined && data.DateTimeOriginal !== null;
    const capture = original ? data.DateTimeOriginal : data.CreateDate;
    return {
      has_gps: hasGps,
      has_capture_time: (capture instanceof Date && !Number.isNaN(capture.getTime())) || hasText(capture),
      has_camera: hasText(data.Make) || hasText(data.Model),
      captured_at: exifCaptureTime(capture, original ? data.OffsetTimeOriginal : data.OffsetTimeDigitized),
    };
  } catch {
    signal.throwIfAborted();
    return null;
  }
}
