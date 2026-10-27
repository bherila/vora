import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { AppIconPage } from './app-icon';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() } }));

const none = { app_name: 'Example Gathering', short_name: null, icon_url: null, apple_touch_icon_url: null };
const set = { app_name: 'Example Gathering', short_name: null, icon_url: '/app-icon/icon-512.png?v=abc', apple_touch_icon_url: '/app-icon/apple-touch-icon.png?v=abc' };

describe('AppIconPage', () => {
  beforeEach(() => jest.mocked(fetchWrapper.get).mockResolvedValue({ data: none }));

  it('uploads the chosen image as form data, previews it and can remove it', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValue({ data: set });
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ data: none });
    render(<AppIconPage />);
    expect(await screen.findByText('No icon')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save icon' })).toBeDisabled();

    const file = new File(['png'], 'monogram.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Upload icon'), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save icon' }));
    expect(await screen.findByRole('img', { name: 'Current app icon' })).toHaveAttribute('src', set.apple_touch_icon_url);
    const [url, body] = jest.mocked(fetchWrapper.post).mock.calls[0] as [string, FormData];
    expect(url).toBe('/api/admin/app-icon');
    expect(body.get('icon')).toBe(file);
    expect(screen.getByRole('status')).toHaveTextContent('Icon updated');

    fireEvent.click(screen.getByRole('button', { name: 'Remove icon' }));
    expect(await screen.findByText('No icon')).toBeInTheDocument();
    expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/admin/app-icon');
  });

  it('saves a Home Screen name and clears it back to the site name', async () => {
    jest.mocked(fetchWrapper.put)
      .mockResolvedValueOnce({ data: { ...none, short_name: 'Gathering' } })
      .mockResolvedValueOnce({ data: none });
    render(<AppIconPage />);
    await screen.findByText('No icon');
    const field = screen.getByLabelText('Home Screen name');
    expect(field).toHaveAttribute('placeholder', 'Example Gathering');

    fireEvent.change(field, { target: { value: ' Gathering ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save name' }));
    expect(await screen.findByText('Home Screen name saved.')).toBeInTheDocument();
    expect(fetchWrapper.put).toHaveBeenLastCalledWith('/api/admin/app-icon/short-name', { short_name: 'Gathering' });
    expect(screen.getByText('Gathering', { selector: 'p' })).toBeInTheDocument();

    fireEvent.change(field, { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save name' }));
    expect(await screen.findByText(/cleared; Example Gathering is used/)).toBeInTheDocument();
    expect(fetchWrapper.put).toHaveBeenLastCalledWith('/api/admin/app-icon/short-name', { short_name: null });
  });

  it("shows the server's validation message", async () => {
    jest.mocked(fetchWrapper.post).mockRejectedValue('The icon must be at least 192 pixels square.');
    render(<AppIconPage />);
    await screen.findByText('No icon');
    fireEvent.change(screen.getByLabelText('Upload icon'), { target: { files: [new File(['x'], 'tiny.png', { type: 'image/png' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save icon' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('at least 192 pixels'));
  });
});
