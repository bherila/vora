import { readPhotoMetadata } from './photoMetadata';

/** A tiny JPEG metadata segment: Make, capture time and GPS, no image pixels. */
function jpegExif(): File {
  const tiff = new Uint8Array(256);
  const view = new DataView(tiff.buffer);
  const word = (offset: number, value: number): void => view.setUint16(offset, value, true);
  const long = (offset: number, value: number): void => view.setUint32(offset, value, true);
  const ascii = (offset: number, value: string): void => { for (let i = 0; i < value.length; i++) tiff[offset + i] = value.charCodeAt(i); };
  const entry = (offset: number, tag: number, type: number, count: number, value: number): void => { word(offset, tag); word(offset + 2, type); long(offset + 4, count); long(offset + 8, value); };
  ascii(0, 'II'); word(2, 42); long(4, 8); word(8, 3);
  entry(10, 0x010f, 2, 6, 64); ascii(64, 'Canon\0');
  entry(22, 0x8769, 4, 1, 80); entry(34, 0x8825, 4, 1, 120);
  word(80, 1); entry(82, 0x9003, 2, 20, 180); ascii(180, '2026:10:22 12:34:56\0');
  word(120, 4); entry(122, 1, 2, 2, 78); entry(134, 2, 5, 3, 208);
  entry(146, 3, 2, 2, 87); entry(158, 4, 5, 3, 232);
  [37, 46, 30, 122, 25, 10].forEach((value, index) => { long(208 + index * 8, value); long(212 + index * 8, 1); });
  const jpeg = new Uint8Array(2 + 4 + 6 + tiff.length + 2);
  jpeg.set([255, 216, 255, 225, 1, 8, 69, 120, 105, 102, 0, 0]);
  jpeg.set(tiff, 12); jpeg.set([255, 217], jpeg.length - 2);
  return new File([jpeg], 'camera.jpg', { type: 'image/jpeg' });
}

it('extracts summary flags and the wall-clock capture time with the real EXIF parser while retaining every original byte', async () => {
  const file = jpegExif();
  const summary = await readPhotoMetadata(file, new AbortController().signal);
  expect(summary).toEqual({ has_gps: true, has_capture_time: true, has_camera: true, captured_at: '2026-10-22T12:34:56' });
  expect(file.size).toBe(270);
  expect(Object.keys(summary ?? {})).toEqual(['has_gps', 'has_capture_time', 'has_camera', 'captured_at']);
});
