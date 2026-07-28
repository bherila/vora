import { fireEvent, render, screen } from '@testing-library/react';

import { CharacterEditorDialog } from '@/user/CharacterEditorDialog';

describe('CharacterEditorDialog', () => {
  it('states the end-state consequences of Linked and Separate personas', () => {
    render(
      <CharacterEditorDialog
        open
        onOpenChange={jest.fn()}
        editing={null}
        onSaved={jest.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Kira' } });

    expect(screen.getByText(
      "People visiting Kira can see she's yours, and anyone who follows you will also see Kira's followers-only posts.",
    )).toBeInTheDocument();
    expect(screen.getByText(
      'Nobody can tell Kira is yours. She builds her own following from scratch.',
    )).toBeInTheDocument();
  });
});
