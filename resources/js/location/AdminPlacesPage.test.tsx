import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { type AdminPlacesData, AdminPlacesPage } from './AdminPlacesPage';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() } }));

let initial: { adminPlaces?: AdminPlacesData } = {};
jest.mock('@/initialData', () => ({ readInitialData: () => initial }));

const mocked = fetchWrapper as jest.Mocked<typeof fetchWrapper>;

function payload(overrides: Partial<AdminPlacesData> = {}): AdminPlacesData {
  return {
    dataset: 'cities5000',
    datasets: ['cities500', 'cities1000', 'cities5000', 'cities15000'],
    auto_refresh: false,
    counts: { places: 64672, current_places: 64670, regions: 3865, countries: 252, accounts_with_location: 12 },
    imported_at: '2026-11-10T06:47:00Z',
    pending: null,
    status: null,
    max_upload_bytes: 50 * 1024 * 1024,
    ...overrides,
  };
}

function sentForm(): Record<string, FormDataEntryValue> {
  const body = mocked.post.mock.calls.at(-1)?.[1] as FormData;
  return Object.fromEntries(body.entries());
}

describe('AdminPlacesPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    initial = { adminPlaces: payload() };
  });

  it('shows the current list and saves the dataset and monthly refresh', async () => {
    mocked.put.mockResolvedValue({ message: 'City list settings saved.', data: payload({ dataset: 'cities15000', auto_refresh: true }) });
    render(<AdminPlacesPage />);

    expect(screen.getByText('64,670')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Dataset'), { target: { value: 'cities15000' } });
    fireEvent.click(screen.getByLabelText('Refresh from GeoNames every month'));
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() => expect(mocked.put).toHaveBeenCalledWith('/api/admin/places/settings', { dataset: 'cities15000', auto_refresh: true }));
    expect(await screen.findByText('City list settings saved.')).toBeInTheDocument();
  });

  it('queues a replace preview that moves people, and needs all three files to upload', async () => {
    mocked.post.mockResolvedValue({ message: 'Preview queued. It starts within a minute.', data: payload({ pending: { id: '9b2d7c1e-4f3a-4b5c-8d6e-7f8091a2b3c4', mode: 'replace', rehome: true, dry_run: true, uploaded: false, source: 'admin', requested_at: '2026-11-10T07:00:00Z' } }) });
    render(<AdminPlacesPage />);

    fireEvent.click(screen.getByLabelText(/Replace/));
    fireEvent.click(screen.getByLabelText(/Move people whose city was dropped/));
    fireEvent.click(screen.getByLabelText('Upload GeoNames files'));
    expect(screen.getByRole('button', { name: 'Preview changes' })).toBeDisabled();
    fireEvent.click(screen.getByLabelText(/Download cities5000/));
    fireEvent.click(screen.getByRole('button', { name: 'Preview changes' }));

    await waitFor(() => expect(mocked.post).toHaveBeenCalled());
    expect(sentForm()).toEqual({ source: 'download', mode: 'replace', rehome: '1', dry_run: '1' });
    expect(await screen.findByText('Queued')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Import now' })).toBeDisabled();
  });

  it('applies the finished preview on screen by its id', async () => {
    initial = { adminPlaces: payload({ status: {
      state: 'succeeded', dry_run: true, mode: 'replace', rehome: true, dataset: 'cities5000', source: 'admin', request_id: '5f1c5b7e-7a51-4f5e-9a8e-3b0d2c1e4f60',
      result: { dry_run: true, parsed: 64000, added: 10, updated: 63990, removed: 5, kept_in_use: 0, rehomed: 2, accounts_affected: 2, regions_removed: 0, countries_removed: 0, prune_skipped: false },
    } }) };
    mocked.post.mockResolvedValue({ data: payload() });
    render(<AdminPlacesPage />);

    expect(screen.getByText('Accounts moved to the nearest city')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Apply this preview' }));

    await waitFor(() => expect(mocked.post).toHaveBeenCalled());
    expect(sentForm()).toEqual({ source: 'preview', preview_id: '5f1c5b7e-7a51-4f5e-9a8e-3b0d2c1e4f60' });
  });

  it('does not offer to apply a command-line preview', () => {
    initial = { adminPlaces: payload({ status: {
      state: 'succeeded', dry_run: true, mode: 'update', dataset: 'cities5000', source: 'command',
      result: { dry_run: true, parsed: 1, added: 0, updated: 1, removed: 0, kept_in_use: 0, rehomed: 0, accounts_affected: 0, regions_removed: 0, countries_removed: 0, prune_skipped: false },
    } }) };
    render(<AdminPlacesPage />);

    expect(screen.queryByRole('button', { name: 'Apply this preview' })).toBeNull();
    expect(screen.getByText(/run from the command line/)).toBeInTheDocument();
  });

  it('reports a failed run', () => {
    initial = { adminPlaces: payload({ status: { state: 'failed', dataset: 'cities5000', mode: 'update', error: 'Download failed (503)' } }) };
    render(<AdminPlacesPage />);

    expect(screen.getByText('Last run failed')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('Download failed (503)');
  });

  it('drops chosen upload files when switching away from Upload, so hidden files are never sent', async () => {
    mocked.post.mockResolvedValue({ data: payload() });
    render(<AdminPlacesPage />);
    const file = (name: string) => new File(['x'], name, { type: 'text/plain' });

    fireEvent.click(screen.getByLabelText('Upload GeoNames files'));
    fireEvent.change(screen.getByLabelText(/Cities \(/), { target: { files: [file('cities5000.txt')] } });
    fireEvent.change(screen.getByLabelText(/Regions \(/), { target: { files: [file('admin1CodesASCII.txt')] } });
    fireEvent.change(screen.getByLabelText(/Countries \(/), { target: { files: [file('countryInfo.txt')] } });
    expect(screen.getByRole('button', { name: 'Import now' })).toBeEnabled();

    fireEvent.click(screen.getByLabelText(/Download cities5000/));
    fireEvent.click(screen.getByLabelText('Upload GeoNames files'));

    expect(screen.getByRole('button', { name: 'Import now' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Cities \(/), { target: { files: [file('cities15000.zip')] } });
    expect(screen.getByRole('button', { name: 'Import now' })).toBeDisabled();
  });

  it('cancels the queued run by its id and shows an interrupted run as finished', async () => {
    initial = { adminPlaces: payload({
      pending: { id: '9b2d7c1e-4f3a-4b5c-8d6e-7f8091a2b3c4', mode: 'update', rehome: false, dry_run: false, uploaded: false, source: 'admin', requested_at: '2026-11-10T07:00:00Z' },
      status: { state: 'interrupted', dataset: 'cities5000', mode: 'update', error: 'The import stopped without finishing (its process ended). Start it again.' },
    }) };
    mocked.delete.mockResolvedValue({ message: 'Queued import cancelled.', data: payload() });
    render(<AdminPlacesPage />);

    expect(screen.getByText('Last run was interrupted')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('stopped without finishing');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    await waitFor(() => expect(mocked.delete).toHaveBeenCalledWith('/api/admin/places/import', { id: '9b2d7c1e-4f3a-4b5c-8d6e-7f8091a2b3c4' }));
    expect(await screen.findByText('Queued import cancelled.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Import now' })).toBeEnabled();
  });
});
