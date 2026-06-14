/**
 * PUT a file to a presigned URL using XHR so we can report progress (the fetch
 * API does not expose upload progress). The Content-Type must match the type
 * the URL was signed with.
 */
export function putToSignedUrl(
  url: string,
  file: File,
  contentType: string,
  onProgress: (fraction: number) => void,
): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url, true);
    xhr.setRequestHeader('Content-Type', contentType);

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
