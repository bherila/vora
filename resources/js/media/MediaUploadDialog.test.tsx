import '@testing-library/jest-dom';

import { fireEvent, render, screen } from '@testing-library/react';

import { MediaUploadDialog } from './MediaUploadDialog';

describe('MediaUploadDialog announcements', () => {
  beforeEach(() => {
    globalThis.fetch = jest.fn().mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => '{"data":[]}',
    } as Response);
  });

  it('defaults the announcement checkbox on and explains its effect', async () => {
    render(
      <MediaUploadDialog
        characters={[]}
        lastInterestIds={[]}
        onUploaded={() => {}}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));

    expect(await screen.findByRole('checkbox', {
      name: "Share this to your followers' feeds. Unchecking keeps the upload private to your profile.",
    })).toBeChecked();
  });
});
