import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { toast } from 'sonner';

import { fetchWrapper } from '@/fetchWrapper';
import { MomentCapture } from '@/media/MomentCapture';
import { captureFrame, listMoments, saveMoment } from '@/media/moments';
import { MomentsStrip } from '@/media/MomentsStrip';
import type { VideoMoment } from '@/media/types';
import { VideoPlayback } from '@/media/videoPlayback';
import { createFakeVideo } from '@/media/videoPlaybackTestUtils';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), delete: jest.fn() } }));
jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

function moment(overrides: Partial<VideoMoment> = {}): VideoMoment {
  return {
    ulid: '01MOMENT', time_ms: 61_068, caption: null, visibility: 'shared', frame_status: 'ready',
    is_mine: false, can_edit: false, can_delete: false, can_use_as_cover: false,
    author: { id: 9, display_name: 'Aunt May', avatar_url: null },
    image: { full_url: '/api/moments/01MOMENT/image/full', thumb_url: '/api/moments/01MOMENT/image/thumb', provisional: false },
    created_at: null,
    ...overrides,
  };
}

const fetchMock = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = fetchMock as unknown as typeof fetch;
});

describe('listMoments', () => {
  it('follows the cursor to the last page', async () => {
    jest.mocked(fetchWrapper.get)
      .mockResolvedValueOnce({ data: [moment({ ulid: 'A' })], next_cursor: 'page 2' })
      .mockResolvedValueOnce({ data: [moment({ ulid: 'B' })], next_cursor: null });

    await expect(listMoments('01VIDEO')).resolves.toMatchObject([{ ulid: 'A' }, { ulid: 'B' }]);
    expect(fetchWrapper.get).toHaveBeenNthCalledWith(1, '/api/media/by-ulid/01VIDEO/moments');
    expect(fetchWrapper.get).toHaveBeenNthCalledWith(2, '/api/media/by-ulid/01VIDEO/moments?cursor=page%202');
  });
});

describe('saveMoment', () => {
  const fields = { time_ms: 1000, caption: null, visibility: 'shared' as const };

  it('saves without a capture when none is available', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValueOnce({ data: moment(), provisional_upload: null });

    await expect(saveMoment('01VIDEO', fields, null)).resolves.toMatchObject({ ulid: '01MOMENT' });
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/media/by-ulid/01VIDEO/moments', { ...fields, wants_provisional: false });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('uploads the capture and completes it', async () => {
    const blob = new Blob(['jpeg'], { type: 'image/jpeg' });
    jest.mocked(fetchWrapper.post)
      .mockResolvedValueOnce({ data: moment({ frame_status: 'pending', image: null }), provisional_upload: { url: 'https://upload.example/x', headers: { 'Content-Type': 'image/jpeg' } } })
      .mockResolvedValueOnce({ data: moment({ frame_status: 'pending', image: { full_url: 'f', thumb_url: 't', provisional: true } }) });
    fetchMock.mockResolvedValueOnce({ ok: true });

    const saved = await saveMoment('01VIDEO', fields, blob);

    expect(fetchWrapper.post).toHaveBeenNthCalledWith(1, '/api/media/by-ulid/01VIDEO/moments', { ...fields, wants_provisional: true, capture_bytes: 4 });
    expect(fetchMock).toHaveBeenCalledWith('https://upload.example/x', { method: 'PUT', headers: { 'Content-Type': 'image/jpeg' }, body: blob });
    expect(fetchWrapper.post).toHaveBeenLastCalledWith('/api/moments/01MOMENT/provisional/complete', {});
    expect(saved.image?.provisional).toBe(true);
  });

  it('keeps the saved moment when the capture upload fails', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValueOnce({ data: moment({ frame_status: 'pending', image: null }), provisional_upload: { url: 'u', headers: {} } });
    fetchMock.mockRejectedValueOnce(new Error('offline'));

    await expect(saveMoment('01VIDEO', fields, new Blob(['x']))).resolves.toMatchObject({ image: null });
    expect(fetchWrapper.post).toHaveBeenCalledTimes(1);
  });

  it('cannot capture a frame before the video has one', async () => {
    await expect(captureFrame(document.createElement('video'))).resolves.toBeNull();
  });
});

