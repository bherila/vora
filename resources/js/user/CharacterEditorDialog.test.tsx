import { fireEvent, render, screen } from '@testing-library/react';

import { CharacterEditorDialog } from '@/user/CharacterEditorDialog';

describe('CharacterEditorDialog', () => {
  it('states the end-state consequences without assuming the persona’s pronouns', () => {
    render(
      <CharacterEditorDialog
        open
        onOpenChange={jest.fn()}
        editing={null}
        onSaved={jest.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Marcus' } });

    expect(screen.getByText(
      "People visiting Marcus can see this persona is yours, and anyone who follows you will also see Marcus's followers-only posts.",
    )).toBeInTheDocument();
    expect(screen.getByText(
      'Nobody can tell Marcus is yours. Marcus builds a following from scratch.',
    )).toBeInTheDocument();
    expect(screen.queryByText(/\b(?:she|her|he|him|his)\b/i)).toBeNull();
  });
});
