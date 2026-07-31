import { act, renderHook } from '@testing-library/react';

import { useAdaptivePolling } from '@/chat/useAdaptivePolling';

function setOnline(online: boolean): void {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: online });
}

function setVisibility(visibilityState: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: visibilityState });
}

describe('useAdaptivePolling', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.spyOn(Math, 'random').mockReturnValue(0.5);
    setOnline(true);
    setVisibility('visible');
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('polls on schedule, backs off after failure, and resets after success', async () => {
    const onPoll = jest.fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue(undefined);
    renderHook(() => useAdaptivePolling({ enabled: true, intervalMs: 100, onPoll }));

    await act(async () => { jest.advanceTimersByTime(100); });
    expect(onPoll).toHaveBeenCalledTimes(1);
    await act(async () => { jest.advanceTimersByTime(199); });
    expect(onPoll).toHaveBeenCalledTimes(1);
    await act(async () => { jest.advanceTimersByTime(1); });
    expect(onPoll).toHaveBeenCalledTimes(2);
    await act(async () => { jest.advanceTimersByTime(100); });
    expect(onPoll).toHaveBeenCalledTimes(3);
  });

  it('pauses while hidden or offline and refreshes immediately when resumed', async () => {
    setVisibility('hidden');
    const onPoll = jest.fn().mockResolvedValue(undefined);
    renderHook(() => useAdaptivePolling({ enabled: true, intervalMs: 100, onPoll }));

    await act(async () => { jest.advanceTimersByTime(500); });
    expect(onPoll).not.toHaveBeenCalled();

    setVisibility('visible');
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    expect(onPoll).toHaveBeenCalledTimes(1);

    setOnline(false);
    act(() => { window.dispatchEvent(new Event('offline')); });
    await act(async () => { jest.advanceTimersByTime(500); });
    expect(onPoll).toHaveBeenCalledTimes(1);

    setOnline(true);
    await act(async () => { window.dispatchEvent(new Event('online')); });
    expect(onPoll).toHaveBeenCalledTimes(2);
  });

  it('does not overlap an in-flight poll', async () => {
    let resolvePoll: (() => void) | undefined;
    const onPoll = jest.fn(() => new Promise<void>((resolve) => { resolvePoll = resolve; }));
    renderHook(() => useAdaptivePolling({ enabled: true, intervalMs: 100, onPoll }));

    await act(async () => { jest.advanceTimersByTime(100); });
    expect(onPoll).toHaveBeenCalledTimes(1);
    act(() => { window.dispatchEvent(new Event('focus')); });
    expect(onPoll).toHaveBeenCalledTimes(1);

    await act(async () => { resolvePoll?.(); });
    await act(async () => { jest.advanceTimersByTime(100); });
    expect(onPoll).toHaveBeenCalledTimes(2);
  });
});