describe('MomentCapture', () => {
  function setup() {
    const fake = createFakeVideo();
    const levels = { levels: [{ height: 360 }, { height: 1080 }, { height: 720 }], currentLevel: -1 };
    const playback = new VideoPlayback(fake.video, levels, 25);
    return { fake, levels, playback };
  }

  it('pauses, pins the top quality while open, and releases it on close', () => {
    const { fake, levels, playback } = setup();
    fake.setPaused(false);
    const { unmount } = render(<MomentCapture mediaUlid="01VIDEO" playback={playback} onSaved={jest.fn()} onClose={jest.fn()} />);

    expect(fake.video.pause).toHaveBeenCalled();
    expect(levels.currentLevel).toBe(1);
    unmount();
    expect(levels.currentLevel).toBe(-1);
  });

  it('saves the presented frame with its caption and visibility', async () => {
    const { fake, playback } = setup();
    const onSaved = jest.fn();
    jest.mocked(fetchWrapper.post).mockResolvedValueOnce({ data: moment({ ulid: '01NEW' }), provisional_upload: null });
    render(<MomentCapture mediaUlid="01VIDEO" playback={playback} onSaved={onSaved} onClose={jest.fn()} />);
    act(() => fake.setTime(61.0687));

    fireEvent.change(screen.getByLabelText('Caption (optional)'), { target: { value: '  Candles  ' } });
    fireEvent.click(screen.getByLabelText('Only me'));
    fireEvent.click(screen.getByRole('button', { name: /Save moment/ }));

    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ ulid: '01NEW' }), null));
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/media/by-ulid/01VIDEO/moments', {
      time_ms: 61_068, caption: 'Candles', visibility: 'private', wants_provisional: false,
    });
  });

  it('shows a save error', async () => {
    const { playback } = setup();
    jest.mocked(fetchWrapper.post).mockRejectedValueOnce('This video has reached its limit of saved moments.');
    render(<MomentCapture mediaUlid="01VIDEO" playback={playback} onSaved={jest.fn()} onClose={jest.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: /Save moment/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('limit of saved moments');
  });
});

