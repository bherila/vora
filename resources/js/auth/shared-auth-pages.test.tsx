import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { readInitialData } from '@/initialData';

import { ForgotPasswordPage } from './forgot-password';
import { LoginPage } from './login';
import { ResetPasswordPage } from './reset-password';

jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));

const request = jest.fn<ReturnType<typeof fetch>, Parameters<typeof fetch>>();

function jsonResponse(body: Record<string, unknown>, ok = true): Response {
  return { ok, json: async () => body } as Response;
}

beforeEach(() => {
  request.mockReset();
  globalThis.fetch = request;
  jest.mocked(readInitialData).mockReturnValue({ eventJoin: { continue_url: '/events/join/01ARZ3NDEKTSV4RRFFQ69G5FAV' } });
  document.head.innerHTML = '<meta name="csrf-token" content="synthetic-csrf">';
});

it('keeps the event context, shell and links while posting login choices once with CSRF', async () => {
  let finish!: (response: Response) => void;
  request.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
  render(<LoginPage />);
  expect(screen.getByText(/keep your place in this browser/)).toBeInTheDocument();
  expect(screen.getByText('Enter your credentials to access your account')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Sign up' })).toHaveAttribute('href', '/register');
  expect(screen.getByRole('link', { name: 'Forgot your password?' })).toHaveAttribute('href', '/forgot-password');
  expect(screen.getAllByText('Sign In')).toHaveLength(2);
  const email = screen.getByLabelText('Email');
  fireEvent.change(email, { target: { value: 'person@example.test' } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'synthetic-password' } });
  fireEvent.click(screen.getByLabelText('Remember me'));
  fireEvent.submit(email.closest('form')!);
  fireEvent.submit(email.closest('form')!);
  await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
  expect(screen.getByRole('button', { name: 'Signing in...' })).toBeDisabled();
  const [url, options] = request.mock.calls[0]!;
  expect(url).toBe('/api/auth/login');
  expect(options?.headers).toMatchObject({ 'X-CSRF-TOKEN': 'synthetic-csrf', Accept: 'application/json' });
  expect(JSON.parse(String(options?.body))).toEqual({ email: 'person@example.test', password: 'synthetic-password', remember: true });
  finish(jsonResponse({ success: false, message: 'Credentials rejected' }));
  expect(await screen.findByText('Credentials rejected')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Sign In' })).toBeEnabled();
});

it('refuses a malformed two-factor response instead of treating it as a completed sign-in', async () => {
  request.mockResolvedValue(jsonResponse({ requires_2fa: true }));
  render(<LoginPage />);
  fireEvent.submit(screen.getByLabelText('Email').closest('form')!);
  expect(await screen.findByText('Login failed. Please try again.')).toBeInTheDocument();
});

it.each(['accepted', 'rejected', 'offline'])('keeps forgot-password responses private when %s and permits another email', async (outcome) => {
  if (outcome === 'offline') request.mockRejectedValue(new Error('Internal transport detail'));
  else request.mockResolvedValue(jsonResponse({ message: 'Account-specific detail' }, outcome === 'accepted'));
  render(<ForgotPasswordPage />);
  const email = screen.getByLabelText('Email');
  fireEvent.change(email, { target: { value: 'person@example.test' } });
  fireEvent.submit(email.closest('form')!);
  expect(await screen.findByRole('alert')).toHaveTextContent('If an account exists with this email, a password reset link has been sent.');
  expect(screen.queryByText(/Account-specific|Internal transport/)).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Email')).not.toBeInTheDocument();
  expect(request).toHaveBeenCalledWith('/api/auth/forgot-password', expect.objectContaining({ body: JSON.stringify({ email: 'person@example.test' }) }));
  fireEvent.click(screen.getByRole('button', { name: 'Try a different email' }));
  expect(screen.getByLabelText('Email')).toHaveValue('');
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Back to Sign In' })).toHaveAttribute('href', '/login');
});

it('validates matching eight-character passwords and sends the bootstrapped reset token once', async () => {
  let finish!: (response: Response) => void;
  request.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
  render(<div id="reset-password"><ResetPasswordPage token="synthetic-reset-token" email="person@example.test" /></div>);
  expect(screen.getByLabelText('Email')).toHaveValue('person@example.test');
  const password = screen.getByLabelText('New Password');
  const confirmation = screen.getByLabelText('Confirm New Password');
  expect(password).toHaveAttribute('minlength', '8');
  expect(confirmation).toHaveAttribute('minlength', '8');
  fireEvent.change(password, { target: { value: 'short' } });
  fireEvent.submit(password.closest('form')!);
  expect(screen.getByRole('alert')).toHaveTextContent('Passwords do not match.');
  fireEvent.change(confirmation, { target: { value: 'short' } });
  fireEvent.submit(password.closest('form')!);
  expect(screen.getByRole('alert')).toHaveTextContent('Password must be at least 8 characters long.');
  expect(request).not.toHaveBeenCalled();
  fireEvent.change(password, { target: { value: 'new-password' } });
  fireEvent.change(confirmation, { target: { value: 'new-password' } });
  fireEvent.submit(password.closest('form')!);
  fireEvent.submit(password.closest('form')!);
  expect(request).toHaveBeenCalledTimes(1);
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Resetting...' })).toBeDisabled();
  const [url, options] = request.mock.calls[0]!;
  expect(url).toBe('/api/auth/reset-password');
  expect(options?.headers).toMatchObject({ 'X-CSRF-TOKEN': 'synthetic-csrf' });
  expect(JSON.parse(String(options?.body))).toEqual({ email: 'person@example.test', token: 'synthetic-reset-token', password: 'new-password', password_confirmation: 'new-password' });
  finish(jsonResponse({ success: false, message: 'Reset link expired' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Reset link expired');
  expect(screen.getByRole('button', { name: 'Reset Password' })).toBeEnabled();
});
