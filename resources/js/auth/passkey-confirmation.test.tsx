import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { PasskeySection } from 'bwh-auth';

import { getAuthComponents } from './shared-components';

describe('published passkey confirmation with Vora components', () => {
  it('reacts to 423 through the existing shared-budget password endpoint and retries enrollment', async () => {
    let confirmed = false;
    const requests: Array<{ url: string; init: RequestInit | undefined }> = [];
    const response = (body: unknown, status = 200) => ({ ok: status < 400, status, json: async () => body }) as Response;
    const originalFetch = globalThis.fetch;
    const originalCredential = Object.getOwnPropertyDescriptor(globalThis, 'PublicKeyCredential');
    const originalCredentials = Object.getOwnPropertyDescriptor(navigator, 'credentials');
    const bytes = new Uint8Array([0]).buffer;
    const create = jest.fn(async () => ({ id: 'synthetic-new-key', rawId: bytes, type: 'public-key', response: { clientDataJSON: bytes, attestationObject: bytes } }));
    Object.defineProperty(globalThis, 'PublicKeyCredential', { configurable: true, value: class {} });
    Object.defineProperty(navigator, 'credentials', { configurable: true, value: { create } });
    document.head.innerHTML = '<meta name="csrf-token" content="synthetic-csrf">';
    globalThis.fetch = jest.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
      requests.push({ url, init });
      if (url === '/api/passkeys') return response([]);
      if (url === '/api/auth/confirm-password') { confirmed = true; return response({ success: true }); }
      if (url === '/api/passkeys/register/options') return confirmed
        ? response({ challenge: 'AA', user: { id: 'AA', name: 'synthetic@example.test', displayName: 'Synthetic' }, rp: { name: 'Synthetic' }, pubKeyCredParams: [] })
        : response({ error: 'password_confirmation_required' }, 423);
      if (url === '/api/passkeys/register') return response({ success: true });
      throw new Error('Unexpected synthetic endpoint');
    });
    try {
      render(<PasskeySection components={getAuthComponents()} />);
      await screen.findByText('No passkeys registered yet.');
      expect(screen.queryByRole('group', { name: 'Confirm your identity' })).toBeNull();
      fireEvent.change(screen.getByLabelText('Passkey name'), { target: { value: 'Synthetic key' } });
      fireEvent.click(screen.getByRole('button', { name: 'Add Passkey' }));
      await screen.findByRole('group', { name: 'Confirm your identity' });
      expect(create).not.toHaveBeenCalled();
      fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'synthetic-password' } });
      fireEvent.click(screen.getByRole('button', { name: 'Confirm password and continue' }));
      await waitFor(() => expect(create).toHaveBeenCalledTimes(1));
      await waitFor(() => expect(requests.some(({ url }) => url === '/api/passkeys/register')).toBe(true));
      const confirmation = requests.find(({ url }) => url === '/api/auth/confirm-password');
      const body = confirmation?.init?.body;
      expect(typeof body).toBe('string');
      if (typeof body !== 'string') throw new Error('Expected JSON request body');
      expect(JSON.parse(body)).toEqual({ password: 'synthetic-password' });
      expect(confirmation?.init?.credentials ?? 'same-origin').toBe('same-origin');
      expect(confirmation?.init?.headers).toEqual(expect.objectContaining({ 'X-CSRF-TOKEN': 'synthetic-csrf' }));
      expect(requests.filter(({ url }) => url === '/api/passkeys/register/options')).toHaveLength(2);
      expect(screen.queryByLabelText('Current password')).toBeNull();
    } finally {
      globalThis.fetch = originalFetch;
      if (originalCredential) Object.defineProperty(globalThis, 'PublicKeyCredential', originalCredential);
      else Reflect.deleteProperty(globalThis, 'PublicKeyCredential');
      if (originalCredentials) Object.defineProperty(navigator, 'credentials', originalCredentials);
      else Reflect.deleteProperty(navigator, 'credentials');
      document.head.innerHTML = '';
    }
  });
});
