/** Upload failures retain their status so permanent errors are never retried. */
export class UploadHttpError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
    this.name = 'UploadHttpError';
  }
}

/** JSON POST for upload endpoints, with cancellation and status-aware errors. */
export async function postUpload<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    },
    body: JSON.stringify(body),
    ...(signal ? { signal } : {}),
  });
  const text = await response.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    // HTML errors still carry their HTTP status (e.g. a gateway failure).
  }
  if (!response.ok) {
    const message = typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string'
      ? data.message : response.statusText || 'Upload request failed.';
    throw new UploadHttpError(response.status, message);
  }
  return data as T;
}
