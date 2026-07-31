import { useCallback, useEffect, useRef } from 'react';

export const ACTIVE_THREAD_POLL_MS = 12_000;
export const INBOX_POLL_MS = 45_000;
const MAX_BACKOFF_MULTIPLIER = 8;

interface AdaptivePollingOptions {
  enabled: boolean;
  intervalMs: number;
  onPoll: () => Promise<void>;
}

interface AdaptivePollingResult {
  pollNow: () => void;
}

export function useAdaptivePolling({
  enabled,
  intervalMs,
  onPoll,
}: AdaptivePollingOptions): AdaptivePollingResult {
  const callbackRef = useRef(onPoll);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const runningRef = useRef(false);
  const failuresRef = useRef(0);
  const mountedRef = useRef(false);
  const executeRef = useRef<() => void>(() => undefined);

  useEffect(() => {
    callbackRef.current = onPoll;
  }, [onPoll]);

  const clearTimer = useCallback((): void => {
    if (timeoutRef.current !== null) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
  }, []);

  const canPoll = useCallback((): boolean => (
    enabled
    && document.visibilityState !== 'hidden'
    && navigator.onLine !== false
  ), [enabled]);

  useEffect(() => {
    mountedRef.current = true;

    const schedule = (): void => {
      clearTimer();
      if (!mountedRef.current || !canPoll()) return;

      const multiplier = Math.min(2 ** failuresRef.current, MAX_BACKOFF_MULTIPLIER);
      const jitter = 0.9 + Math.random() * 0.2;
      timeoutRef.current = setTimeout(() => executeRef.current(), Math.round(intervalMs * multiplier * jitter));
    };
    const execute = (): void => {
      if (!canPoll() || runningRef.current) return;

      runningRef.current = true;
      clearTimer();
      void callbackRef.current()
        .then(() => {
          failuresRef.current = 0;
        })
        .catch(() => {
          failuresRef.current += 1;
        })
        .finally(() => {
          runningRef.current = false;
          schedule();
        });
    };
    executeRef.current = execute;
    schedule();

    const resume = (): void => {
      if (document.visibilityState === 'hidden' || navigator.onLine === false) {
        clearTimer();
        return;
      }
      executeRef.current();
    };
    const visibility = (): void => resume();

    window.addEventListener('focus', resume);
    window.addEventListener('online', resume);
    window.addEventListener('offline', resume);
    document.addEventListener('visibilitychange', visibility);

    return () => {
      mountedRef.current = false;
      clearTimer();
      window.removeEventListener('focus', resume);
      window.removeEventListener('online', resume);
      window.removeEventListener('offline', resume);
      document.removeEventListener('visibilitychange', visibility);
    };
  }, [canPoll, clearTimer, intervalMs]);

  return { pollNow: useCallback((): void => executeRef.current(), []) };
}
