import { useEffect, useState } from 'react';

/**
 * Keep the screen on while `active` (Safari 16.4+; Home Screen apps from iOS
 * 18.4). The platform releases the lock whenever the page is hidden, so it is
 * re-acquired when the page becomes visible again. Returns whether it is held.
 */
export function useScreenWakeLock(active: boolean): boolean {
  const [held, setHeld] = useState(false);

  useEffect(() => {
    if (!active || typeof navigator === 'undefined' || !('wakeLock' in navigator)) return;
    let sentinel: WakeLockSentinel | null = null;
    let cancelled = false;
    const acquire = async (): Promise<void> => {
      if (document.visibilityState !== 'visible' || (sentinel !== null && !sentinel.released)) return;
      try {
        const next = await navigator.wakeLock.request('screen');
        if (cancelled) { void next.release(); return; }
        sentinel = next;
        setHeld(true);
        next.addEventListener('release', () => { if (!cancelled) setHeld(false); });
      } catch {
        // Denied (low power mode, policy) or unsupported: the reminder text covers it.
        setHeld(false);
      }
    };
    const onVisibility = (): void => { if (document.visibilityState === 'visible') void acquire(); };
    void acquire();
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      cancelled = true;
      document.removeEventListener('visibilitychange', onVisibility);
      void sentinel?.release();
      setHeld(false);
    };
  }, [active]);

  return held;
}
