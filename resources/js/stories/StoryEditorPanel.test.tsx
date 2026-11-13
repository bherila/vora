import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { hydrateIdentityStore, removeIdentityOption, upsertIdentityOption } from '@/identity';

import { storiesApi } from './api';
import { StoryEditorPanel } from './StoryEditorPanel';
import type { StoryEditor } from './types';

jest.mock('@/initialData', () => ({
  readInitialData: () => ({
    navbar: {
      activeIdentityId: 17,
      identities: [
        { id: null, displayName: 'Human Name', avatarUrl: null },
        { id: 17, displayName: 'Kira', avatarUrl: null },
      ],
    },
  }),
}));

jest.mock('@/components/interest-picker', () => ({ InterestPicker: ({ value, onChange }: { value: number[]; onChange: (ids: number[]) => void }) => <button onClick={() => onChange(value.includes(5) ? value.filter((id) => id !== 5) : [...value, 5])}>Toggle interest</button> }));
jest.mock('@/components/Markdown', () => ({ Markdown: () => null }));
jest.mock('./CoAuthorPanel', () => ({ CoAuthorPanel: () => null }));
jest.mock('./CyoaGraphDiagram', () => ({ CyoaGraphDiagram: () => null }));

jest.mock('./api', () => ({
  storiesApi: {
    get: jest.fn(() => Promise.resolve({
      id: 3,
      ulid: 'story-ulid',
      title: 'Story',
      type: 'long_form',
      status: 'draft',
      audience: 'everyone',
      discoverable: true,
      body: '',
      owner: { id: 7, display_name: 'Human Name' },
      interests: [],
      involves: [],
      authors: [{
        id: 9,
        user_id: 7,
        character_id: 17,
        display_name: 'Kira',
        role: 'owner',
        status: 'accepted',
        is_owner: true,
      }],
      review: { status: 'pending', label: 'Pending', note: null },
      node_count: null,
      published_at: null,
      created_at: null,
      updated_at: null,
      nodes: [],
      choices: [],
      can_manage_authors: true,
      involvable_options: [],
    })),
    updateAuthorIdentity: jest.fn(() => Promise.resolve([])),
    update: jest.fn(),
    saveGraph: jest.fn(),
  },
}));

