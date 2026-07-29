import { fireEvent, render, screen } from '@testing-library/react';

import { CharacterEditorDialog } from '@/user/CharacterEditorDialog';

/** Pronouns that must never appear in name-interpolated persona copy (#132). */
const GENDERED = /\b(she|her|hers|he|him|his)\b/i;

function renderDialog() {
  render(
    <CharacterEditorDialog
      open
      onOpenChange={jest.fn()}
      editing={null}
      onSaved={jest.fn()}
    />,
  );
}

function relationshipCopy(): string {
  return screen.getByRole('group', { name: 'Connection to your profile' }).textContent ?? '';
}

describe('CharacterEditorDialog', () => {
  beforeAll(() => {
    Object.defineProperty(window, 'PointerEvent', {
      configurable: true,
      value: MouseEvent,
    });
  });

  it('states the end-state consequences of Linked and Separate personas', () => {
    renderDialog();

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Kira' } });

    expect(screen.getByText(
      "People visiting Kira can see this persona is yours, and anyone who follows you will also see Kira's followers-only posts.",
    )).toBeInTheDocument();
    expect(screen.getByText(
      'Nobody can tell Kira is yours. Kira builds a following from scratch.',
    )).toBeInTheDocument();
  });

  it('offers independent listing control without persona viewing preferences', () => {
    renderDialog();

    const discoverable = screen.getByRole('checkbox', {
      name: 'Show this persona in Explore and People search',
    });

    expect(discoverable).toBeChecked();
    expect(screen.getByText(
      'When the audience is Everyone, this lists the persona for discovery. Turn it off to keep the persona reachable only by direct link.',
    )).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'User types to see' })).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Genders to see' })).not.toBeInTheDocument();

    fireEvent.click(discoverable);
    expect(discoverable).not.toBeChecked();
  });

  // The persona name is arbitrary and a persona carries its own gender, so the
  // copy must not assert one. Regression guard for #132, where the name was
  // interpolated but "she"/"She" was hardcoded.
  it.each([
    ['a masculine name', 'Marcus'],
    ['a neutral name', 'Vex'],
    ['no name yet (the placeholder)', ''],
  ])('uses no gendered pronoun for %s', (_label, name) => {
    renderDialog();

    if (name !== '') {
      fireEvent.change(screen.getByLabelText('Display name'), { target: { value: name } });
    }

    expect(relationshipCopy()).not.toMatch(GENDERED);
  });
});
