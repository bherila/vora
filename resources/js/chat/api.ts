import type { ChatConversation, ChatEnvelope, ChatMessage, ChatPageResponse } from '@/chat/types';

export class ChatApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ChatApiError';
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function requestJson<T>(url: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(url, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken(),
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...init.headers,
    },
  });
  const text = await response.text();
  let payload: unknown = null;
  if (text !== '') {
    try {
      payload = JSON.parse(text) as unknown;
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const message = typeof payload === 'object' && payload !== null && 'message' in payload
      ? String(payload.message)
      : response.statusText || 'Request failed.';
    throw new ChatApiError(message, response.status);
  }

  return payload as T;
}

function encodedPath(value: string): string {
  return encodeURIComponent(value);
}

export const chatApi = {
  conversations(cursor?: string | null): Promise<ChatPageResponse<ChatConversation>> {
    const params = new URLSearchParams();
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();

    return requestJson(`/api/chat/conversations${query ? `?${query}` : ''}`);
  },

  conversation(id: string): Promise<ChatEnvelope<ChatConversation>> {
    return requestJson(`/api/chat/conversations/${encodedPath(id)}`);
  },

  createConversation(recipientId: string): Promise<ChatEnvelope<ChatConversation>> {
    return requestJson('/api/chat/conversations', {
      method: 'POST',
      body: JSON.stringify({ recipient_id: recipientId }),
    });
  },

  messages(id: string, options: { cursor?: string; after?: string } = {}): Promise<ChatPageResponse<ChatMessage>> {
    const params = new URLSearchParams();
    if (options.cursor) params.set('cursor', options.cursor);
    if (options.after) params.set('after', options.after);
    const query = params.toString();

    return requestJson(`/api/chat/conversations/${encodedPath(id)}/messages${query ? `?${query}` : ''}`);
  },

  send(id: string, clientMessageId: string, body: string): Promise<ChatEnvelope<ChatMessage>> {
    return requestJson(`/api/chat/conversations/${encodedPath(id)}/messages`, {
      method: 'POST',
      body: JSON.stringify({ client_message_id: clientMessageId, body }),
    });
  },

  markRead(id: string, messageId: string): Promise<ChatEnvelope<{ unread_count: number }>> {
    return requestJson(`/api/chat/conversations/${encodedPath(id)}/read`, {
      method: 'POST',
      body: JSON.stringify({ message_id: messageId }),
    });
  },

  unreadCount(): Promise<ChatEnvelope<{ count: number }>> {
    return requestJson('/api/chat/unread-count');
  },

  async sync(etag: string | null, signal?: AbortSignal): Promise<{ changed: boolean; etag: string | null }> {
    const response = await fetch('/api/chat/sync', {
      credentials: 'include',
      ...(signal ? { signal } : {}),
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(etag ? { 'If-None-Match': etag } : {}),
      },
    });

    if (response.status === 304) {
      return { changed: false, etag: etag ?? response.headers.get('ETag') };
    }
    if (!response.ok) {
      throw new ChatApiError(response.statusText || 'Could not refresh messages.', response.status);
    }

    return { changed: true, etag: response.headers.get('ETag') };
  },
};
