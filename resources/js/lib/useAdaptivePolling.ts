import { useCallback, useEffect, useRef, useState } from 'react';

export const ACTIVE_THREAD_POLL_MS = 12_000;
export const COMMENT_THREAD_POLL_MS = 20_000;
export const INBOX_POLL_MS = 45_000;
const MAX_BACKOFF_MULTIPLIER = 8;

interface PollGroupEntry {
  setGranted: (granted: boolean) => void;
}

const pollGroups = new Map<string, Map<symbol, PollGroupEntry>>();

function rebalanceGroup(group: string, maximum: number): void {
  const entries = pollGroups.get(group);
  if (!entries) return;

  let index = 0;
  entries.forEach((entry) => {
    entry.setGranted(index < maximum);
    index += 1;
  });
}

interface AdaptivePollingOptions {
  enabled: boolean;
  intervalMs: number;
  onPoll: () => Promise<void>;
  /** Optional shared capacity pool for many simultaneously mounted surfaces. */
  group?: string;
  maxGroupPollers?: number;
}

interface AdaptivePollingResult {
  pollNow: () => void;
}

export function useAdaptivePolling({
  enabled,
  intervalMs,
  onPoll,
  group,
  maxGroupPollers = 1,
}: AdaptivePollingOptions): AdaptivePollingResult {
  const callbackRef = useRef(onPoll);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const runningRef = useRef(false);
  const failuresRef = useRef(0);
  const mountedRef = useRef(false);
  const executeRef = useRef<() => void>(() => undefined);
  const slotIdRef = useRef(Symbol('adaptive-poller'));
  const [slotGranted, setSlotGranted] = useState(group === undefined);

  useEffect(() => {
    callbackRef.current = onPoll;
  }, [onPoll]);

  useEffect(() => {
    if (group === undefined) {
      setSlotGranted(true);
      return;
    }

    const entries = pollGroups.get(group) ?? new Map<symbol, PollGroupEntry>();
    pollGroups.set(group, entries);
    const slotId = slotIdRef.current;

    if (enabled) {
      entries.set(slotId, { setGranted: setSlotGranted });
    } else {
      entries.delete(slotId);
      setSlotGranted(false);
    }
    rebalanceGroup(group, maxGroupPollers);

    return () => {
      entries.delete(slotId);
      if (entries.size === 0) pollGroups.delete(group);
      else rebalanceGroup(group, maxGroupPollers);
    };
  }, [enabled, group, maxGroupPollers]);

  const effectiveEnabled = enabled && slotGranted;

  const clearTimer = useCallback((): void => {
    if (timeoutRef.current !== null) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
  }, []);

  const canPoll = useCallback((): boolean => (
    effectiveEnabled
    && document.visibilityState !== 'hidden'
    && navigator.onLine !== false
  ), [effectiveEnabled]);

  useEffect(() => {
    if (!effectiveEnabled) {
      mountedRef.current = false;
      executeRef.current = (): void => undefined;
      clearTimer();

      return;
    }

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
  }, [canPoll, clearTimer, effectiveEnabled, intervalMs]);

  return { pollNow: useCallback((): void => executeRef.current(), []) };
}
