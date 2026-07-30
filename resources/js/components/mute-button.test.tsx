import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { MuteButton } from './mute-button';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    delete: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const post = jest.mocked(fetchWrapper.post);
const remove = jest.mocked(fetchWrapper.delete);

describe('MuteButton', () => {
  beforeEach(() => {
    post.mockReset();
    remove.mockReset();
    post.mockResolvedValue({ data: { muted: true } });
    remove.mockResolvedValue({ data: { muted: false } });
  });

  it('mutes one exact identity and explains why its direct profile remains visible', async () => {
    render(<MuteButton type="character" id={9} displayName="Kira" initialMuted={false} />);

    fireEvent.click(screen.getByRole('button', { name: 'Mute' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/mutes', {
      type: 'character',
      id: 9,
    }));
    expect(screen.getByRole('button', { name: 'Unmute' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText("You won't see Kira in your feed. Kira won't know.")).toBeInTheDocument();
  });

  it('unmutes without changing any other relationship state', async () => {
    render(<MuteButton type="user" id={7} displayName="Ben" initialMuted />);

    fireEvent.click(screen.getByRole('button', { name: 'Unmute' }));

    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/mutes', {
      type: 'user',
      id: 7,
    }));
    expect(screen.getByRole('button', { name: 'Mute' })).toHaveAttribute('aria-pressed', 'false');
  });
});