function deferredStory() {
  let resolve!: (story: StoryEditor) => void;
  let reject!: (reason: string) => void;
  const promise = new Promise<StoryEditor>((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

function renderEditor(onChanged = jest.fn()) {
  return render(<StoryEditorPanel storyId={3} currentUserId={7} onBack={jest.fn()} onChanged={onChanged} onDeleted={jest.fn()} />);
}

describe('StoryEditorPanel authoring identity', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    hydrateIdentityStore([
      { id: null, displayName: 'Human Name', avatarUrl: null },
      { id: 17, displayName: 'Kira', avatarUrl: null },
    ], 17);
  });

  it('shows the active identity persisted at story creation and lets the author change it', async () => {
    render(
      <StoryEditorPanel
        storyId={3}
        currentUserId={7}
        onBack={jest.fn()}
        onChanged={jest.fn()}
        onDeleted={jest.fn()}
      />,
    );

    const select = await screen.findByRole('combobox', { name: 'Writing as' });
    expect(select).toHaveValue('17');
    fireEvent.change(select, { target: { value: '' } });

    await waitFor(() => expect(storiesApi.updateAuthorIdentity).toHaveBeenCalledWith(3, 7, null));
  });

  it('updates live identity options without changing the story persisted author identity', async () => {
    render(
      <StoryEditorPanel
        storyId={3}
        currentUserId={7}
        onBack={jest.fn()}
        onChanged={jest.fn()}
        onDeleted={jest.fn()}
      />,
    );

    const select = await screen.findByRole('combobox', { name: 'Writing as' });
    expect(select).toHaveValue('17');

    act(() => upsertIdentityOption({ id: 23, displayName: 'Nova', avatarUrl: null }));
    expect(await screen.findByRole('option', { name: 'Nova' })).toBeInTheDocument();
    expect(select).toHaveValue('17');

    act(() => removeIdentityOption(23));
    await waitFor(() => expect(screen.queryByRole('option', { name: 'Nova' })).toBeNull());
    expect(select).toHaveValue('17');
    expect(storiesApi.updateAuthorIdentity).not.toHaveBeenCalled();
  });

  it('preserves every follow-up detail edit while a save is in flight and resubmits the visible draft', async () => {
    const initial = await storiesApi.get(3);
    jest.mocked(storiesApi.get).mockResolvedValueOnce({ ...initial, involvable_options: [{ type: 'user', id: 8, name: 'Another author' }] });
    const pending = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(pending.promise);
    renderEditor();
    const title = await screen.findByRole('textbox', { name: 'Title' });
    fireEvent.change(title, { target: { value: 'Submitted title' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    fireEvent.change(title, { target: { value: 'Follow-up title' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Story (markdown)' }), { target: { value: 'Follow-up body' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'published' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Who can see this?' }), { target: { value: 'followers' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /List in discovery/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Another author' }));
    fireEvent.click(screen.getByRole('button', { name: 'Toggle interest' }));
    await act(async () => pending.resolve({ ...initial, title: 'Canonical submitted title' }));
    expect(title).toHaveValue('Follow-up title');
    expect(screen.getByRole('textbox', { name: 'Story (markdown)' })).toHaveValue('Follow-up body');
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveValue('published');
    expect(screen.getByRole('combobox', { name: 'Who can see this?' })).toHaveValue('followers');
    expect(screen.getByRole('checkbox', { name: /List in discovery/ })).not.toBeChecked();
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
    const next = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(next.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    expect(storiesApi.update).toHaveBeenLastCalledWith(3, expect.objectContaining({ title: 'Follow-up title', body: 'Follow-up body', status: 'published', audience: 'followers', discoverable: false, interest_ids: [5], involvements: [{ type: 'user', id: 8 }] }));
    await act(async () => next.resolve({ ...initial, title: 'Follow-up title', body: 'Follow-up body', status: 'published', audience: 'followers', discoverable: false, interests: [{ id: 5, name: 'Interest' }], involves: [{ type: 'user', id: 8, name: 'Another author' }] }));
    expect(screen.getByText('Saved.')).toBeInTheDocument();
    fireEvent.change(title, { target: { value: 'Another unsaved title' } });
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
    expect(screen.queryByText('Saved.')).not.toBeInTheDocument();
  });

  it('adopts server-canonical unchanged fields without overwriting a newer field', async () => {
    const initial = await storiesApi.get(3);
    const pending = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(pending.promise);
    renderEditor();
    const title = await screen.findByRole('textbox', { name: 'Title' });
    fireEvent.change(title, { target: { value: ' Submitted ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Story (markdown)' }), { target: { value: 'New body' } });
    await act(async () => pending.resolve({ ...initial, title: 'Submitted' }));
    expect(title).toHaveValue('Submitted');
    expect(screen.getByRole('textbox', { name: 'Story (markdown)' })).toHaveValue('New body');
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
  });

  it('preserves dirty details when a real graph save completes, and preserves a dirty graph when details save', async () => {
    const initial = { ...await storiesApi.get(3), type: 'cyoa' as const, nodes: [{ id: 11, key: 'start', title: 'Start', body: 'Passage', is_start: true, position_x: 24, position_y: 24 }] };
    jest.mocked(storiesApi.get).mockResolvedValueOnce(initial);
    const graph = deferredStory();
    jest.mocked(storiesApi.saveGraph).mockReturnValueOnce(graph.promise);
    const onChanged = jest.fn();
    renderEditor(onChanged);
    const title = await screen.findByRole('textbox', { name: 'Title' });
    fireEvent.change(title, { target: { value: 'Unsaved story title' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    await act(async () => graph.resolve({ ...initial, nodes: [{ ...initial.nodes[0]!, title: 'Canonical passage' }] }));
    expect(title).toHaveValue('Unsaved story title');
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Passage title')).toHaveValue('Canonical passage');
    expect(onChanged).toHaveBeenCalledTimes(1);
    fireEvent.change(screen.getByPlaceholderText('Passage title'), { target: { value: 'Unsaved passage' } });
    const details = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(details.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await act(async () => details.resolve({ ...initial, title: 'Unsaved story title' }));
    expect(screen.getByPlaceholderText('Passage title')).toHaveValue('Unsaved passage');
    expect(screen.getByText('Unsaved graph changes')).toBeInTheDocument();
    expect(screen.getByText('Saved.')).toBeInTheDocument();
  });

  it('keeps the draft and error feedback after a failed save', async () => {
    const pending = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(pending.promise);
    renderEditor();
    const title = await screen.findByRole('textbox', { name: 'Title' });
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    fireEvent.change(title, { target: { value: 'Still here' } });
    await act(async () => pending.reject('Synthetic save failure'));
    expect(title).toHaveValue('Still here');
    expect(screen.getByText('Synthetic save failure')).toBeInTheDocument();
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
  });

  it('treats interest and involvement selections as unordered before and during a save', async () => {
    const initial = { ...await storiesApi.get(3), interests: [{ id: 5, name: 'First interest' }, { id: 9, name: 'Second interest' }],
      involves: [{ type: 'user' as const, id: 8, name: 'First person' }, { type: 'user' as const, id: 10, name: 'Second person' }],
      involvable_options: [{ type: 'user' as const, id: 8, name: 'First person' }, { type: 'user' as const, id: 10, name: 'Second person' }] };
    jest.mocked(storiesApi.get).mockResolvedValueOnce(initial);
    renderEditor();
    await screen.findByRole('textbox', { name: 'Title' });
    function reselectFirst(): void {
      fireEvent.click(screen.getByRole('button', { name: 'Toggle interest' }));
      fireEvent.click(screen.getByRole('button', { name: 'Toggle interest' }));
      fireEvent.click(screen.getByRole('checkbox', { name: 'First person' }));
      fireEvent.click(screen.getByRole('checkbox', { name: 'First person' }));
    }
    reselectFirst();
    expect(screen.queryByText('Unsaved details.')).not.toBeInTheDocument();
    const pending = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(pending.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    reselectFirst();
    await act(async () => pending.resolve(initial));
    expect(screen.getByText('Saved.')).toBeInTheDocument();
    expect(screen.queryByText('Unsaved details.')).not.toBeInTheDocument();
  });

  it('refreshes clean details from graph responses while retaining dirty fields and resubmitting canonical values', async () => {
    const initial = { ...await storiesApi.get(3), type: 'cyoa' as const };
    jest.mocked(storiesApi.get).mockResolvedValueOnce(initial);
    renderEditor();
    const title = await screen.findByRole('textbox', { name: 'Title' });
    fireEvent.change(title, { target: { value: 'My unsaved title' } });
    const graph = deferredStory();
    jest.mocked(storiesApi.saveGraph).mockReturnValueOnce(graph.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    await act(async () => graph.resolve({ ...initial, title: 'Co-author title', audience: 'followers', discoverable: false }));
    expect(title).toHaveValue('My unsaved title');
    expect(screen.getByRole('combobox', { name: 'Who can see this?' })).toHaveValue('followers');
    expect(screen.getByRole('checkbox', { name: /List in discovery/ })).not.toBeChecked();
    expect(screen.getByText('Unsaved details.')).toBeInTheDocument();
    const details = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(details.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    expect(storiesApi.update).toHaveBeenLastCalledWith(3, expect.objectContaining({ title: 'My unsaved title', audience: 'followers', discoverable: false }));
    await act(async () => details.resolve({ ...initial, title: 'My unsaved title', audience: 'followers', discoverable: false }));
    expect(screen.getByText('Saved.')).toBeInTheDocument();
  });

  it('keeps a newer completed details save and its clean baseline when an older graph response arrives last', async () => {
    const initial = { ...await storiesApi.get(3), type: 'cyoa' as const };
    jest.mocked(storiesApi.get).mockResolvedValueOnce(initial);
    renderEditor();
    const title = await screen.findByRole('textbox', { name: 'Title' });
    const graph = deferredStory();
    jest.mocked(storiesApi.saveGraph).mockReturnValueOnce(graph.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    fireEvent.change(title, { target: { value: 'My newer saved title' } });
    const details = deferredStory();
    jest.mocked(storiesApi.update).mockReturnValueOnce(details.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await act(async () => details.resolve({ ...initial, title: 'My newer saved title' }));
    expect(screen.getByText('Saved.')).toBeInTheDocument();
    await act(async () => graph.resolve({ ...initial, title: 'Older co-author title', audience: 'followers' }));
    expect(title).toHaveValue('My newer saved title');
    expect(screen.getByRole('combobox', { name: 'Who can see this?' })).toHaveValue('everyone');
    expect(screen.getByText('Saved.')).toBeInTheDocument();
    expect(screen.queryByText('Unsaved details.')).not.toBeInTheDocument();
  });
});
