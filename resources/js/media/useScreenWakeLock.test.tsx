import { act, renderHook } from '@testing-library/react';

import { useScreenWakeLock } from './useScreenWakeLock';

class FakeSentinel extends EventTarget {
  released = false;
  release = jest.fn(async () => { this.released = true; this.dispatchEvent(new Event('release')); });
}

function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
  document.dispatchEvent(new Event('visibilitychange'));
}

describe('useScreenWakeLock', () => {
  let sentinels: FakeSentinel[];

  beforeEach(() => {
    sentinels = [];
    setVisibility('visible');
    Object.defineProperty(navigator, 'wakeLock', {
      configurable: true,
      value: { request: jest.fn(async () => { const sentinel = new FakeSentinel(); sentinels.push(sentinel); return sentinel; }) },
    });
  });

  afterEach(() => { delete (navigator as { wakeLock?: unknown }).wakeLock; });

  it('holds the lock while active, re-acquires after the system releases it, and releases on close', async () => {
    const { result, rerender } = renderHook(({ active }) => useScreenWakeLock(active), { initialProps: { active: true } });
    await act(async () => {});
    expect(result.current).toBe(true);
    expect(navigator.wakeLock.request).toHaveBeenCalledWith('screen');

    // iOS drops the lock when the page is hidden (app switch, lock button).
    await act(async () => { setVisibility('hidden'); await sentinels[0]?.release(); });
    expect(result.current).toBe(false);
    await act(async () => { setVisibility('visible'); });
    expect(result.current).toBe(true);
    expect(sentinels).toHaveLength(2);

    rerender({ active: false });
    expect(sentinels[1]?.release).toHaveBeenCalled();
    expect(result.current).toBe(false);
  });

  it('does nothing when inactive, unsupported, or denied', async () => {
    renderHook(() => useScreenWakeLock(false));
    expect(navigator.wakeLock.request).not.toHaveBeenCalled();

    jest.mocked(navigator.wakeLock.request).mockRejectedValueOnce(new DOMException('Low power mode', 'NotAllowedError'));
    const denied = renderHook(() => useScreenWakeLock(true));
    await act(async () => {});
    expect(denied.result.current).toBe(false);

    delete (navigator as { wakeLock?: unknown }).wakeLock;
    const unsupported = renderHook(() => useScreenWakeLock(true));
    await act(async () => {});
    expect(unsupported.result.current).toBe(false);
  });
});
