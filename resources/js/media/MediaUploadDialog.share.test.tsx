import { render, screen, within } from '@testing-library/react';
import { StrictMode } from 'react';

import { MediaUploadDialog } from './MediaUploadDialog';

jest.mock('@/initialData', () => ({ readInitialData: () => ({}) }));
jest.mock('@/components/interest-picker', () => ({ InterestPicker: () => null }));
jest.mock('@/components/media/FileDropzone', () => ({
  FileDropzone: ({ files }: { files: File[] }) => <ul aria-label="Queued files">{files.map((file) => <li key={file.name}>{file.name}</li>)}</ul>,
}));

const events = [{ ulid: 'event-one', name: 'Reception', visibility: 'everyone' as const }];

describe('MediaUploadDialog shared files', () => {
  it('queues each shared selection once when React replays mounting effects', () => {
    const shared = [new File(['photo'], 'shared.jpg', { type: 'image/jpeg' })];
    render(<StrictMode><MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={jest.fn()} sharedFiles={shared} /></StrictMode>);
    expect(within(screen.getByRole('list', { name: 'Queued files' })).getAllByRole('listitem')).toHaveLength(1);
  });

  it('opens with files shared from another app queued, and stays closed without them', () => {
    const { rerender } = render(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={jest.fn()} eventOptions={events} sharedFiles={[]} />);
    expect(screen.queryByRole('dialog')).toBeNull();

    const shared = [new File(['a'], 'IMG_0001.HEIC', { type: 'image/heic' }), new File(['b'], 'IMG_0002.MOV', { type: 'video/quicktime' })];
    rerender(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={jest.fn()} eventOptions={events} sharedFiles={shared} />);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Queued files' })).toHaveTextContent('IMG_0001.HEICIMG_0002.MOV');
  });
});
