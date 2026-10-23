/** Kept separate so worker lifecycle checks can inject a disposable worker. */
export function createHeifDecoderWorker(): Worker {
  return new Worker(new URL('./heifDecoder.worker.ts', import.meta.url), { type: 'module' });
}
