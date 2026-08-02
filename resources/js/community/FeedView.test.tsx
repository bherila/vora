import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { communityApi } from '@/community/api';
import { FeedView } from '@/community/FeedView';
import type { CommunityPost, FeedResponse } from '@/community/types';

jest.mock('./api', () => ({
  communityApi: {
    feed: jest.fn(),
  },
}));
jest.mock('./OnboardingChecklist', () => ({
  OnboardingChecklist: () => <div>Onboarding</div>,
}));
jest.mock('./PostComposer', () => ({
  PostComposer: ({
    contextInterest,
    onCreated,
  }: {
    contextInterest: { slug: string } | null;
    onCreated: (post: CommunityPost) => void;
  }) => (
    <div>
      <span data-testid="composer-interest">{contextInterest?.slug ?? 'none'}</span>
      <button
        type="button"
        onClick={() => onCreated({
          id: 99,
          ulid: '01MISMATCH',
          body: 'Different Interest',
          audience: 'everyone',
          discoverable: true,
          author: null,
          as_character: null,
          attachments: [],
          context_interest: { name: 'Books', slug: 'books' },
          reaction_count: 0,
          viewer_reacted: false,
          comment_count: 0,
          created_at: null,
        })}
      >
        Create mismatched post
      </button>
    </div>
  ),
}));
jest.mock('./PostCard', () => ({
  PostCard: ({ post }: { post: CommunityPost }) => <div>{post.body}</div>,
}));

const feed = jest.mocked(communityApi.feed);

function response(overrides: Partial<FeedResponse> = {}): FeedResponse {
  return {
    success: true,
    data: [],
    next_cursor: null,
    ...overrides,
  };
}

describe('FeedView', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/feed');
    window.IntersectionObserver = jest.fn(() => ({
      disconnect: jest.fn(),
      observe: jest.fn(),
      takeRecords: jest.fn(),
      unobserve: jest.fn(),
      root: null,
      rootMargin: '0px',
      thresholds: [],
    })) as unknown as typeof IntersectionObserver;
    feed.mockReset();
    feed.mockResolvedValue(response());
  });

  it('defaults missing and invalid URL scope values to Following', async () => {
    window.history.replaceState({}, '', '/feed?scope=unexpected');

    render(<FeedView hasFollowing />);

    await waitFor(() => expect(feed).toHaveBeenCalledWith('following', null, null));
    expect(screen.getByRole('button', { name: /Following/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('only the people and personas you follow.')).toBeInTheDocument();
  });

  it('switches to Mixed with the exact explanatory copy and stores the opt-in in the URL', async () => {
    render(<FeedView hasFollowing />);
    await waitFor(() => expect(feed).toHaveBeenCalledWith('following', null, null));

    fireEvent.click(screen.getByRole('button', { name: /Mixed/ }));

    await waitFor(() => expect(feed).toHaveBeenLastCalledWith('mixed', null, null));
    expect(screen.getByRole('button', { name: /Mixed/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('public posts from everyone, plus the people and personas you follow.')).toBeInTheDocument();
    expect(window.location.search).toBe('?scope=mixed');
  });

  it('removes the opt-in query when switching back to Following', async () => {
    window.history.replaceState({}, '', '/feed?scope=mixed');

    render(<FeedView hasFollowing />);
    await waitFor(() => expect(feed).toHaveBeenCalledWith('mixed', null, null));

    fireEvent.click(screen.getByRole('button', { name: /Following/ }));

    await waitFor(() => expect(feed).toHaveBeenLastCalledWith('following', null, null));
    expect(window.location.search).toBe('');
  });

  it('keeps the selected scope when loading the next page', async () => {
    window.history.replaceState({}, '', '/feed?scope=mixed');
    feed
      .mockResolvedValueOnce(response({ next_cursor: 'next page' }))
      .mockResolvedValueOnce(response());

    render(<FeedView hasFollowing />);

    const loadMore = await screen.findByRole('button', { name: 'Load more' });
    fireEvent.click(loadMore);

    await waitFor(() => expect(feed).toHaveBeenLastCalledWith('mixed', 'next page', null));
  });

  it('restores an Interest filter from the URL and keeps it on cursor requests', async () => {
    window.history.replaceState({}, '', '/feed?scope=mixed&interest=hiking');
    feed
      .mockResolvedValueOnce(response({ next_cursor: 'next page' }))
      .mockResolvedValueOnce(response());

    render(<FeedView hasFollowing interests={[{ name: 'Hiking', slug: 'hiking' }]} />);

    expect(await screen.findByLabelText('Filter by Interest')).toHaveValue('hiking');
    await waitFor(() => expect(feed).toHaveBeenCalledWith('mixed', null, 'hiking'));
    fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
    await waitFor(() => expect(feed).toHaveBeenLastCalledWith('mixed', 'next page', 'hiking'));
  });

  it('updates the URL and reloads when the general feed Interest filter changes', async () => {
    render(<FeedView hasFollowing interests={[{ name: 'Hiking', slug: 'hiking' }]} />);
    await waitFor(() => expect(feed).toHaveBeenCalledWith('following', null, null));

    fireEvent.change(screen.getByLabelText('Filter by Interest'), { target: { value: 'hiking' } });

    await waitFor(() => expect(feed).toHaveBeenLastCalledWith('following', null, 'hiking'));
    expect(window.location.search).toBe('?interest=hiking');
    expect(screen.getByTestId('composer-interest')).toHaveTextContent('hiking');
  });

  it('does not prepend a new post that does not match the active Interest filter', async () => {
    window.history.replaceState({}, '', '/feed?interest=hiking');
    render(<FeedView hasFollowing interests={[{ name: 'Hiking', slug: 'hiking' }]} />);
    await waitFor(() => expect(feed).toHaveBeenCalledWith('following', null, 'hiking'));

    fireEvent.click(screen.getByRole('button', { name: 'Create mismatched post' }));

    expect(screen.queryByText('Different Interest')).not.toBeInTheDocument();
  });

  it('keeps the dedicated Interest page context locked', async () => {
    render(<FeedView hasFollowing interest={{ name: 'Hiking', slug: 'hiking' }} interests={[]} />);

    await waitFor(() => expect(feed).toHaveBeenCalledWith('following', null, 'hiking'));
    expect(screen.queryByLabelText('Filter by Interest')).not.toBeInTheDocument();
  });

  it('locks users without follows to Mixed and explains why Following is disabled', async () => {
    window.history.replaceState({}, '', '/feed?scope=following');

    render(<FeedView hasFollowing={false} />);

    await waitFor(() => expect(feed).toHaveBeenCalledWith('mixed', null, null));
    expect(feed).not.toHaveBeenCalledWith('following', null, null);
    expect(screen.getByRole('button', { name: /Mixed/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: /Following/ })).toBeDisabled();
    expect(window.location.search).toBe('?scope=mixed');

    fireEvent.mouseEnter(screen.getByTestId('following-disabled-tooltip-trigger'));

    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      "you aren't following anyone yet.",
    );
  });
});
