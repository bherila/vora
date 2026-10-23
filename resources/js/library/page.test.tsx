import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

import { LibraryPage } from './page';
import { EMPTY_STORAGE_USAGE, type LibraryCollection, type LibraryItem } from './types';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), put: jest.fn(), delete: jest.fn() } }));
jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));
jest.mock('@/media/MediaUploadDialog', () => ({ MediaUploadDialog: () => <button>Upload media</button> }));
jest.mock('@/components/protected-image', () => ({ ProtectedImage: () => <span>Image preview</span> }));
jest.mock('@/media/MediaPlayer', () => ({ MediaPlayer: () => <span>Media player</span> }));
jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));

const get = jest.mocked(fetchWrapper.get);
const post = jest.mocked(fetchWrapper.post);
const remove = jest.mocked(fetchWrapper.delete);
const bootstrap = jest.mocked(readInitialData);

const note: LibraryItem = {
  ulid: 'my-note', title: 'Received note', body: 'Private text received elsewhere.', source: 'note',
  owned: true, available: true, member_ids: [], collection_ulids: ['my-collection'], media: null, created_at: null,
};
const collection: LibraryCollection = {
  ulid: 'my-collection', name: 'Received from Lyra', description: null, owned: true, member_ids: [],
  related_persona: { ulid: 'lyra', display_name: 'Lyra', href: '/c/lyra' }, related_persona_unavailable: false,
};

