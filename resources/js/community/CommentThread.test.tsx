import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { CommentApiError, communityApi } from '@/community/api';
import { CommentThread } from '@/community/CommentThread';
import type { PostComment } from '@/community/types';

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

function comment(id: number, overrides: Partial<PostComment> = {}): PostComment {
  return {
    id,
    ulid: `01COMMENT${id}`,
    parent_id: null,
    body: `Comment ${id}`,
    author: { id: 99, display_name: 'Reader' },
    created_at: '2026-08-02T12:00:00Z',
    deleted: false,
    can_delete: false,
    ...overrides,
  };
}

function setOnline(online: boolean): void {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: online });
}

function setVisibility(visibilityState: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: visibilityState });
}

describe('CommentThread', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.spyOn(Math, 'random').mockReturnValue(0.5);
    setOnline(true);
    setVisibility('visible');
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('does not load or poll while collapsed', async () => {
    const comments = jest.spyOn(communityApi, 'comments').mockResolvedValue({
      changed: true,
      etag: '"collapsed"',
      data: [],
    });

    render(<CommentThread postId={10} initialCount={0} enabled={false} />);
    await act(async () => { jest.advanceTimersByTime(60_000); });

    expect(comments).not.toHaveBeenCalled();
    expect(screen.queryByPlaceholderText('Write a comment')).toBeNull();
  });

  it('keeps the rendered thread unchanged after a 304 poll', async () => {
    const comments = jest.spyOn(communityApi, 'comments')
      .mockResolvedValueOnce({ changed: true, etag: '"revision-1"', data: [comment(1)] })
      .mockResolvedValueOnce({ changed: false, etag: '"revision-1"' });

    render(<CommentThread postId={10} initialCount={1} />);
    expect(await screen.findByText('Comment 1')).toBeInTheDocument();

    await act(async () => { jest.advanceTimersByTime(20_000); });

    expect(comments).toHaveBeenLastCalledWith(10, '"revision-1"');
    expect(screen.getByText('Comment 1')).toBeInTheDocument();
  });

  it('refreshes immediately after create and delete outside the three-thread poll pool', async () => {
    const comments = jest.spyOn(communityApi, 'comments').mockImplementation(async (postId) => ({
      changed: true,
      etag: `"post-${postId}"`,
      data: [comment(postId, { can_delete: true })],
    }));
    jest.spyOn(communityApi, 'comment').mockResolvedValue(comment(104, { body: 'New fourth comment' }));
    const deleteComment = jest.spyOn(communityApi, 'deleteComment').mockResolvedValue(undefined);

    render(<>{[1, 2, 3, 4].map((postId) => <CommentThread key={postId} postId={postId} initialCount={1} />)}</>);
    await waitFor(() => expect(comments).toHaveBeenCalledTimes(4));

    fireEvent.change(screen.getAllByPlaceholderText('Write a comment')[3]!, { target: { value: 'New fourth comment' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Post comment' })[3]!);
    await waitFor(() => expect(comments.mock.calls.filter(([postId]) => postId === 4)).toHaveLength(2));

    fireEvent.click(screen.getAllByTitle('Delete comment')[3]!);
    const dialog = await screen.findByRole('alertdialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(deleteComment).toHaveBeenCalledWith(4, 4));
    await waitFor(() => expect(comments.mock.calls.filter(([postId]) => postId === 4)).toHaveLength(3));
  });

  it.each([401, 403, 404])('clears stale comments and stops polling after a %i response', async (status) => {
    const comments = jest.spyOn(communityApi, 'comments')
      .mockResolvedValueOnce({ changed: true, etag: '"visible"', data: [comment(1)] })
      .mockRejectedValueOnce(new CommentApiError('Unavailable', status));

    render(<CommentThread postId={10} initialCount={1} />);
    expect(await screen.findByText('Comment 1')).toBeInTheDocument();

    await act(async () => { jest.advanceTimersByTime(20_000); });
    expect(await screen.findByText('Comments are no longer available.')).toBeInTheDocument();
    expect(screen.queryByText('Comment 1')).toBeNull();
    expect(screen.queryByPlaceholderText('Write a comment')).toBeNull();

    await act(async () => { jest.advanceTimersByTime(60_000); });
    expect(comments).toHaveBeenCalledTimes(2);
  });
});
