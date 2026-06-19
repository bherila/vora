let cache: Record<string, unknown> | null = null;

export function readInitialData<T = Record<string, unknown>>(): T {
  if (cache === null) {
    const el = document.getElementById('initial-data');
    try {
      cache = el?.textContent ? JSON.parse(el.textContent) as Record<string, unknown> : {};
    } catch {
      cache = {};
    }
  }

  return cache as T;
}
