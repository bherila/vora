import { fetchWrapper } from '@/fetchWrapper';
import type { VideoMoment } from '@/media/types';

/** Server limits, mirrored for immediate feedback (VideoMoment.php, config/media.php). */
export const MAX_MOMENT_CAPTION_LENGTH = 280;
export const MAX_CAPTURE_BYTES = 1_572_864;
const MAX_CAPTURE_EDGE = 1920;

interface MomentResponse {
  data: VideoMoment;
}

interface CreateResponse extends MomentResponse {
  provisional_upload: { url: string; headers: Record<string, string> } | null;
}

/** Pages to follow at most: the server caps a video at 2,000 moments, 100 a page. */
const MAX_MOMENT_PAGES = 25;

/** Every moment of a video the viewer can see, following the server's cursor. */
export async function listMoments(mediaUlid: string): Promise<VideoMoment[]> {
  const base = `/api/media/by-ulid/${encodeURIComponent(mediaUlid)}/moments`;
  const moments: VideoMoment[] = [];
  let cursor: string | null = null;
  for (let page = 0; page < MAX_MOMENT_PAGES; page++) {
    const response = await fetchWrapper.get(cursor === null ? base : `${base}?cursor=${encodeURIComponent(cursor)}`) as { data: VideoMoment[]; next_cursor?: string | null };
    moments.push(...response.data);
    cursor = response.next_cursor ?? null;
    if (cursor === null) {
      break;
    }
  }
  return moments;
}

/**
 * Save a moment. When the server still has to extract the frame, the
 * browser's own capture is uploaded so the author sees it straight away;
 * nobody else is ever shown it. A failed capture upload leaves the moment
 * saved, just without the early preview.
 */
export async function saveMoment(
  mediaUlid: string,
  fields: { time_ms: number; caption: string | null; visibility: VideoMoment['visibility'] },
  capture: Blob | null,
): Promise<VideoMoment> {
  const created = await fetchWrapper.post(`/api/media/by-ulid/${encodeURIComponent(mediaUlid)}/moments`, {
    ...fields,
    wants_provisional: capture !== null,
    // The upload URL is signed for this exact length.
    ...(capture === null ? {} : { capture_bytes: capture.size }),
  }) as CreateResponse;
  const upload = created.provisional_upload;
  if (capture === null || upload === null) {
    return created.data;
  }
  try {
    const put = await fetch(upload.url, { method: 'PUT', headers: upload.headers, body: capture });
    if (!put.ok) {
      return created.data;
    }
    const completed = await fetchWrapper.post(`/api/moments/${encodeURIComponent(created.data.ulid)}/provisional/complete`, {}) as MomentResponse;
    return completed.data;
  } catch {
    return created.data;
  }
}

export function updateMoment(ulid: string, fields: Partial<Pick<VideoMoment, 'caption' | 'visibility'>>): Promise<VideoMoment> {
  return fetchWrapper.patch(`/api/moments/${encodeURIComponent(ulid)}`, fields).then((response: MomentResponse) => response.data);
}

export function retryMoment(ulid: string): Promise<VideoMoment> {
  return fetchWrapper.post(`/api/moments/${encodeURIComponent(ulid)}/retry`, {}).then((response: MomentResponse) => response.data);
}

export function deleteMoment(ulid: string): Promise<unknown> {
  return fetchWrapper.delete(`/api/moments/${encodeURIComponent(ulid)}`);
}

export function setMomentAsCover(mediaUlid: string, momentUlid: string): Promise<unknown> {
  return fetchWrapper.post(`/api/media/by-ulid/${encodeURIComponent(mediaUlid)}/cover`, { moment: momentUlid });
}

/**
 * Draw the frame on screen into a JPEG, at most 1920 px on its long edge and
 * 1.5 MB. Resolves null when the browser cannot read the frame (for example
 * native HLS, whose cross-origin segments taint the canvas).
 */
export async function captureFrame(video: HTMLVideoElement): Promise<Blob | null> {
  const { videoWidth, videoHeight } = video;
  if (videoWidth === 0 || videoHeight === 0) {
    return null;
  }
  const scale = Math.min(1, MAX_CAPTURE_EDGE / Math.max(videoWidth, videoHeight));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(videoWidth * scale);
  canvas.height = Math.round(videoHeight * scale);
  const context = canvas.getContext('2d');
  if (context === null) {
    return null;
  }
  try {
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    for (const quality of [0.9, 0.75, 0.6]) {
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (blob !== null && blob.size <= MAX_CAPTURE_BYTES) {
        return blob;
      }
    }
  } catch {
    // A tainted canvas throws on export; the server frame still follows.
  }
  return null;
}
