/**
 * PUT a file to a presigned URL using XHR so we can report progress (the fetch
 * API does not expose upload progress). The headers must be exactly those the
 * URL was signed with (returned by the API alongside the URL), otherwise R2
 * rejects the signature.
 */
interface PutToSignedUrlOptions {
  signal?: AbortSignal;
}

export function putToSignedUrl(
  url: string,
  file: Blob,
  headers: Record<string, string>,
  onProgress: (fraction: number) => void,
  options: PutToSignedUrlOptions = {},
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

    const abort = (): void => {
      xhr.abort();
      reject(new DOMException('Upload canceled.', 'AbortError'));
    };

    if (options.signal?.aborted) {
      abort();
      return;
    }

    options.signal?.addEventListener('abort', abort, { once: true });
    xhr.onerror = () => reject(new Error('Upload failed: network error.'));
    xhr.onabort = () => reject(new DOMException('Upload canceled.', 'AbortError'));
    xhr.onloadend = () => options.signal?.removeEventListener('abort', abort);
    xhr.send(file);
  });
}
