import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { StartDiscussion } from './StartDiscussion';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { post: jest.fn() },
}));

const post = fetchWrapper.post as jest.Mock;

describe('StartDiscussion', () => {
  beforeEach(() => {
    post.mockReset();
  });

  it('does not make a request until there is a non-empty first comment', () => {
    render(<StartDiscussion endpoint="/discussion" onStarted={jest.fn()} />);

    const button = screen.getByRole('button', { name: 'Post comment' });
    expect(button).toBeDisabled();
    fireEvent.change(screen.getByPlaceholderText('Write the first comment…'), { target: { value: '   ' } });
    expect(button).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
  });

  it('creates the canonical discussion with the first comment', async () => {
    const onStarted = jest.fn();
    post.mockResolvedValue({
      data: { post: { id: 41, ulid: '01TEST', comment_count: 1 } },
    });
    render(<StartDiscussion endpoint="/discussion" onStarted={onStarted} />);

    fireEvent.change(screen.getByPlaceholderText('Write the first comment…'), { target: { value: ' First! ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/discussion', { body: 'First!' }));
    expect(onStarted).toHaveBeenCalledWith({ id: 41, ulid: '01TEST', comment_count: 1 });
  });
});
