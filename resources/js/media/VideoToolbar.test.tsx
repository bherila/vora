import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { toast } from 'sonner';

import { VideoPlayback } from '@/media/videoPlayback';
import { createFakeVideo, type FakeVideo } from '@/media/videoPlaybackTestUtils';
import { isShortcutBlocked, VideoToolbar } from '@/media/VideoToolbar';

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const KEY = 'vora:video-position:01VIDEO';

function setup(options: { duration?: number; readyState?: number } = {}): { fake: FakeVideo; playback: VideoPlayback } {
  const fake = createFakeVideo(options);
  const playback = new VideoPlayback(fake.video, { levels: [{ height: 480, frameRate: 25 }] });
  return { fake, playback };
}

beforeEach(() => {
  jest.clearAllMocks();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/media/01VIDEO');
});

it('shows the timecode and duration and steps by frame and second', () => {
  const { fake, playback } = setup({ duration: 3750 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);
  act(() => fake.setTime(62.48));

  expect(screen.getByText('00:01:02.12')).toBeInTheDocument();
  expect(screen.getByText('/ 1:02:30')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Next frame' }));
  expect(fake.seeks.at(-1)).toBeCloseTo(62.48 + 1.25 / 25);
  fireEvent.click(screen.getByRole('button', { name: 'Forward one second' }));
  expect(fake.seeks.at(-1)).toBeCloseTo(62.48 + 1.25 / 25 + 1);
  fireEvent.click(screen.getByRole('button', { name: 'Back one second' }));
  fireEvent.click(screen.getByRole('button', { name: 'Previous frame' }));
  expect(fake.seeks).toHaveLength(4);
});

it('handles keyboard shortcuts outside text fields and dialogs', () => {
  const { fake, playback } = setup();
  render(
    <>
      <VideoToolbar playback={playback} mediaUlid="01VIDEO" />
      <input aria-label="Comment" />
      <div role="dialog"><button type="button">Inside dialog</button></div>
    </>,
  );
  act(() => fake.setTime(10));

  fireEvent.keyDown(document.body, { key: '.' });
  expect(fake.seeks.at(-1)).toBeCloseTo(10.05);
  fireEvent.keyDown(document.body, { key: '>' });
  expect(fake.seeks).toHaveLength(2);

  fireEvent.keyDown(screen.getByLabelText('Comment'), { key: '.' });
  fireEvent.keyDown(screen.getByText('Inside dialog'), { key: ',' });
  fireEvent.keyDown(document.body, { key: '.', ctrlKey: true });
  fireEvent.keyDown(document.body, { key: 'x' });
  expect(fake.seeks).toHaveLength(2);
});

it('recognizes blocked shortcut targets', () => {
  const editable = document.createElement('div');
  editable.setAttribute('contenteditable', '');
  document.body.append(editable);

  expect(isShortcutBlocked(null)).toBe(false);
  expect(isShortcutBlocked(document.body)).toBe(false);
  expect(isShortcutBlocked(document.createElement('textarea'))).toBe(true);
  expect(isShortcutBlocked(editable)).toBe(true);
  editable.remove();
});

it('seeks to a #t= deep link once metadata loads, and on later fragment changes', async () => {
  window.history.replaceState(null, '', '/media/01VIDEO#t=1m30s');
  const { fake, playback } = setup({ readyState: 0 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);

  expect(fake.seeks).toEqual([]);
  act(() => {
    fake.video.dispatchEvent(new Event('loadedmetadata'));
  });
  expect(fake.seeks).toEqual([90]);

  fake.setReadyState(4);
  act(() => {
    window.location.hash = '#t=2h';
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  });
  await waitFor(() => expect(fake.seeks.at(-1)).toBe(600));
});

it('copies a link to the current second', async () => {
  const writeText = jest.fn().mockResolvedValue(undefined);
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
  const { fake, playback } = setup();
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);
  act(() => fake.setTime(90.7));

  fireEvent.click(screen.getByRole('button', { name: /Copy link at this time/ }));

  await waitFor(() => expect(writeText).toHaveBeenCalledWith('http://localhost/media/01VIDEO#t=1m30s'));
  expect(toast.success).toHaveBeenCalledWith('Link copied.');

  writeText.mockRejectedValueOnce(new Error('denied'));
  fireEvent.click(screen.getByRole('button', { name: /Copy link at this time/ }));
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Could not copy the link.'));
});

it('defers a resume seek until metadata loads', () => {
  window.localStorage.setItem(KEY, JSON.stringify({ t: 2472, at: Date.now() }));
  const { fake, playback } = setup({ duration: 3600, readyState: 0 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);

  fireEvent.click(screen.getByRole('button', { name: 'Resume at 41:12' }));
  expect(fake.seeks).toEqual([]);
  act(() => {
    fake.video.dispatchEvent(new Event('loadedmetadata'));
  });
  expect(fake.seeks).toEqual([2472]);
});

it('navigates chapters with buttons and [ ] keys and shows the current title', () => {
  const chapters = [{ start_ms: 0, title: 'Christmas' }, { start_ms: 60_000, title: 'Birthday' }];
  const { fake, playback } = setup({ duration: 300 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" chapters={chapters} />);
  act(() => fake.setTime(30));

  expect(screen.getByText('Christmas')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Next chapter' }));
  expect(fake.seeks.at(-1)).toBe(60);
  act(() => fake.setTime(75));
  expect(screen.getByText('Birthday')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Next chapter' })).toBeDisabled();

  fireEvent.keyDown(document.body, { key: '[' });
  expect(fake.seeks.at(-1)).toBe(60);
  act(() => fake.setTime(61));
  fireEvent.keyDown(document.body, { key: '[' });
  expect(fake.seeks.at(-1)).toBe(0);
  fireEvent.keyDown(document.body, { key: ']' });
  expect(fake.seeks.at(-1)).toBe(60);
});

it('hides chapter controls without chapters', () => {
  const { playback } = setup();
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);
  expect(screen.queryByRole('button', { name: 'Next chapter' })).toBeNull();
});

it('offers to resume a saved position and seeks there', () => {
  window.localStorage.setItem(KEY, JSON.stringify({ t: 2472, at: Date.now() }));
  const { fake, playback } = setup({ duration: 3600 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);

  fireEvent.click(screen.getByRole('button', { name: 'Resume at 41:12' }));

  expect(fake.seeks).toEqual([2472]);
  expect(screen.queryByRole('button', { name: /Resume at/ })).toBeNull();
});

it('does not offer to resume when a deep link chooses the position', () => {
  window.localStorage.setItem(KEY, JSON.stringify({ t: 2472, at: Date.now() }));
  window.history.replaceState(null, '', '/media/01VIDEO#t=30');
  const { playback } = setup({ duration: 3600 });
  render(<VideoToolbar playback={playback} mediaUlid="01VIDEO" />);

  expect(screen.queryByRole('button', { name: /Resume at/ })).toBeNull();
});

it('saves the position after watching, but never just for opening the page', () => {
  window.localStorage.setItem(KEY, JSON.stringify({ t: 2472, at: Date.now() }));
  const first = setup({ duration: 3600 });
  const { unmount } = render(<VideoToolbar playback={first.playback} mediaUlid="01VIDEO" />);
  unmount();
  expect(JSON.parse(window.localStorage.getItem(KEY) ?? 'null')).toMatchObject({ t: 2472 });

  const second = setup({ duration: 3600 });
  render(<VideoToolbar playback={second.playback} mediaUlid="01VIDEO" />);
  act(() => {
    second.fake.setPaused(false);
    second.fake.setTime(600);
    second.fake.setPaused(true);
  });
  expect(JSON.parse(window.localStorage.getItem(KEY) ?? 'null')).toMatchObject({ t: 600 });
});