describe('MomentsStrip', () => {
  function renderStrip(moments: VideoMoment[], extra: { onMakeChapter?: jest.Mock; previews?: Map<string, string> } = {}) {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video);
    const onChange = jest.fn();
    const onRemove = jest.fn();
    render(
      <MomentsStrip
        mediaUlid="01VIDEO"
        playback={playback}
        moments={moments}
        localPreviews={extra.previews ?? new Map()}
        onChange={onChange}
        onRemove={onRemove}
        onMakeChapter={extra.onMakeChapter}
      />,
    );
    return { fake, onChange, onRemove };
  }

  it('shows nothing without moments', () => {
    const { container } = render(<MomentsStrip mediaUlid="01V" playback={new VideoPlayback(createFakeVideo().video)} moments={[]} localPreviews={new Map()} onChange={jest.fn()} onRemove={jest.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('shows ready frames, placeholders for others while preparing, and the author their own capture', () => {
    renderStrip([
      moment(),
      moment({ ulid: '01PENDING', time_ms: 5000, frame_status: 'pending', image: null }),
      moment({ ulid: '01MINE', time_ms: 9000, frame_status: 'pending', image: null, is_mine: true, visibility: 'private' }),
      moment({ ulid: '01FAILED', time_ms: 12_000, frame_status: 'failed', image: null }),
    ], { previews: new Map([['01MINE', 'blob:local']]) });

    const items = screen.getAllByRole('listitem');
    expect(items).toHaveLength(4);
    // Thumbnails are decorative inside a labelled button.
    expect(items[0]!.querySelector('img')).toHaveAttribute('src', '/api/moments/01MOMENT/image/thumb');
    expect(within(items[1]!).getByText('Frame being prepared')).toBeInTheDocument();
    expect(items[2]!.querySelector('img')).toHaveAttribute('src', 'blob:local');
    expect(within(items[2]!).getByText('Only you')).toBeInTheDocument();
    expect(within(items[3]!).getByText('Frame unavailable')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Mine' }));
    expect(screen.getAllByRole('listitem')).toHaveLength(1);
  });

  it('seeks a millisecond into the frame', () => {
    const { fake } = renderStrip([moment()]);
    fireEvent.click(screen.getByRole('button', { name: 'Play from 1:01' }));
    expect(fake.seeks.at(-1)).toBeCloseTo(61.069);
  });

  it('copies a link to the moment\'s own millisecond', async () => {
    const writeText = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    renderStrip([moment({ time_ms: 12_345 })]);

    fireEvent.click(screen.getByRole('button', { name: 'Open the moment at 0:12' }));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Copy link' }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(expect.stringMatching(/#t=12\.346s$/)));
  });

  it('lets the author caption, share, and remove a moment', async () => {
    const mine = moment({ is_mine: true, can_edit: true, can_delete: true });
    const { onChange, onRemove } = renderStrip([mine]);
    jest.mocked(fetchWrapper.patch).mockResolvedValue({ data: { ...mine, caption: 'Cake' } });
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });

    fireEvent.click(screen.getByRole('button', { name: 'Open the moment at 1:01' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Caption'), { target: { value: ' Cake ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save caption' }));
    await waitFor(() => expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/moments/01MOMENT', { caption: 'Cake' }));
    expect(onChange).toHaveBeenCalled();

    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Make private' })).toBeEnabled());
    fireEvent.click(within(dialog).getByRole('button', { name: 'Make private' }));
    await waitFor(() => expect(fetchWrapper.patch).toHaveBeenLastCalledWith('/api/moments/01MOMENT', { visibility: 'private' }));

    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Remove' })).toBeEnabled());
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove' }));
    await waitFor(() => expect(onRemove).toHaveBeenCalledWith('01MOMENT'));
    expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/moments/01MOMENT');
  });

  it('offers the uploader a cover and a chapter, and the author a retry', async () => {
    const onMakeChapter = jest.fn();
    renderStrip([moment({ can_use_as_cover: true }), moment({ ulid: '01FAILED', time_ms: 3000, is_mine: true, frame_status: 'failed', image: null })], { onMakeChapter });
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true, data: moment({ ulid: '01FAILED', frame_status: 'pending', image: null }) });

    fireEvent.click(screen.getByRole('button', { name: 'Open the moment at 1:01' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use as cover' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/media/by-ulid/01VIDEO/cover', { moment: '01MOMENT' }));
    expect(toast.success).toHaveBeenCalledWith('Cover updated.');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Start a chapter here' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Start a chapter here' }));
    expect(onMakeChapter).toHaveBeenCalledWith(expect.objectContaining({ ulid: '01MOMENT' }));

    fireEvent.click(screen.getByRole('button', { name: 'Open the moment at 0:03' }));
    expect(screen.getByText('The frame could not be prepared.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenLastCalledWith('/api/moments/01FAILED/retry', {}));
  });

  it('hides actions the viewer may not take', () => {
    renderStrip([moment()]);
    fireEvent.click(screen.getByRole('button', { name: 'Open the moment at 1:01' }));
    const dialog = screen.getByRole('dialog');
    for (const name of ['Remove', 'Use as cover', 'Start a chapter here', 'Try again', 'Save caption']) {
      expect(within(dialog).queryByRole('button', { name })).toBeNull();
    }
    expect(within(dialog).getByRole('link', { name: 'Download' })).toHaveAttribute('href', '/api/moments/01MOMENT/image/full');
  });
});
