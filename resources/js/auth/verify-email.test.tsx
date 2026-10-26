import { act, fireEvent, render, screen } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { STATUS_POLL_MS, VerifyEmailPage } from './verify-email';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn() } }));
jest.mock('./event-joining', () => ({ EventJoiningNotice: () => null, getEventJoiningUrl: () => null }));

type Delivery = { status: string; sent_at: string } | null;
const status = (email: string, delivery: Delivery, emailLocked = false) => ({ data: { email, email_locked: emailLocked, verified: false, delivery } });
const sent = { sent_at: '2026-10-26T18:00:00Z' };

async function flush(): Promise<void> {
  await act(async () => { await Promise.resolve(); });
}

describe('VerifyEmailPage delivery status', () => {
  beforeEach(() => { jest.useFakeTimers(); jest.resetAllMocks(); });
  afterEach(() => jest.useRealTimers());

  it('shows where the link went, warns once it bounces, and lets the address be fixed', async () => {
    jest.mocked(fetchWrapper.get)
      .mockResolvedValueOnce(status('guest@exmaple.test', { status: 'sent', ...sent }))
      .mockResolvedValueOnce(status('guest@exmaple.test', { status: 'bounced', ...sent }))
      .mockResolvedValue(status('guest@example.test', null));
    jest.mocked(fetchWrapper.patch).mockResolvedValue({ success: true });
    render(<VerifyEmailPage />);
    await flush();
    expect(screen.getByText('guest@exmaple.test')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();

    await act(async () => { jest.advanceTimersByTime(STATUS_POLL_MS); });
    await flush();
    expect(screen.getByRole('alert')).toHaveTextContent("couldn't deliver the verification email to guest@exmaple.test");
    // A settled report stops polling.
    await act(async () => { jest.advanceTimersByTime(STATUS_POLL_MS * 5); });
    expect(fetchWrapper.get).toHaveBeenCalledTimes(2);

    fireEvent.change(screen.getByLabelText('Use a different email address'), { target: { value: ' guest@example.test ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save and resend' }));
    await flush();
    await flush();
    expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/account', { email: 'guest@example.test' });
    expect(screen.getByText('Verification link sent to guest@example.test.')).toBeInTheDocument();
    expect(screen.getByText('guest@example.test')).toBeInTheDocument();
    expect(screen.queryByLabelText('Use a different email address')).toBeNull();
  });

  it('keeps watching after a delivered report so a later bounce still surfaces', async () => {
    jest.mocked(fetchWrapper.get)
      .mockResolvedValueOnce(status('guest@exmaple.test', { status: 'delivered', ...sent }))
      .mockResolvedValue(status('guest@exmaple.test', { status: 'bounced', ...sent }));
    render(<VerifyEmailPage />);
    await flush();
    expect(screen.queryByRole('alert')).toBeNull();
    await act(async () => { jest.advanceTimersByTime(STATUS_POLL_MS); });
    await flush();
    expect(screen.getByRole('alert')).toHaveTextContent('guest@exmaple.test');
  });

  it('notes delays, hides the address change when locked, and stops polling after about three minutes', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValueOnce(status('guest@example.test', { status: 'deferred', ...sent }));
    const { unmount } = render(<VerifyEmailPage />);
    await flush();
    expect(screen.getByText(/delaying delivery to guest@example\.test/)).toBeInTheDocument();
    unmount();

    jest.mocked(fetchWrapper.get).mockResolvedValue(status('guest@exmaple.test', { status: 'blocked', ...sent }, true));
    const locked = render(<VerifyEmailPage />);
    await flush();
    expect(screen.getByRole('alert')).toHaveTextContent('locked to your invitation');
    expect(screen.queryByLabelText('Use a different email address')).toBeNull();
    locked.unmount();

    jest.mocked(fetchWrapper.get).mockReset().mockResolvedValue(status('guest@example.test', { status: 'sent', ...sent }));
    render(<VerifyEmailPage />);
    for (let i = 0; i < 25; i++) {
      await flush();
      await act(async () => { jest.advanceTimersByTime(STATUS_POLL_MS); });
    }
    expect(fetchWrapper.get).toHaveBeenCalledTimes(19);
  });
});
