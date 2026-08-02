import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { chatApi } from '@/chat/api';
import { ChatPage } from '@/chat/ChatPage';
import type { ChatConversation, ChatMessage } from '@/chat/types';

jest.mock('@/chat/api', () => ({
  ChatApiError: class ChatApiError extends Error {
    constructor(message: string, public readonly status: number) { super(message); }
  },
  chatApi: {
    sync: jest.fn(),
    conversations: jest.fn(),
    conversation: jest.fn(),
    messages: jest.fn(),
    send: jest.fn(),
    markRead: jest.fn(),
  },
}));

jest.mock('@/lib/useAdaptivePolling', () => ({
  ACTIVE_THREAD_POLL_MS: 12_000,
  INBOX_POLL_MS: 45_000,
  useAdaptivePolling: jest.fn(() => ({ pollNow: jest.fn() })),
}));

const conversation: ChatConversation = {
  id: '01CONVERSATION000000000000',
  other_user: { id: '01OTHERACCOUNT0000000000000', display_name: 'Aria', avatar_url: null },
  latest_message: null,
  unread_count: 1,
  may_send: true,
  last_activity_at: '2026-07-31T12:00:00.000Z',
};

const incoming: ChatMessage = {
  id: '01MESSAGE00000000000000000',
  sender_id: conversation.other_user?.id ?? null,
  body: 'See you later',
  created_at: '2026-07-31T12:00:00.000Z',
  is_mine: false,
  client_message_id: null,
};

describe('ChatPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    window.history.replaceState({}, '', '/messages');
    window.requestAnimationFrame = (callback: FrameRequestCallback): number => {
      callback(0);
      return 1;
    };
    Element.prototype.scrollIntoView = jest.fn();
    (chatApi.sync as jest.Mock).mockResolvedValue({ changed: true, etag: '"1"' });
    (chatApi.conversations as jest.Mock).mockResolvedValue({ success: true, data: [conversation], next_cursor: null });
    (chatApi.conversation as jest.Mock).mockResolvedValue({ success: true, data: conversation });
    (chatApi.messages as jest.Mock).mockResolvedValue({ success: true, data: [incoming], next_cursor: null });
    (chatApi.markRead as jest.Mock).mockResolvedValue({ success: true, data: { unread_count: 0 } });
  });

  it('renders unread inbox state and opens a selected thread in reading order', async () => {
    render(<ChatPage />);

    const row = await screen.findByRole('button', { name: /Aria/ });
    expect(row).toHaveTextContent('Unread messages: 1');
    fireEvent.click(row);

    expect(await screen.findByText('See you later')).toBeInTheDocument();
    expect(chatApi.messages).toHaveBeenCalledWith(conversation.id);
    await waitFor(() => expect(chatApi.markRead).toHaveBeenCalledWith(conversation.id, incoming.id));
  });

  it('reconciles an optimistic send by one idempotency key and labels it Sent', async () => {
    const committed: ChatMessage = {
      id: '01COMMITTED000000000000000',
      sender_id: '01ME000000000000000000000',
      body: 'Hello asynchronously',
      created_at: '2026-07-31T12:01:00.000Z',
      is_mine: true,
      client_message_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    };
    jest.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    (chatApi.send as jest.Mock).mockResolvedValue({ success: true, data: committed });
    render(<ChatPage />);
    fireEvent.click(await screen.findByRole('button', { name: /Aria/ }));
    await screen.findByText('See you later');

    fireEvent.change(screen.getByLabelText('Message'), { target: { value: committed.body } });
    fireEvent.click(screen.getByRole('button', { name: 'Send message' }));

    await waitFor(() => expect(chatApi.send).toHaveBeenCalledWith(
      conversation.id,
      committed.client_message_id,
      committed.body,
    ));
    expect(await screen.findByText(/Sent$/)).toBeInTheDocument();
    expect(screen.queryByText(/Delivered|Read/)).toBeNull();
    expect(within(screen.getByRole('log')).getAllByText(committed.body)).toHaveLength(1);
  });

  it('retains history but disables composing when eligibility is lost', async () => {
    (chatApi.conversation as jest.Mock).mockResolvedValue({
      success: true,
      data: { ...conversation, may_send: false },
    });
    render(<ChatPage />);
    fireEvent.click(await screen.findByRole('button', { name: /Aria/ }));

    expect(await screen.findByText('See you later')).toBeInTheDocument();
    expect(screen.getByText('Messaging is unavailable. Your existing history remains here.')).toBeInTheDocument();
    expect(screen.queryByLabelText('Message')).toBeNull();
  });
});
