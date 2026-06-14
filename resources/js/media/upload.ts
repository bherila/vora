/**
 * PUT a file to a presigned URL using XHR so we can report progress (the fetch
 * API does not expose upload progress). The headers must be exactly those the
 * URL was signed with (returned by the API alongside the URL), otherwise R2
 * rejects the signature.
 */
export function putToSignedUrl(
  url: string,
  file: File,
  headers: Record<string, string>,
  onProgress: (fraction: number) => void,
): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url, true);
    for (const [name, value] of Object.entries(headers)) {
      xhr.setRequestHeader(name, value);
    }

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total);
      }
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve();
      } else {
        reject(new Error(`Upload failed (HTTP ${xhr.status}).`));
      }
    };

    xhr.onerror = () => reject(new Error('Upload failed: network error.'));
    xhr.send(file);
  });
}

export interface MultipartInfo {
  upload_id: string;
  part_size: number;
}

export interface UploadedPart {
  part_number: number;
  etag: string;
}

/**
 * PUT one part (a Blob slice) to its presigned URL and return the ETag R2 sends
 * back, which the complete call needs. Requires the bucket CORS to expose the
 * ETag response header.
 */
function putPart(url: string, body: Blob, onLoaded: (loaded: number) => void): Promise<string> {
  return new Promise<string>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url, true);
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onLoaded(event.loaded);
      }
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        const etag = xhr.getResponseHeader('ETag');
        if (etag) {
          resolve(etag);
        } else {
          reject(new Error('Upload failed: the storage response did not expose an ETag (check bucket CORS).'));
        }
      } else {
        reject(new Error(`Part upload failed (HTTP ${xhr.status}).`));
      }
    };
    xhr.onerror = () => reject(new Error('Part upload failed: network error.'));
    xhr.send(body);
  });
}

async function withRetry<T>(fn: () => Promise<T>, attempts = 3): Promise<T> {
  let lastError: unknown;
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error;
    }
  }
  throw lastError instanceof Error ? lastError : new Error('Upload failed after retries.');
}

/**
 * Chunk a file and upload each part to a presigned URL, with per-part retry for
 * resilience on large/slow connections. Returns the ordered parts (number +
 * ETag) the caller passes to the multipart-complete endpoint.
 */
export async function uploadMultipart(
  file: File,
  multipart: MultipartInfo,
  requestPartUrl: (partNumber: number) => Promise<string>,
  onProgress: (fraction: number) => void,
): Promise<UploadedPart[]> {
  const partSize = Math.max(multipart.part_size, 5 * 1024 * 1024);
  const partCount = Math.max(1, Math.ceil(file.size / partSize));
  const parts: UploadedPart[] = [];
  let uploadedBytes = 0;

  for (let index = 0; index < partCount; index++) {
    const start = index * partSize;
    const blob = file.slice(start, Math.min(start + partSize, file.size));
    const partNumber = index + 1;

    const etag = await withRetry(async () => {
      const url = await requestPartUrl(partNumber);
      return putPart(url, blob, (loaded) => {
        onProgress(file.size === 0 ? 1 : (uploadedBytes + loaded) / file.size);
      });
    });

    uploadedBytes += blob.size;
    onProgress(file.size === 0 ? 1 : uploadedBytes / file.size);
    parts.push({ part_number: partNumber, etag });
  }

  return parts;
}
