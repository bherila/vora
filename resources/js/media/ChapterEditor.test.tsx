import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { ChapterBar } from '@/media/ChapterBar';
import { ChapterEditor } from '@/media/ChapterEditor';
import { ChapterList } from '@/media/ChapterList';
import type { VideoChapter } from '@/media/types';
import { VideoPlayback } from '@/media/videoPlayback';
import { createFakeVideo, type FakeVideo } from '@/media/videoPlaybackTestUtils';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { put: jest.fn() } }));

const chapters: VideoChapter[] = [
  { start_ms: 0, title: 'Christmas' },
  { start_ms: 60_000, title: 'Birthday' },
];

function setup(): { fake: FakeVideo; playback: VideoPlayback } {
  const fake = createFakeVideo({ duration: 300 });
  return { fake, playback: new VideoPlayback(fake.video, null, 25) };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('ChapterBar and ChapterList', () => {
  it('draws one segment per chapter and jumps on click', () => {
    const { fake, playback } = setup();
    const { container } = render(<ChapterBar chapters={chapters} playback={playback} durationMs={null} />);
    const segments = container.querySelectorAll('[data-testid="chapter-bar"] rect');

    // Two chapters plus the playhead.
    expect(segments).toHaveLength(3);
    expect(segments[0]).toHaveAttribute('width', String(200 - 2));
    expect(segments[0]).toHaveClass('fill-primary');
    fireEvent.click(segments[1]!);
    expect(fake.seeks).toEqual([60]);
  });

  it('renders nothing without chapters or a duration', () => {
    const fake = createFakeVideo({ duration: Number.NaN });
    const playback = new VideoPlayback(fake.video);
    const { container, rerender } = render(<ChapterBar chapters={chapters} playback={playback} durationMs={null} />);
    expect(container).toBeEmptyDOMElement();
    rerender(<ChapterBar chapters={[]} playback={playback} durationMs={1000} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('lists chapters as jump buttons and marks the playing one', () => {
    const { fake, playback } = setup();
    render(<ChapterList chapters={chapters} playback={playback} />);
    act(() => fake.setTime(75));

    expect(screen.getByRole('button', { name: /Birthday/ })).toHaveAttribute('aria-current', 'true');
    expect(screen.getByRole('button', { name: /Christmas/ })).not.toHaveAttribute('aria-current');
    fireEvent.click(screen.getByRole('button', { name: /0:00 Christmas/ }));
    expect(fake.seeks).toEqual([0]);
  });
});

describe('ChapterEditor', () => {
  function renderEditor(initial: VideoChapter[] = chapters) {
    const view = setup();
    const onSaved = jest.fn();
    const onCancel = jest.fn();
    render(<ChapterEditor mediaUlid="01VIDEO" chapters={initial} playback={view.playback} durationMs={300_000} onSaved={onSaved} onCancel={onCancel} />);
    return { ...view, onSaved, onCancel };
  }

  it('adds a chapter at the playhead, with a beginning when the list is empty', () => {
    const { fake } = renderEditor([]);
    act(() => fake.setTime(42.5));

    fireEvent.click(screen.getByRole('button', { name: /Add at playhead/ }));

    expect(screen.getByLabelText('Chapter 1 title')).toHaveValue('Beginning');
    expect(screen.getByLabelText('Chapter 2 start time')).toHaveValue('0:42.500');
    expect(screen.getByLabelText('Chapter 2 title')).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Save chapters' })).toBeDisabled();
    expect(screen.getByText('Chapter 2 needs a title.')).toBeInTheDocument();
  });

  it('adds with the M key, but not while typing', () => {
    const { fake } = renderEditor();
    act(() => fake.setTime(120));

    fireEvent.keyDown(screen.getByLabelText('Chapter 1 title'), { key: 'm' });
    expect(screen.queryByLabelText('Chapter 3 title')).toBeNull();
    fireEvent.keyDown(document.body, { key: 'm' });
    expect(screen.getByLabelText('Chapter 3 start time')).toHaveValue('2:00');
  });

  it('nudges, retimes, and keeps rows in time order', () => {
    renderEditor();

    fireEvent.click(screen.getByRole('button', { name: 'Chapter 2: one second later' }));
    expect(screen.getByLabelText('Chapter 2 start time')).toHaveValue('1:01');
    fireEvent.click(screen.getByRole('button', { name: 'Chapter 2: one frame earlier' }));
    expect(screen.getByLabelText('Chapter 2 start time')).toHaveValue('1:00.960');

    const time = screen.getByLabelText('Chapter 2 start time');
    fireEvent.change(time, { target: { value: 'soon' } });
    expect(screen.getByText('Use times like 12:31 or 1:02:30.')).toBeInTheDocument();
    fireEvent.change(time, { target: { value: '4:00' } });
    fireEvent.blur(time);
    expect(screen.getByLabelText('Chapter 2 start time')).toHaveValue('4:00');
    expect(screen.getByLabelText('Chapter 1 start time')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Delete chapter 1' })).toBeDisabled();
  });

  it('replaces the draft with a pasted list', () => {
    renderEditor();
    fireEvent.click(screen.getByRole('button', { name: /Paste a list/ }));
    fireEvent.change(screen.getByLabelText(/One chapter per line/), { target: { value: '1:00 Cake\nnonsense' } });
    fireEvent.click(screen.getByRole('button', { name: 'Replace with this list' }));

    expect(screen.getByLabelText('Chapter 1 title')).toHaveValue('Beginning');
    expect(screen.getByLabelText('Chapter 2 title')).toHaveValue('Cake');
    expect(screen.getByText('Added a "Beginning" chapter at 0:00.')).toBeInTheDocument();
    expect(screen.getByText(/Line 2: expected a time and a title/)).toBeInTheDocument();
  });

  it('saves the whole list and reports server errors', async () => {
    const { onSaved } = renderEditor();
    jest.mocked(fetchWrapper.put).mockResolvedValueOnce({ data: [{ start_ms: 0, title: 'Christmas' }] });
    fireEvent.click(screen.getByRole('button', { name: 'Delete chapter 2' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save chapters' }));

    await waitFor(() => expect(onSaved).toHaveBeenCalledWith([{ start_ms: 0, title: 'Christmas' }]));
    expect(fetchWrapper.put).toHaveBeenCalledWith('/api/media/by-ulid/01VIDEO/chapters', { chapters: [{ start_ms: 0, title: 'Christmas' }] });

    jest.mocked(fetchWrapper.put).mockRejectedValueOnce('The first chapter must start at 0:00.');
    fireEvent.click(screen.getByRole('button', { name: 'Save chapters' }));
    const errors = await screen.findByText('The first chapter must start at 0:00.');
    expect(within(errors.closest('ul')!).getAllByRole('listitem')).toHaveLength(1);
  });

  it('cancels without saving', () => {
    const { onCancel } = renderEditor();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onCancel).toHaveBeenCalled();
    expect(fetchWrapper.put).not.toHaveBeenCalled();
  });
});
