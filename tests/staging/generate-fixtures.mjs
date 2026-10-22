import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export default function generateFixtures() {
  const directory = fileURLToPath(new URL('./.fixtures/', import.meta.url));
  mkdirSync(directory, { recursive: true });
  const common = ['-hide_banner', '-loglevel', 'error', '-y'];
  execFileSync('ffmpeg', [...common, '-f', 'lavfi', '-i', 'color=c=teal:s=320x180', '-frames:v', '1', '-threads', '1', `${directory}/photo.png`], { stdio: 'inherit' });
  execFileSync('ffmpeg', [...common, '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '4', '-c:v', 'libx264', '-threads', '1', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-movflags', '+faststart', '-metadata', `comment=Disposable acceptance ${Date.now()}`, `${directory}/video.mp4`], { stdio: 'inherit' });
}
