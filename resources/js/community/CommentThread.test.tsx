import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { CommentApiError, communityApi } from '@/community/api';
import { CommentThread } from '@/community/CommentThread';
import type { PostComment } from '@/community/types';

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
let mockRestrictions: Array<{ capability: string; label: string; reason: string | null; expires_at: string | null }> = [];
jest.mock('@/initialData', () => ({ readInitialData: () => ({ restrictions: mockRestrictions }) }));

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
    mockRestrictions = [];
    jest.useFakeTimers();
    jest.spyOn(Math, 'random').mockReturnValue(0.5);
    setOnline(true);
    setVisibility('visible');
  });

  it('keeps comments and deletion available while hiding create and reply controls', async () => {
    mockRestrictions = [{
      capability: 'comment.create',
      label: 'Commenting',
      reason: 'Repeated abuse',
      expires_at: null,
    }];
    jest.spyOn(communityApi, 'comments').mockResolvedValue({
      changed: true,
      etag: '"restricted"',
      data: [comment(1, { can_delete: true })],
    });

    render(<CommentThread postId={10} initialCount={1} />);

    expect(await screen.findByText('Comment 1')).toBeInTheDocument();
    expect(screen.getByTitle('Delete comment')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reply' })).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Write a comment')).not.toBeInTheDocument();
    expect(screen.getByText(/Commenting restricted/)).toBeInTheDocument();
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

  it('clears an unchanged submitted draft and its reply context, including surrounding whitespace', async () => {
    const root = comment(1);
    const created = comment(2, { body: 'Submitted reply', parent_id: root.id });
    jest.spyOn(communityApi, 'comments')
      .mockResolvedValueOnce({ changed: true, etag: '"initial"', data: [root] })
      .mockResolvedValue({ changed: true, etag: '"created"', data: [root, created] });
    const create = jest.spyOn(communityApi, 'comment').mockResolvedValue(created);
    render(<CommentThread postId={10} initialCount={1} />);
    await screen.findByText('Comment 1');

    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
    const composer = screen.getByPlaceholderText('Write a comment');
    fireEvent.change(composer, { target: { value: '  Submitted reply  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));

    await waitFor(() => expect(composer).toHaveValue(''));
    expect(create).toHaveBeenCalledWith(10, 'Submitted reply', root.id);
    expect(screen.queryByText('Replying to Reader')).not.toBeInTheDocument();
    expect(screen.getByText('Submitted reply', { selector: 'p' })).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Post comment' })).toBeDisabled();
  });

  it('preserves a follow-up draft and its reply context while a previous comment is posting', async () => {
    const root = comment(1);
    const created = comment(2, { body: 'First reply', parent_id: root.id });
    jest.spyOn(communityApi, 'comments')
      .mockResolvedValueOnce({ changed: true, etag: '"initial"', data: [root] })
      .mockResolvedValue({ changed: true, etag: '"created"', data: [root, created] });
    let resolveCreate!: (value: PostComment) => void;
    const create = jest.spyOn(communityApi, 'comment')
      .mockReturnValueOnce(new Promise<PostComment>((resolve) => { resolveCreate = resolve; }))
      .mockResolvedValue(comment(3, { body: 'Follow-up reply', parent_id: root.id }));
    render(<CommentThread postId={10} initialCount={1} />);
    await screen.findByText('Comment 1');

    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
    const composer = screen.getByPlaceholderText('Write a comment');
    fireEvent.change(composer, { target: { value: 'First reply' } });
    fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
    expect(screen.getByRole('button', { name: 'Posting...' })).toBeDisabled();
    fireEvent.change(composer, { target: { value: 'Follow-up reply' } });

    await act(async () => { resolveCreate(created); });

    expect(screen.getByText('First reply', { selector: 'p' })).toBeInTheDocument();
    expect(composer).toHaveValue('Follow-up reply');
    expect(screen.getByText('Replying to Reader')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Post comment' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
    await waitFor(() => expect(create).toHaveBeenLastCalledWith(10, 'Follow-up reply', root.id));
  });

  it('preserves a newly chosen reply target when the submitted body is unchanged', async () => {
    const roots = [comment(1), comment(2, { author: { id: 98, display_name: 'Another reader' } })];
    const created = comment(3, { body: 'Submitted reply', parent_id: 1 });
    jest.spyOn(communityApi, 'comments')
      .mockResolvedValueOnce({ changed: true, etag: '"initial"', data: roots })
      .mockResolvedValue({ changed: true, etag: '"created"', data: [...roots, created] });
    let resolveCreate!: (value: PostComment) => void;
    jest.spyOn(communityApi, 'comment').mockReturnValue(new Promise<PostComment>((resolve) => { resolveCreate = resolve; }));
    render(<CommentThread postId={10} initialCount={2} />);
    await screen.findByText('Comment 1');

    fireEvent.click(screen.getAllByRole('button', { name: 'Reply' })[0]!);
    fireEvent.change(screen.getByPlaceholderText('Write a comment'), { target: { value: 'Submitted reply' } });
    fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Reply' })[1]!);
    await act(async () => { resolveCreate(created); });

    expect(screen.getByPlaceholderText('Write a comment')).toHaveValue('');
    expect(screen.getByText('Replying to Another reader')).toBeInTheDocument();
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
