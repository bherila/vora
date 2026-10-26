import { heifMimeType, normalizeHeifFile, prepareHeifPhoto } from '@/media/heifPhoto';
import { computeFileHash, generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { readPhotoMetadata } from '@/media/photoMetadata';
import { type Audience, type MediaItem, mediaTypeForFile } from '@/media/types';
import { type CompletedMultipartPart, type MultipartPartToSign, type MultipartUploadSession, putToSignedUrl, readMultipartSession, removeMultipartSession, saveMultipartSession, uploadMultipartFile, withRetries } from '@/media/upload';
import { postUpload, UploadHttpError } from '@/media/uploadRequest';
import { readVideoCaptureTime } from '@/media/videoMetadata';

interface StoreResponse {
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
  thumbnail_upload_url: string | null;
  thumbnail_upload_headers: Record<string, string> | null;
  multipart: {
    enabled: boolean;
    threshold_bytes: number;
    part_size_bytes: number;
  };
}

interface InitMultipartResponse {
  data: { upload_id: string; part_size_bytes: number; expires_in_minutes: number };
}

interface PresignMultipartPartsResponse {
  data: { part_number: number; url: string; headers: Record<string, string> }[];
}

interface Derivatives {
  thumbnail: Blob | null;
  perceptualHash: string | null;
  width: number | null;
  height: number | null;
}

/**
 * Best-effort client-side thumbnail/poster (and, for photos, a perceptual hash).
 * Ordinary thumbnail failures are optional; HEIC photos require a JPEG display preview.
 */
async function buildDerivatives(file: File, type: 'photo' | 'video', signal: AbortSignal): Promise<Derivatives> {
  if (heifMimeType(file) !== null) {
    const { preview: thumbnail, width, height } = await prepareHeifPhoto(file, signal);
    let perceptualHash: string | null = null;
    if (supportsClientDerivatives()) {
      try { perceptualHash = (await generatePhotoDerivatives(new File([thumbnail], 'preview.jpg', { type: 'image/jpeg' }))).perceptualHash; } catch { /* Hashing remains best-effort. */ }
    }
    return { thumbnail, perceptualHash, width, height };
  }
  if (!supportsClientDerivatives()) {
    return { thumbnail: null, perceptualHash: null, width: null, height: null };
  }
  try {
    if (type === 'photo') {
      const { thumbnail, perceptualHash, width, height } = await generatePhotoDerivatives(file);
      return { thumbnail, perceptualHash, width: width ?? null, height: height ?? null };
    }
    const thumbnail = await generateVideoPoster(file, signal);
    signal.throwIfAborted();
    return { thumbnail, perceptualHash: null, width: null, height: null };
  } catch {
    return { thumbnail: null, perceptualHash: null, width: null, height: null };
  }
}

function multipartSessionKey(fileHash: string | null, scope: string): string {
  // Metadata can collide for different files. Never resume without verified bytes.
  return `vora:media:multipart:v2:${scope}:${fileHash ?? crypto.randomUUID()}`;
}

async function beginMultipartSession(
  created: StoreResponse | null,
  sessionKey: string,
  context: string,
  signal: AbortSignal,
): Promise<MultipartUploadSession> {
  if (created === null) {
    throw new Error('Upload session is missing.');
  }

  const init = await postUpload<InitMultipartResponse>(`/api/media/${created.data.id}/multipart/init`, {}, signal);
  const session = {
    mediaId: created.data.id,
    uploadId: init.data.upload_id,
    partSizeBytes: init.data.part_size_bytes,
    completedParts: [],
    createdAt: new Date().toISOString(),
    context,
  };

  saveMultipartSession(sessionKey, session);

  return session;
}

/** Release only an owned pending reservation; a ready conflict never deletes it. */
async function releaseStoredSession(session: Pick<MultipartUploadSession, 'mediaId'>, key: string): Promise<boolean> {
  let ready = false;
  try {
    await postUpload(`/api/media/${session.mediaId}/cancel-upload`, {});
  } catch (error) {
    if (!(error instanceof UploadHttpError)) throw error;
    if (error.status === 409) ready = true;
    else if (error.status !== 404) throw error;
  }
  // Keep recoverable state if cancellation failed for any other reason.
  removeMultipartSession(key);
  return ready;
}

async function uploadThumbnailBestEffort(
  created: StoreResponse,
  thumbnail: Blob | null,
  signal: AbortSignal,
  required = false,
): Promise<void> {
  if (!thumbnail || !created.thumbnail_upload_url || !created.thumbnail_upload_headers) {
    if (required) throw new Error('The HEIC/HEIF JPEG preview could not be uploaded. Please retry this photo.');
    return;
  }

  const { thumbnail_upload_url: url, thumbnail_upload_headers: headers } = created;
  try {
    await withRetries(() => putToSignedUrl(url, thumbnail, headers, () => {}, { signal }), signal);
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      throw err;
    }
    if (required) throw new Error('The HEIC/HEIF JPEG preview could not be uploaded. Please retry this photo.');
    /* Other thumbnails are optional. */
  }
}

