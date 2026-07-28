import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { communityApi } from './api';
import { PostComposer } from './PostComposer';

jest.mock('@/initialData', () => ({
  readInitialData: () => ({
    navbar: {
      activeIdentityId: 17,
      identities: [
        { id: null, displayName: 'Human Name', avatarUrl: null },
        { id: 17, displayName: 'Kira', avatarUrl: null },
      ],
    },
  }),
}));

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn((url: string) => {
      if (url === '/api/characters') {
        return Promise.resolve({ success: true, data: [{ id: 17, display_name: 'Kira' }] });
      }

      return Promise.resolve({ success: true, data: [] });
    }),
  },
}));

jest.mock('./api', () => ({
  communityApi: {
    createPost: jest.fn(() => Promise.resolve({ id: 1 })),
  },
}));

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

describe('PostComposer identity default', () => {
  it('defaults a new post to the server-hydrated active identity', async () => {
    render(<PostComposer onCreated={jest.fn()} />);

    await waitFor(() => expect(screen.getByRole('button', { name: /As Kira/i })).toBeInTheDocument());
    fireEvent.change(screen.getByPlaceholderText('Share an update'), { target: { value: 'Hello' } });
    fireEvent.submit(screen.getByPlaceholderText('Share an update').closest('form') as HTMLFormElement);

    await waitFor(() => expect(communityApi.createPost).toHaveBeenCalledWith(expect.objectContaining({
      character_id: 17,
    })));
  });
});
