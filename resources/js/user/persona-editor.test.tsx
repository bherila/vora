import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { type CharacterRecord, PersonaEditorPage } from '@/user/persona-editor';

const mockPost = jest.fn();
const mockPatch = jest.fn();
let mockRestrictions: Array<{ capability: string; label: string; reason: string | null; expires_at: string | null }> = [];

jest.mock('@/initialData', () => ({
  readInitialData: () => ({ restrictions: mockRestrictions }),
}));

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
}));

jest.mock('@/community/AudienceField', () => ({
  AudienceField: ({ specificRelationship }: { specificRelationship: string }) => (
    <div data-testid="audience-field" data-specific-relationship={specificRelationship} />
  ),
}));

jest.mock('@/interests/character-interests-editor', () => ({
  CharacterInterestsEditor: ({ characterId }: { characterId: number }) => (
    <div data-testid="character-interests" data-character-id={characterId} />
  ),
}));

/** Pronouns that must never appear in name-interpolated persona copy (#132). */
const GENDERED = /\b(she|her|hers|he|him|his)\b/i;

const EDITING: CharacterRecord = {
  id: 9,
  ulid: '01HZX5PERSONA',
  display_name: 'Kira',
  description: 'A wandering star-cartographer.',
  is_linked: true,
  audience: 'everyone',
  audience_user_ids: [],
  discoverable: false,
  gender: 'female',
  gender_other: null,
  user_type: 'furry',
  user_type_other: null,
  inherit_interests: true,
  profile_picture: null,
};

function renderEditor(
  initialCharacter: CharacterRecord | null = null,
  navigate: (href: string) => void = jest.fn(),
) {
  render(<PersonaEditorPage initialCharacter={initialCharacter} navigate={navigate} />);
}

function relationshipCopy(): string {
  return screen.getByRole('group', { name: 'Connection to your profile' }).textContent ?? '';
}

describe('PersonaEditorPage', () => {
  beforeAll(() => {
    Object.defineProperty(window, 'PointerEvent', {
      configurable: true,
      value: MouseEvent,
    });
  });

  beforeEach(() => {
    mockRestrictions = [];
    mockPost.mockReset();
    mockPatch.mockReset();
  });

  it('replaces the persona picture picker when media uploads are restricted', () => {
    mockRestrictions = [{
      capability: 'media.upload',
      label: 'Media uploads',
      reason: 'Safety review',
      expires_at: null,
    }];

    renderEditor(EDITING);

    expect(screen.queryByLabelText('Profile picture')).not.toBeInTheDocument();
    expect(screen.getByText(/Media uploads restricted/)).toBeInTheDocument();
  });

  it('preserves the settled Linked and Separate copy without gendered pronouns', () => {
    renderEditor();

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Marcus' } });

    expect(screen.getByText(
      "People visiting Marcus can see this persona is yours, and anyone who follows you will also see Marcus's followers-only posts.",
    )).toBeInTheDocument();
    expect(screen.getByText(
      'Nobody can tell Marcus is yours. Marcus builds a following from scratch.',
    )).toBeInTheDocument();
    expect(relationshipCopy()).not.toMatch(GENDERED);
  });

  it.each([
    ['a neutral name', 'Vex'],
    ['no name yet (the placeholder)', ''],
  ])('keeps the pronoun guard for %s', (_label, name) => {
    renderEditor();

    if (name !== '') {
      fireEvent.change(screen.getByLabelText('Display name'), { target: { value: name } });
    }

    expect(relationshipCopy()).not.toMatch(GENDERED);
  });

  it('keeps persona discovery independent and the audience allowlist mutual-only', () => {
    renderEditor();

    const discoverable = screen.getByRole('checkbox', {
      name: 'Show this persona in Explore and People search',
    });

    expect(discoverable).toBeChecked();
    expect(screen.getByText(
      "When the audience is Everyone, this lists the persona in Explore and People search. Turning it off removes those listings; a Linked persona may still appear on its owner's profile.",
    )).toBeInTheDocument();
    expect(screen.getByTestId('audience-field')).toHaveAttribute('data-specific-relationship', 'mutuals');
    expect(screen.queryByRole('group', { name: 'User types to see' })).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Genders to see' })).not.toBeInTheDocument();

    fireEvent.click(discoverable);
    expect(discoverable).not.toBeChecked();
  });

  it('redirects a newly saved persona to its stable edit page', async () => {
    const navigate = jest.fn();
    mockPost.mockResolvedValue({
      success: true,
      data: { ...EDITING, display_name: 'Nova', discoverable: true },
    });
    renderEditor(null, navigate);

    expect(screen.queryByTestId('character-interests')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Nova' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save character' }));

    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/characters', expect.objectContaining({
      display_name: 'Nova',
      discoverable: true,
    })));
    expect(mockPost.mock.calls[0]?.[1]).not.toHaveProperty('preferred_user_types');
    expect(mockPost.mock.calls[0]?.[1]).not.toHaveProperty('preferred_genders');
    expect(navigate).toHaveBeenCalledWith('/c/01HZX5PERSONA/edit');
  });

  it('keeps Save and add another as a redirect to a fresh page', async () => {
    const navigate = jest.fn();
    mockPost.mockResolvedValue({ success: true, data: EDITING });
    renderEditor(null, navigate);

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Kira' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save & add another' }));

    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/personas/new'));
  });

  it('loads avatar and interests immediately on the edit page and saves in place', async () => {
    const navigate = jest.fn();
    mockPatch.mockResolvedValue({
      success: true,
      data: { ...EDITING, description: 'Updated.' },
    });
    renderEditor(EDITING, navigate);

    expect(screen.getByLabelText('Profile picture')).toBeInTheDocument();
    expect(screen.getByTestId('character-interests')).toHaveAttribute('data-character-id', '9');
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Updated.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save character' }));

    await waitFor(() => expect(mockPatch).toHaveBeenCalledWith(
      '/api/characters/9',
      expect.objectContaining({ description: 'Updated.', discoverable: false }),
    ));
    expect(navigate).not.toHaveBeenCalled();
    expect(await screen.findByText('Persona saved.')).toBeInTheDocument();
  });
});