export type MediaUploadDestination =
  | { kind: 'event'; ulid: string }
  | { kind: 'library'; collectionUlid: string | null }
  | { kind: 'gallery'; characterId: number | null; audience: Audience; audienceUserIds: number[]; discoverable: boolean; announce: boolean; interestIds: number[] };

export interface UploadReservation {
  mediaId: number;
  sessionKey: string | null;
}

export class UploadAttemptError extends Error {
  constructor(message: string, readonly canceled: boolean, readonly reservation: UploadReservation | null) {
    super(message);
  }
}

/** Discard only pending owned uploads; a ready conflict preserves completed media. */
export async function discardUploadReservation(reservation: UploadReservation): Promise<void> {
  try {
    await postUpload(`/api/media/${reservation.mediaId}/cancel-upload`, {});
  } catch (error) {
    if (!(error instanceof UploadHttpError) || ![404, 409].includes(error.status)) throw error;
  }
  if (reservation.sessionKey !== null) removeMultipartSession(reservation.sessionKey);
}

interface UploadMediaFileOptions {
  file: File;
  title: string;
  destination: MediaUploadDestination;
  signal: AbortSignal;
  reservation?: UploadReservation | null;
  onProgress: (phase: 'preparing' | 'uploading', percent: number) => void;
}

/** One immutable destination and file; batch state belongs to the dialog. */
export async function uploadMediaFile({ file, title, destination, signal, reservation, onProgress }: UploadMediaFileOptions): Promise<void> {
  const isEvent = destination.kind === 'event';
  const isLibrary = destination.kind === 'library';
  const eventUlid = destination.kind === 'event' ? destination.ulid : undefined;
  const libraryCollectionUlid = destination.kind === 'library' ? destination.collectionUlid : undefined;
  const selectedCharacterId = destination.kind === 'gallery' ? destination.characterId : null;
  const audience = destination.kind === 'gallery' ? destination.audience : 'everyone';
  const audienceUserIds = destination.kind === 'gallery' ? destination.audienceUserIds : [];
  const discoverable = destination.kind === 'gallery' && destination.discoverable;
  const announce = destination.kind === 'gallery' && destination.announce;
  const uploadInterestIds = destination.kind === 'gallery' ? destination.interestIds : [];
  let phase: 'preparing' | 'uploading' = 'preparing';
  const setProgress = (percent: number): void => onProgress(phase, percent);
  let disposableUploadId: number | null = null;
  let pendingMediaId: number | null = reservation?.mediaId ?? null;
  let sessionKey: string | null = reservation?.sessionKey ?? null;
  try {
    // A failed direct-upload cleanup must succeed before another reservation.
    if (reservation !== undefined && reservation !== null && reservation.sessionKey === null) {
      try {
        await postUpload(`/api/media/${reservation.mediaId}/cancel-upload`, {});
      } catch (error) {
        if (error instanceof UploadHttpError && error.status === 409) return;
        if (!(error instanceof UploadHttpError) || error.status !== 404) throw error;
      }
      pendingMediaId = null;
    }
    const selectedFile = normalizeHeifFile(file);
    const type = mediaTypeForFile(selectedFile);
    if (type === null) {
      throw new Error('Only image and video files can be uploaded.');
    }
    const inspectMetadata = isEvent && type === 'photo' && ['image/jpeg', 'image/png', 'image/heic', 'image/heif'].includes(selectedFile.type);
    phase = 'preparing';
    setProgress(0);
    const [{ thumbnail, perceptualHash, width, height }, fileHash, metadata, videoCapturedAt] = await Promise.all([
      buildDerivatives(selectedFile, type, signal),
      computeFileHash(selectedFile, (fraction) => setProgress(fraction * 100), signal),
      inspectMetadata ? readPhotoMetadata(selectedFile, signal) : Promise.resolve(null),
      isEvent && type === 'video' ? readVideoCaptureTime(selectedFile, signal) : Promise.resolve(null),
    ]);
    // Orders the Event gallery by when the moment happened rather than when it was shared.
    const capturedAt = metadata?.captured_at ?? videoCapturedAt;

    signal.throwIfAborted();
    phase = 'uploading';
    setProgress(0);
    const scope = isEvent ? `event:${eventUlid}` : isLibrary ? `library:${libraryCollectionUlid ?? 'all'}` : `gallery:${selectedCharacterId ?? 'account'}`;
    const currentSessionKey = multipartSessionKey(fileHash, scope);
    sessionKey = currentSessionKey;
    const uploadPayload = {
      type,
      filename: selectedFile.name,
      content_type: selectedFile.type,
      size: selectedFile.size,
      title: title.trim() || null,
      has_thumbnail: thumbnail !== null,
      thumbnail_size: thumbnail?.size ?? null,
      perceptual_hash: perceptualHash,
      file_hash: fileHash,
      ...(inspectMetadata ? { photo_metadata: { width, height, ...(metadata === null ? {} : {
        has_gps: metadata.has_gps, has_capture_time: metadata.has_capture_time, has_camera: metadata.has_camera,
      }) } } : {}),
      ...(isEvent && capturedAt ? { captured_at: capturedAt } : {}),
      ...(!isEvent ? { interest_ids: uploadInterestIds, announce } : {}),
      ...(isEvent ? {} : isLibrary ? {
        audience: 'specific', audience_user_ids: [], discoverable: false, announce: false,
        collection_ulid: libraryCollectionUlid,
      } : selectedCharacterId === null ? {
        audience,
        audience_user_ids: audience === 'specific' ? audienceUserIds : [],
        discoverable,
      } : {
        character_id: selectedCharacterId,
      }),
    };
    const context = JSON.stringify({
      audience: 'audience' in uploadPayload ? uploadPayload.audience : null,
      audience_user_ids: [...new Set('audience_user_ids' in uploadPayload ? uploadPayload.audience_user_ids : [])].sort((a, b) => a - b),
      discoverable: 'discoverable' in uploadPayload ? uploadPayload.discoverable : null,
      announce: 'announce' in uploadPayload ? uploadPayload.announce : false,
      character_id: 'character_id' in uploadPayload ? uploadPayload.character_id : null,
      title: uploadPayload.title,
      interest_ids: [...new Set('interest_ids' in uploadPayload ? uploadPayload.interest_ids : [])].sort((a, b) => a - b),
      collection_ulid: isLibrary ? libraryCollectionUlid : null,
      ...(isEvent ? { event_ulid: eventUlid } : {}),
    });
    // Metadata-only keys cannot establish matching bytes, but their old
    // reservations must be released before a replacement can consume quota.
    const legacyKey = `${isEvent ? `event:${eventUlid}:` : isLibrary ? `library:${libraryCollectionUlid ?? 'all'}:` : ''}vora:media:multipart:${selectedFile.name}:${selectedFile.size}:${selectedFile.lastModified}:${selectedFile.type}`;
    const legacySession = readMultipartSession(legacyKey);
    if (legacySession !== null) {
      pendingMediaId = legacySession.mediaId;
      sessionKey = legacyKey;
      await releaseStoredSession(legacySession, legacyKey);
      pendingMediaId = null;
      sessionKey = currentSessionKey;
    }

    let existingSession = fileHash === null ? null : readMultipartSession(currentSessionKey);
    if (existingSession !== null && existingSession.context !== context) {
      pendingMediaId = existingSession.mediaId;
      const ready = await releaseStoredSession(existingSession, currentSessionKey);
      existingSession = null;
      pendingMediaId = null;
      if (ready) {
        throw new Error('This upload already completed with different or unverified settings. Update the existing item’s settings before trying again.');
      }
    }
    const createUpload = async (): Promise<StoreResponse> => {
      const result = await postUpload<StoreResponse>(
        isEvent ? `/api/events/${encodeURIComponent(destination.kind === 'event' ? destination.ulid : '')}/uploads` : isLibrary ? '/api/library/uploads' : '/api/media', uploadPayload, signal,
      );
      disposableUploadId = result.data.id;
      pendingMediaId = result.data.id;
      return result;
    };
    if (existingSession === null && pendingMediaId !== null && reservation?.sessionKey) {
      const ready = await releaseStoredSession(reservation, reservation.sessionKey);
      pendingMediaId = null;
      if (ready) return;
    }
    if (existingSession !== null) pendingMediaId = existingSession.mediaId;
    const created = existingSession === null ? await createUpload() : null;

    const shouldMultipart = existingSession !== null
      || (created?.multipart.enabled === true && selectedFile.size >= created.multipart.threshold_bytes);

    if (shouldMultipart) {
      const runMultipart = (session: MultipartUploadSession, target: StoreResponse | null): Promise<void> => uploadMultipartFile(selectedFile, {
        sessionKey: currentSessionKey,
        session,
        signal: signal,
        onProgress: (fraction) => setProgress(fraction * 100),
        presignParts: async (parts: MultipartPartToSign[]) => {
          const response = await postUpload<PresignMultipartPartsResponse>(`/api/media/${session.mediaId}/multipart/parts`, {
            upload_id: session.uploadId,
            part_numbers: parts.map((part) => part.partNumber),
            part_sizes: Object.fromEntries(parts.map((part) => [part.partNumber, part.sizeBytes])),
          }, signal);

          return response.data;
        },
        complete: async (parts: CompletedMultipartPart[]) => {
          if (target !== null) {
            await uploadThumbnailBestEffort(target, thumbnail, signal, heifMimeType(selectedFile) !== null);
          }

          await postUpload(`/api/media/${session.mediaId}/multipart/complete`, {
            upload_id: session.uploadId,
            parts,
          }, signal);
        },
        abort: async () => {
          // Explicit cancellation also removes the incomplete Library item
          // and prevents a late direct PUT from completing this upload.
          await postUpload(`/api/media/${session.mediaId}/cancel-upload`, {});
          pendingMediaId = null;
          disposableUploadId = null;
        },
      });
      try {
        const session = existingSession ?? await beginMultipartSession(created, currentSessionKey, context, signal);
        // Retain failed sessions only when their content hash makes them resumable.
        if (fileHash !== null) disposableUploadId = null;
        await runMultipart(session, created);
      } catch (err) {
        if (existingSession === null || !(err instanceof UploadHttpError) || ![404, 422].includes(err.status)) throw err;
        // Matching bytes and settings plus an owner-only ready conflict
        // recover a lost completion response without allocating new media.
        const alreadyComplete = await releaseStoredSession(existingSession, currentSessionKey);
        pendingMediaId = null;
        if (!alreadyComplete) {
          signal.throwIfAborted();
          const replacement = await createUpload();
          const session = await beginMultipartSession(replacement, currentSessionKey, context, signal);
          disposableUploadId = null;
          await runMultipart(session, replacement);
        }
      } finally {
        if (fileHash === null) removeMultipartSession(currentSessionKey);
      }
    } else if (created !== null) {
      await withRetries(() => putToSignedUrl(created.upload_url, selectedFile, created.upload_headers, (fraction) => {
        setProgress(fraction * 100);
      }, { signal }), signal);

      await uploadThumbnailBestEffort(created, thumbnail, signal, heifMimeType(selectedFile) !== null);
      await withRetries(() => postUpload(`/api/media/${created.data.id}/complete`, {}, signal), signal);
    }

    pendingMediaId = null;
  } catch (error) {
    const canceled = error instanceof DOMException && error.name === 'AbortError';
    const cleanupId = disposableUploadId ?? (canceled ? pendingMediaId : null);
    if (cleanupId !== null) {
      try {
        await postUpload(`/api/media/${cleanupId}/cancel-upload`, {});
        pendingMediaId = null;
      } catch (cleanupError) {
        if (cleanupError instanceof UploadHttpError && cleanupError.status === 409) return;
        if (cleanupError instanceof UploadHttpError && cleanupError.status === 404) pendingMediaId = null;
      }
      if (pendingMediaId === null && sessionKey !== null) removeMultipartSession(sessionKey);
      // A failed cleanup can be retried before another allocation.
      if (disposableUploadId !== null || pendingMediaId === null) sessionKey = null;
    }
    const message = typeof error === 'string' ? error : error instanceof Error ? error.message : 'Request failed.';
    throw new UploadAttemptError(message, canceled,
      pendingMediaId === null ? null : { mediaId: pendingMediaId, sessionKey });
  }
}
