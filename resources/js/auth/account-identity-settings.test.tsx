import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { AccountIdentitySettings } from '@/auth/account-identity-settings';
import { fetchWrapper } from '@/fetchWrapper';
import { putToSignedUrl } from '@/media/upload';
import { postUpload } from '@/media/uploadRequest';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { patch: jest.fn(), delete: jest.fn() } }));
jest.mock('@/restrictions', () => ({ activeRestriction: () => null }));
jest.mock('@/media/upload', () => ({ putToSignedUrl: jest.fn() }));
jest.mock('@/media/uploadRequest', () => ({ postUpload: jest.fn() }));

describe('AccountIdentitySettings', () => {
  beforeEach(() => { jest.resetAllMocks(); });

  it('updates only the display name through the existing account endpoint', async () => {
    jest.mocked(fetchWrapper.patch).mockResolvedValue({ data: { display_name: 'Alex' } });
    render(<AccountIdentitySettings displayName="Old name" />);
    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: ' Alex ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save display name' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Display name updated.');
    expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/account', { display_name: 'Alex' });
    expect(screen.getByLabelText('Display name')).toHaveValue('Alex');
  });

  it('preserves a failed name edit and displays the server error', async () => {
    jest.mocked(fetchWrapper.patch).mockRejectedValue('Name update failed.');
    render(<AccountIdentitySettings displayName="Old name" />);
    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'New name' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save display name' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Name update failed.');
    expect(screen.getByLabelText('Display name')).toHaveValue('New name');
  });

  it('uploads a photo through the core signed upload and review path', async () => {
    jest.mocked(postUpload).mockResolvedValueOnce({ data: { id: 42 }, upload_url: 'https://storage.example/upload', upload_headers: { 'Content-Type': 'image/png' } }).mockResolvedValueOnce({ success: true });
    jest.mocked(putToSignedUrl).mockResolvedValue();
    render(<AccountIdentitySettings displayName="Alex" />);
    const file = new File(['image'], 'avatar.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Profile picture'), { target: { files: [file] } });
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('An administrator reviews new images'));
    expect(postUpload).toHaveBeenNthCalledWith(1, '/api/account/profile-picture', { filename: 'avatar.png', content_type: 'image/png', size: 5 }, expect.any(AbortSignal));
    expect(putToSignedUrl).toHaveBeenCalledWith('https://storage.example/upload', file, { 'Content-Type': 'image/png' }, expect.any(Function), { signal: expect.any(AbortSignal) });
    expect(postUpload).toHaveBeenNthCalledWith(2, '/api/account/profile-picture/42/complete', {}, expect.any(AbortSignal));
  });

  it('releases a failed photo upload reservation and keeps the error visible', async () => {
    jest.mocked(postUpload).mockResolvedValue({ data: { id: 42 }, upload_url: 'https://storage.example/upload', upload_headers: {} });
    jest.mocked(putToSignedUrl).mockRejectedValue(new Error('Upload failed.'));
    render(<AccountIdentitySettings displayName="Alex" />);
    fireEvent.change(screen.getByLabelText('Profile picture'), { target: { files: [new File(['image'], 'avatar.png', { type: 'image/png' })] } });
    expect(await screen.findByRole('alert')).toHaveTextContent('Upload failed.');
    expect(postUpload).toHaveBeenCalledWith('/api/media/42/cancel-upload', {});
    expect(screen.getByLabelText('Profile picture')).toBeEnabled();
  });

  it('removes a photo through the existing account endpoint', async () => {
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });
    render(<AccountIdentitySettings displayName="Alex" />);
    fireEvent.click(screen.getByRole('button', { name: 'Remove current picture' }));
    await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/account/profile-picture', {}));
    expect(await screen.findByRole('status')).toHaveTextContent('Profile picture removed.');
  });
});