describe('LibraryPage', () => {
  let collections: LibraryCollection[];
  let currentNote: LibraryItem;

  beforeEach(() => {
    jest.clearAllMocks();
    Object.defineProperty(window, 'PointerEvent', { configurable: true, value: MouseEvent });
    window.history.replaceState({}, '', '/library');
    collections = [collection];
    currentNote = note;
    bootstrap.mockReturnValue({ library: { enabled: true, storage_usage: EMPTY_STORAGE_USAGE } });
    get.mockImplementation(async (url) => {
      if (url === '/api/library/collections') return { data: collections };
      if (url === '/api/account/storage') return { data: EMPTY_STORAGE_USAGE };
      if (url === '/api/library/personas?q=') return { data: [{ id: 9, ulid: 'lyra', display_name: 'Lyra' }] };
      if (url === '/api/library/items/my-note') return { data: currentNote };
      if (url === '/api/users') return { data: [] };
      return { data: [currentNote], meta: { has_more: false } };
    });
    post.mockResolvedValue({ data: note });
    remove.mockResolvedValue({ success: true });
  });

  it('keeps existing content readable while storage creation is disabled', async () => {
    bootstrap.mockReturnValue({ library: { enabled: false, storage_usage: EMPTY_STORAGE_USAGE } });
    render(<LibraryPage />);
    expect(await screen.findByRole('button', { name: 'Open Received note' })).toBeInTheDocument();
    expect(screen.getByText(/Library storage is enabled selectively/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Upload media' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'New collection' })).toBeNull();
  });

  it('creates a private collection using an existing persona without granting that persona access', async () => {
    render(<LibraryPage />);
    fireEvent.click(screen.getByRole('button', { name: 'New collection' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/It grants no access and sends no notification/)).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText('Collection name'), { target: { value: 'From Lyra' } });
    await within(dialog).findByRole('option', { name: 'Lyra' });
    fireEvent.change(within(dialog).getByLabelText('Related persona (optional)'), { target: { value: 'lyra' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create collection' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/collections', { name: 'From Lyra', description: null, related_persona_ulid: 'lyra' }));
  });

  it('discloses shared collection access when adding a note', async () => {
    window.history.replaceState({}, '', '/library?collection=my-collection');
    collections = [{ ...collection, member_ids: [8] }];
    render(<LibraryPage />);
    await screen.findByText(/Current and future items you upload or write, including notes/);
    fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/This note will be visible to this collection/)).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText('Note'), { target: { value: 'A deliberately shared note.' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add note' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/notes', { title: null, body: 'A deliberately shared note.', collection_ulid: 'my-collection' }));
  });

  it('removes a collection while preserving its items in the Library', async () => {
    window.history.replaceState({}, '', '/library?collection=my-collection');
    remove.mockImplementation(async () => { collections = []; return { success: true }; });
    render(<LibraryPage />);
    await screen.findByRole('button', { name: 'Remove collection' });
    fireEvent.click(screen.getByRole('button', { name: 'Remove collection' }));
    const dialog = screen.getByRole('alertdialog');
    expect(within(dialog).getByText(/Its items remain in your Library/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove collection' }));
    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/library/collections/my-collection'));
    expect(await screen.findByRole('button', { name: 'Open Received note' })).toBeInTheDocument();
    expect(remove).toHaveBeenCalledTimes(1);
  });

  it('distinguishes removing collection membership from removing the Library item', async () => {
    remove.mockImplementation(async () => { currentNote = { ...note, collection_ulids: [] }; return { success: true }; });
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Open Received note' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove from Received from Lyra' }));
    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/library/items/my-note/collections/my-collection'));
    expect(within(dialog).getByText('Private text received elsewhere.')).toBeInTheDocument();
    expect(remove).toHaveBeenCalledTimes(1);
  });

  it('keeps saved items private while allowing organization into shared collections', async () => {
    currentNote = { ...note, source: 'saved' };
    collections = [{ ...collection, member_ids: [8] }];
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Open Received note' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/Saved items are visible only to you, including in shared collections/)).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Share' })).toBeNull();
    expect(within(dialog).getByRole('button', { name: 'Remove from Received from Lyra' })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Edit' })).toBeInTheDocument();
  });

  it('offers an individual original download for a retained video even when new storage is disabled', async () => {
    bootstrap.mockReturnValue({ library: { enabled: false, storage_usage: EMPTY_STORAGE_USAGE } });
    currentNote = { ...note, source: 'saved', body: null, media: {
      id: 11, ulid: 'video-one', character_id: null, type: 'video', purpose: 'event', title: 'Saved video',
      mime_type: 'video/mp4', size_bytes: 3 * 1024 ** 3, audience: 'specific', discoverable: false,
      upload_status: 'ready', url: null, thumbnail_url: null, video: null, interests: [], character: null, created_at: null,
    } };
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Open Received note' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('link', { name: 'Download' })).toHaveAttribute('href', '/api/library/items/my-note/download');
  });
  it('selects owned and shared items, saves only shared references and clears selection when searching', async () => {
    const originalGet = get.getMockImplementation()!;
    get.mockImplementation(async (url) => url.startsWith('/api/library/items?')
      ? { data: [currentNote, { ...currentNote, ulid: 'shared-note', title: 'Shared note', owned: false }], meta: { has_more: false } }
      : originalGet(url));
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select all on this page' }));
    expect(screen.getByText('2 selected')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Select Shared note' })).toBeChecked();
    expect(screen.queryByRole('button', { name: 'Remove from Library' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Save selected to Library' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/items/bulk-save', { ulids: ['shared-note'] }));
    fireEvent.change(screen.getByLabelText('Search Library items'), { target: { value: 'another query' } });
    await waitFor(() => expect(screen.queryByLabelText('Selected Library items')).toBeNull());
    expect((await screen.findByRole('checkbox', { name: 'Select Received note' }))).not.toBeChecked();
  });

  it('clears current-page selection when changing pages', async () => {
    const originalGet = get.getMockImplementation()!;
    get.mockImplementation(async (url) => {
      if (!url.startsWith('/api/library/items?')) return originalGet(url);
      const secondPage = url.includes('page=2');
      return { data: [{ ...note, ulid: secondPage ? 'page-two' : note.ulid, title: secondPage ? 'Next page note' : note.title }], meta: { has_more: !secondPage } };
    });
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Received note' }));
    expect(screen.getByText('1 selected')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByRole('checkbox', { name: 'Select Next page note' })).not.toBeChecked();
    expect(screen.queryByLabelText('Selected Library items')).toBeNull();
  });

  it('adds selected items to a shared collection with explicit sharing disclosure', async () => {
    collections = [{ ...collection, member_ids: [8] }];
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Received note' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add to collection' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Destination collection'), { target: { value: collection.ulid } });
    expect(within(dialog).getByText(/Your uploads and notes become visible/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Saved items stay private/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add to collection' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/items/bulk', { action: 'add', item_ulids: [note.ulid], collection_ulid: collection.ulid }));
    await waitFor(() => expect(screen.queryByLabelText('Selected Library items')).toBeNull());
  });

  it('requires confirmation for bulk Library deletion and supports removing while disabled', async () => {
    bootstrap.mockReturnValue({ library: { enabled: false, storage_usage: EMPTY_STORAGE_USAGE } });
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Received note' }));
    expect(screen.queryByRole('button', { name: 'Add to collection' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Remove from Library' }));
    expect(post).not.toHaveBeenCalled();
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/all your collections/)).toBeInTheDocument();
    expect(within(dialog).getByText(/keep their own saved items/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove from Library' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/items/bulk', { action: 'delete', item_ulids: [note.ulid] }));
  });

  it('moves from the current collection without detaching other memberships', async () => {
    window.history.replaceState({}, '', '/library?collection=my-collection');
    const destination = { ...collection, ulid: 'destination', name: 'New place' };
    collections = [collection, destination, { ...collection, ulid: 'foreign', name: 'Foreign place', owned: false }];
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Received note' }));
    fireEvent.click(screen.getByRole('button', { name: 'Move to collection' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/Other collection memberships remain/)).toBeInTheDocument();
    expect(within(dialog).queryByRole('option', { name: collection.name })).toBeNull();
    expect(within(dialog).queryByRole('option', { name: 'Foreign place' })).toBeNull();
    fireEvent.change(within(dialog).getByLabelText('Destination collection'), { target: { value: destination.ulid } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Move to collection' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/items/bulk', { action: 'move', item_ulids: [note.ulid], collection_ulid: destination.ulid, source_collection_ulid: collection.ulid }));
  });

  it('keeps a failed bulk selection available for retry without claiming success', async () => {
    post.mockRejectedValue('Access changed. Reload and try again.');
    window.history.replaceState({}, '', '/library?collection=my-collection');
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Received note' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove from collection' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove from collection' }));
    await within(dialog).findByText('Access changed. Reload and try again.');
    expect(post).toHaveBeenCalledWith('/api/library/items/bulk', { action: 'remove', item_ulids: [note.ulid], source_collection_ulid: collection.ulid });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.getByText('1 selected')).toBeInTheDocument();
  });

  it('offers bulk Save for shared items while hiding ownership-only organization actions', async () => {
    window.history.replaceState({}, '', '/library?collection=my-collection');
    collections = [{ ...collection, owned: false }];
    currentNote = { ...note, owned: false };
    render(<LibraryPage />);
    await screen.findByRole('button', { name: 'Open Received note' });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Received note' }));
    expect(screen.getByRole('button', { name: 'Save selected to Library' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove from Library' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Add to collection' })).toBeNull();
    expect(screen.queryByLabelText('Selected Library items')).toBeNull();
  });

  it('creates collections without persona requests or fields when personas are disabled', async () => {
    bootstrap.mockReturnValue({ instance: { profile: 'event', modules: { personas: false, profiles: false, events: true } }, library: { enabled: true, storage_usage: EMPTY_STORAGE_USAGE } });
    render(<LibraryPage />);
    fireEvent.click(screen.getByRole('button', { name: 'New collection' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByLabelText('Related persona (optional)')).toBeNull();
    expect(within(dialog).queryByLabelText('Search existing personas')).toBeNull();
    expect(screen.getByRole('heading', { name: 'My collection', hidden: true })).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText('Collection name'), { target: { value: 'Event memories' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create collection' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/collections', { name: 'Event memories', description: null }));
    expect(get.mock.calls.some(([url]) => url.startsWith('/api/library/personas'))).toBe(false);
    expect(screen.queryByText(/Organize them around existing personas/)).toBeNull();
  });

  it('shares with Event attendees without calling the disabled social people directory', async () => {
    window.history.replaceState({}, '', '/library?collection=my-collection');
    bootstrap.mockReturnValue({ instance: { modules: { personas: false, profiles: false, events: true } }, library: { enabled: true, storage_usage: EMPTY_STORAGE_USAGE } });
    const originalGet = get.getMockImplementation()!;
    get.mockImplementation(async (url) => url === '/api/events/attendees' ? { data: [{ id: 8, display_name: 'Sam' }, { id: 3, display_name: 'Myself' }] } : url === '/api/events/settings' ? { data: { viewer_id: 3 } } : originalGet(url));
    jest.mocked(fetchWrapper.put).mockResolvedValue({ success: true });
    render(<LibraryPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Share collection' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: 'Sam' }));
    expect(within(dialog).queryByRole('checkbox', { name: 'Myself' })).toBeNull();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save sharing' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/library/collections/my-collection/sharing', { user_ids: [8] }));
    expect(get).toHaveBeenCalledWith('/api/events/attendees');
    expect(get).not.toHaveBeenCalledWith('/api/users');
    expect(screen.queryByRole('link', { name: 'Lyra' })).toBeNull();
  });

});
