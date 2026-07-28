import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { hydrateIdentityStore, removeIdentityOption, upsertIdentityOption } from '@/identity';

import { storiesApi } from './api';
import { StoryEditorPanel } from './StoryEditorPanel';

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

jest.mock('@/components/interest-picker', () => ({ InterestPicker: () => null }));
jest.mock('@/components/Markdown', () => ({ Markdown: () => null }));
jest.mock('./CoAuthorPanel', () => ({ CoAuthorPanel: () => null }));
jest.mock('./CyoaGraphEditor', () => ({ CyoaGraphEditor: () => null }));

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
  },
}));

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
});
