import '@testing-library/jest-dom';

import { fireEvent, render, screen } from '@testing-library/react';

import { MediaUploadDialog } from './MediaUploadDialog';

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
jest.mock('@/components/media/FileDropzone', () => ({ FileDropzone: () => null }));

const characters = [{
  id: 17,
  display_name: 'Kira',
  audience: 'followers' as const,
  audience_user_ids: [],
}];

describe('MediaUploadDialog identity and announcement defaults', () => {
  beforeEach(() => {
    globalThis.fetch = jest.fn().mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => '{"data":[]}',
    } as Response);
  });

  it('opens with the server-hydrated active identity selected', () => {
    render(
      <MediaUploadDialog
        characters={characters}
        lastInterestIds={[]}
        onUploaded={jest.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));

    expect(screen.getByRole('combobox', { name: 'Character' })).toHaveValue('17');
    expect(screen.getByText("This upload uses the selected character's privacy setting.")).toBeInTheDocument();
  });

  it('defaults the announcement checkbox on and explains its effect', async () => {
    render(
      <MediaUploadDialog
        characters={characters}
        lastInterestIds={[]}
        onUploaded={jest.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));

    expect(await screen.findByRole('checkbox', {
      name: "Share this to your followers' feeds. Unchecking keeps the upload private to your profile.",
    })).toBeChecked();
  });
});
