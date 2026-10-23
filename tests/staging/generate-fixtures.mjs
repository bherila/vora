import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Invented camera metadata at 0°N, 0°E; no personal media or location data.
function withSyntheticExif(jpeg) {
  const tiff = Buffer.alloc(256);
  const entry = (offset, tag, type, count, value) => {
    tiff.writeUInt16LE(tag, offset); tiff.writeUInt16LE(type, offset + 2);
    tiff.writeUInt32LE(count, offset + 4); tiff.writeUInt32LE(value, offset + 8);
  };
  tiff.write('II'); tiff.writeUInt16LE(42, 2); tiff.writeUInt32LE(8, 4); tiff.writeUInt16LE(3, 8);
  entry(10, 0x010f, 2, 5, 64); tiff.write('Test\0', 64);
  entry(22, 0x8769, 4, 1, 80); entry(34, 0x8825, 4, 1, 120);
  tiff.writeUInt16LE(1, 80); entry(82, 0x9003, 2, 20, 180); tiff.write('2026:10:22 12:34:56\0', 180);
  tiff.writeUInt16LE(4, 120); entry(122, 1, 2, 2, 78); entry(134, 2, 5, 3, 208);
  entry(146, 3, 2, 2, 69); entry(158, 4, 5, 3, 232);
  for (let index = 0; index < 6; index += 1) tiff.writeUInt32LE(1, 212 + index * 8);
  const segment = Buffer.alloc(10);
  segment.set([255, 225]); segment.writeUInt16BE(8 + tiff.length, 2); segment.write('Exif\0\0', 4);
  return Buffer.concat([jpeg.subarray(0, 2), segment, tiff, jpeg.subarray(2)]);
}

export default function generateFixtures() {
  const directory = fileURLToPath(new URL('./.fixtures/', import.meta.url));
  mkdirSync(directory, { recursive: true });
  const common = ['-hide_banner', '-loglevel', 'error', '-y'];
  execFileSync('ffmpeg', [...common, '-f', 'lavfi', '-i', 'color=c=teal:s=320x180', '-frames:v', '1', '-threads', '1', `${directory}/photo.png`], { stdio: 'inherit' });
  copyFileSync(`${directory}/photo.png`, `${directory}/batch-first.png`);
  copyFileSync(`${directory}/photo.png`, `${directory}/batch-last.png`);
  copyFileSync(fileURLToPath(new URL('../browser/fixtures/synthetic.heic', import.meta.url)), `${directory}/photo.heic`);
  const source = fileURLToPath(new URL('../browser/fixtures/synthetic-heic-source.png', import.meta.url));
  execFileSync('ffmpeg', [...common, '-i', source, '-q:v', '2', '-frames:v', '1', '-threads', '1', `${directory}/photo-location.jpg`], { stdio: 'inherit' });
  writeFileSync(`${directory}/photo-location.jpg`, withSyntheticExif(readFileSync(`${directory}/photo-location.jpg`)));
  execFileSync('ffmpeg', [...common, '-i', source, '-vf', 'scale=128:96', '-frames:v', '1', '-threads', '1', `${directory}/photo-larger.png`], { stdio: 'inherit' });
  writeFileSync(`${directory}/unsupported.txt`, 'Not media');
  execFileSync('ffmpeg', [...common, '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '4', '-c:v', 'libx264', '-threads', '1', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-movflags', '+faststart', '-metadata', `comment=Disposable acceptance ${Date.now()}`, `${directory}/video.mp4`], { stdio: 'inherit' });
}
