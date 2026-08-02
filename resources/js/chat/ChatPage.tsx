import { ArrowLeft, MessageCircle, RefreshCw, Send } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { chatApi, ChatApiError } from '@/chat/api';
import type { ChatConversation, ChatMessage } from '@/chat/types';
import { Avatar } from '@/components/avatar';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { ReportButton } from '@/components/report-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import {
  ACTIVE_THREAD_POLL_MS,
  INBOX_POLL_MS,
  useAdaptivePolling,
} from '@/lib/useAdaptivePolling';

const CONVERSATION_PATH = /^\/messages\/([0-9A-HJKMNP-TV-Z]{26})$/i;

function initialConversationId(): string | null {
  return window.location.pathname.match(CONVERSATION_PATH)?.[1] ?? null;
}

function displayTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? ''
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function mergeMessages(current: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  const byId = new Map(current.map((message) => [message.id, message]));
  const optimisticByClient = new Map(
    current
      .filter((message) => message.client_message_id !== null)
      .map((message) => [message.client_message_id, message.id]),
  );

  for (const message of incoming) {
    const optimisticId = message.client_message_id
      ? optimisticByClient.get(message.client_message_id)
      : undefined;
    if (optimisticId && optimisticId !== message.id) byId.delete(optimisticId);
    byId.set(message.id, { ...message, local_status: 'sent' });
  }

  return [...byId.values()].sort((first, second) => (
    first.created_at.localeCompare(second.created_at) || first.id.localeCompare(second.id)
  ));
}

function updateConversation(
  conversations: ChatConversation[],
  updated: ChatConversation,
): ChatConversation[] {
  const remaining = conversations.filter((conversation) => conversation.id !== updated.id);
  return [updated, ...remaining].sort((first, second) => (
    second.last_activity_at.localeCompare(first.last_activity_at) || second.id.localeCompare(first.id)
  ));
}

export function ChatPage() {
  const [conversations, setConversations] = useState<ChatConversation[]>([]);
  const [nextConversationCursor, setNextConversationCursor] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(initialConversationId);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [olderCursor, setOlderCursor] = useState<string | null>(null);
  const [composer, setComposer] = useState('');
  const [loadingInbox, setLoadingInbox] = useState(true);
  const [loadingThread, setLoadingThread] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [announcement, setAnnouncement] = useState('');
  const [online, setOnline] = useState(() => navigator.onLine !== false);
  const syncEtagRef = useRef<string | null>(null);
  const messagesRef = useRef<ChatMessage[]>([]);
  const selectedIdRef = useRef<string | null>(selectedId);
  const lastReadRef = useRef<string | null>(null);
  const endRef = useRef<HTMLDivElement | null>(null);
  const headingRef = useRef<HTMLHeadingElement | null>(null);

  useEffect(() => {
    messagesRef.current = messages;
  }, [messages]);

  useEffect(() => {
    selectedIdRef.current = selectedId;
  }, [selectedId]);

  const selected = useMemo(
    () => conversations.find((conversation) => conversation.id === selectedId) ?? null,
    [conversations, selectedId],
  );

  const clearPrivateState = useCallback((notice: string): void => {
    setConversations([]);
    setNextConversationCursor(null);
    setSelectedId(null);
    setMessages([]);
    setOlderCursor(null);
    setComposer('');
    syncEtagRef.current = null;
    lastReadRef.current = null;
    window.history.replaceState({}, '', '/messages');
    setError(notice);
  }, []);

  useEffect(() => {
    const update = (): void => setOnline(navigator.onLine !== false);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
    };
  }, []);

  const loadInbox = useCallback(async (cursor: string | null = null): Promise<void> => {
    const response = await chatApi.conversations(cursor);
    setConversations((current) => cursor
      ? [...current, ...response.data.filter((item) => !current.some((existing) => existing.id === item.id))]
      : response.data);
    setNextConversationCursor(response.next_cursor);
  }, []);

  const markLatestRead = useCallback(async (conversationId: string, nextMessages: ChatMessage[]): Promise<void> => {
    const latest = [...nextMessages].reverse().find((message) => message.local_status !== 'pending');
    if (!latest || latest.is_mine || latest.id === lastReadRef.current) return;

    lastReadRef.current = latest.id;
    const response = await chatApi.markRead(conversationId, latest.id);
    setConversations((current) => current.map((conversation) => conversation.id === conversationId
      ? { ...conversation, unread_count: response.data.unread_count }
      : conversation));
  }, []);

  const loadThread = useCallback(async (conversationId: string): Promise<void> => {
    setLoadingThread(true);
    setError(null);
    lastReadRef.current = null;
    try {
      const [conversationResponse, messageResponse] = await Promise.all([
        chatApi.conversation(conversationId),
        chatApi.messages(conversationId),
      ]);
      const ordered = [...messageResponse.data].reverse();
      setConversations((current) => updateConversation(current, conversationResponse.data));
      setMessages(ordered);
      setOlderCursor(messageResponse.next_cursor);
      await markLatestRead(conversationId, ordered);
      requestAnimationFrame(() => {
        headingRef.current?.focus();
        endRef.current?.scrollIntoView({ block: 'end' });
      });
    } catch (caught) {
      if (caught instanceof ChatApiError && caught.status === 404) {
        setConversations((current) => current.filter((conversation) => conversation.id !== conversationId));
        setMessages([]);
        setSelectedId(null);
        window.history.replaceState({}, '', '/messages');
        setError('That conversation is unavailable.');
        return;
      }
      if (caught instanceof ChatApiError && (caught.status === 401 || caught.status === 403)) {
        clearPrivateState('Your messaging session is no longer available.');
        return;
      }
      setError('Could not load this conversation.');
    } finally {
      setLoadingThread(false);
    }
  }, [clearPrivateState, markLatestRead]);

  useEffect(() => {
    let cancelled = false;
    void (async (): Promise<void> => {
      try {
        const sync = await chatApi.sync(null);
        if (cancelled) return;
        syncEtagRef.current = sync.etag;
        await loadInbox();
        if (cancelled) return;
        const initialId = initialConversationId();
        if (initialId) await loadThread(initialId);
      } catch (caught) {
        if (!cancelled && caught instanceof ChatApiError && (caught.status === 401 || caught.status === 403)) {
          clearPrivateState('Your messaging session is no longer available.');
        } else if (!cancelled) {
          setError('Could not load messages.');
        }
      } finally {
        if (!cancelled) setLoadingInbox(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [clearPrivateState, loadInbox, loadThread]);

  const pollInbox = useCallback(async (): Promise<void> => {
    try {
      const sync = await chatApi.sync(syncEtagRef.current);
      syncEtagRef.current = sync.etag;
      if (sync.changed) await loadInbox();
      setError(null);
    } catch (caught) {
      if (caught instanceof ChatApiError && (caught.status === 401 || caught.status === 403)) {
        clearPrivateState('Your messaging session is no longer available.');
        return;
      }
      throw caught;
    }
  }, [clearPrivateState, loadInbox]);

  const pollThread = useCallback(async (): Promise<void> => {
    const conversationId = selectedIdRef.current;
    if (!conversationId) return;

    const latest = [...messagesRef.current]
      .reverse()
      .find((message) => message.local_status !== 'pending' && message.local_status !== 'failed');
    try {
      const response = await chatApi.messages(conversationId, latest ? { after: latest.id } : {});
      const incoming = latest ? response.data : [...response.data].reverse();
      if (incoming.length === 0) return;
      const merged = mergeMessages(messagesRef.current, incoming);
      setMessages(merged);
      setAnnouncement(`${incoming.length} new ${incoming.length === 1 ? 'message' : 'messages'}.`);
      await markLatestRead(conversationId, merged);
      requestAnimationFrame(() => endRef.current?.scrollIntoView({ block: 'end' }));
    } catch (caught) {
      if (caught instanceof ChatApiError && caught.status === 404) {
        setConversations((current) => current.filter((conversation) => conversation.id !== conversationId));
        setMessages([]);
        setSelectedId(null);
        window.history.replaceState({}, '', '/messages');
        setError('That conversation is unavailable.');
        return;
      }
      if (caught instanceof ChatApiError && (caught.status === 401 || caught.status === 403)) {
        clearPrivateState('Your messaging session is no longer available.');
        return;
      }
      throw caught;
    }
  }, [clearPrivateState, markLatestRead]);

  const { pollNow: retryInbox } = useAdaptivePolling({ enabled: true, intervalMs: INBOX_POLL_MS, onPoll: pollInbox });
  useAdaptivePolling({ enabled: selectedId !== null, intervalMs: ACTIVE_THREAD_POLL_MS, onPoll: pollThread });

  const selectConversation = (conversationId: string): void => {
    setSelectedId(conversationId);
    setMessages([]);
    window.history.pushState({}, '', `/messages/${encodeURIComponent(conversationId)}`);
    void loadThread(conversationId);
  };

  const closeConversation = (): void => {
    setSelectedId(null);
    setMessages([]);
    setOlderCursor(null);
    window.history.pushState({}, '', '/messages');
  };

  const loadOlder = async (): Promise<void> => {
    if (!selectedId || !olderCursor) return;
    try {
      const response = await chatApi.messages(selectedId, { cursor: olderCursor });
      setMessages((current) => mergeMessages([...response.data].reverse(), current));
      setOlderCursor(response.next_cursor);
    } catch {
      setError('Could not load older messages.');
    }
  };

  const sendWithKey = async (body: string, clientMessageId: string): Promise<void> => {
    if (!selectedId) return;
    setSending(true);
    setAnnouncement('Sending message.');
    const optimisticId = `pending:${clientMessageId}`;
    setMessages((current) => {
      const withoutPreviousAttempt = current.filter((message) => message.client_message_id !== clientMessageId);
      return [...withoutPreviousAttempt, {
        id: optimisticId,
        sender_id: null,
        body,
        created_at: new Date().toISOString(),
        is_mine: true,
        client_message_id: clientMessageId,
        local_status: 'pending',
      }];
    });

    try {
      const response = await chatApi.send(selectedId, clientMessageId, body);
      setMessages((current) => mergeMessages(current, [response.data]));
      setConversations((current) => current.map((conversation) => conversation.id === selectedId
        ? { ...conversation, latest_message: response.data, last_activity_at: response.data.created_at }
        : conversation));
      setAnnouncement('Message sent.');
    } catch (caught) {
      setMessages((current) => current.map((message) => message.client_message_id === clientMessageId
        ? { ...message, local_status: 'failed' }
        : message));
      setAnnouncement('Message failed. Retry when you are ready.');
      if (caught instanceof ChatApiError && caught.status === 422) {
        setConversations((current) => current.map((conversation) => conversation.id === selectedId
          ? { ...conversation, may_send: false }
          : conversation));
      }
    } finally {
      setSending(false);
    }
  };

  const submit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    const body = composer.trim();
    if (body === '' || body.length > 5000 || !selected?.may_send || sending) return;
    setComposer('');
    void sendWithKey(body, crypto.randomUUID());
  };

  return (
    <div className={`${BROWSING_PAGE_WIDTH} px-0 sm:px-4`}>
      <div className="mb-5 px-4 sm:px-0">
        <h1 className="text-2xl font-bold">Messages</h1>
        <p className="text-sm text-muted-foreground">Private conversations with mutual connections.</p>
      </div>
      {!online && (
        <Alert className="mb-4"><AlertDescription>You are offline. Messages will refresh when you reconnect.</AlertDescription></Alert>
      )}
      {error && (
        <Alert className="mb-4">
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>{error}</span>
            <Button variant="outline" size="sm" onClick={() => { setError(null); retryInbox(); }}>Retry</Button>
          </AlertDescription>
        </Alert>
      )}
      <Card className="min-h-[36rem] overflow-hidden p-0">
        <div className="grid min-h-[36rem] lg:grid-cols-[22rem_minmax(0,1fr)]">
          <aside
            aria-label="Conversations"
            className={`border-r border-border ${selectedId ? 'hidden lg:block' : 'block'}`}
          >
            {loadingInbox ? (
              <p className="p-4 text-sm text-muted-foreground" role="status">Loading conversations…</p>
            ) : conversations.length === 0 ? (
              <div className="p-8 text-center">
                <MessageCircle className="mx-auto mb-3 h-8 w-8 text-muted-foreground" aria-hidden="true" />
                <p className="font-medium">No conversations yet</p>
                <p className="mt-1 text-sm text-muted-foreground">Message a mutual connection from their account profile.</p>
              </div>
            ) : (
              <div>
                {conversations.map((conversation) => {
                  const name = conversation.other_user?.display_name ?? 'Unavailable account';
                  return (
                    <button
                      key={conversation.id}
                      type="button"
                      aria-current={selectedId === conversation.id ? 'page' : undefined}
                      onClick={() => selectConversation(conversation.id)}
                      className="flex w-full items-center gap-3 border-b border-border px-4 py-3 text-left hover:bg-muted/60 aria-[current=page]:bg-muted"
                    >
                      <Avatar name={name} src={conversation.other_user?.avatar_url} sizeClassName="h-10 w-10" />
                      <span className="min-w-0 flex-1">
                        <span className="flex items-center justify-between gap-2">
                          <span className="truncate text-sm font-medium">{name}</span>
                          <span className="shrink-0 text-xs text-muted-foreground">{displayTime(conversation.last_activity_at)}</span>
                        </span>
                        <span className="mt-0.5 flex items-center justify-between gap-2">
                          <span className="truncate text-xs text-muted-foreground">
                            {conversation.latest_message?.body ?? 'Conversation started'}
                          </span>
                          {conversation.unread_count > 0 && (
                            <span className="rounded-full bg-foreground px-1.5 py-0.5 text-[11px] font-medium text-background">
                              <span className="sr-only">Unread messages: </span>{conversation.unread_count}
                            </span>
                          )}
                        </span>
                      </span>
                    </button>
                  );
                })}
                {nextConversationCursor && (
                  <div className="p-3 text-center">
                    <Button variant="outline" size="sm" onClick={() => void loadInbox(nextConversationCursor)}>
                      Load more conversations
                    </Button>
                  </div>
                )}
              </div>
            )}
          </aside>

          <section
            aria-labelledby="conversation-heading"
            className={`${selectedId ? 'flex' : 'hidden lg:flex'} min-w-0 flex-col`}
          >
            {!selectedId ? (
              <div className="flex flex-1 items-center justify-center p-8 text-center text-muted-foreground">
                Select a conversation to read your messages.
              </div>
            ) : (
              <>
                <header className="flex items-center gap-3 border-b border-border px-4 py-3">
                  <Button variant="ghost" size="icon" className="lg:hidden" onClick={closeConversation} aria-label="Back to conversations">
                    <ArrowLeft className="h-4 w-4" />
                  </Button>
                  <Avatar
                    name={selected?.other_user?.display_name ?? 'Unavailable account'}
                    src={selected?.other_user?.avatar_url}
                    sizeClassName="h-9 w-9"
                  />
                  <h2 ref={headingRef} id="conversation-heading" tabIndex={-1} className="truncate font-semibold outline-none">
                    {selected?.other_user?.display_name ?? 'Conversation'}
                  </h2>
                </header>

                <div className="flex-1 overflow-y-auto p-4" role="log" aria-live="polite" aria-relevant="additions text">
                  {olderCursor && (
                    <div className="mb-4 text-center">
                      <Button variant="outline" size="sm" onClick={() => void loadOlder()}>Load older messages</Button>
                    </div>
                  )}
                  {loadingThread ? (
                    <p className="text-center text-sm text-muted-foreground" role="status">Loading messages…</p>
                  ) : messages.length === 0 ? (
                    <p className="text-center text-sm text-muted-foreground">No messages yet. Say hello when you are ready.</p>
                  ) : (
                    <ol className="space-y-3">
                      {messages.map((message) => (
                        <li key={message.id} className={`flex ${message.is_mine ? 'justify-end' : 'justify-start'}`}>
                          <div className={`max-w-[85%] rounded-lg px-3 py-2 ${message.is_mine ? 'bg-foreground text-background' : 'bg-muted'}`}>
                            <p className="sr-only">{message.is_mine ? 'You' : selected?.other_user?.display_name ?? 'Other participant'} said:</p>
                            <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>
                            <p className={`mt-1 text-[11px] ${message.is_mine ? 'text-background/70' : 'text-muted-foreground'}`}>
                              {displayTime(message.created_at)}{message.is_mine ? ` · ${message.local_status === 'pending' ? 'Sending' : message.local_status === 'failed' ? 'Failed' : 'Sent'}` : ''}
                            </p>
                            {!message.is_mine && message.local_status !== 'pending' && (
                              <ReportButton type="chat_message" id={message.id} label="Report message" variant="ghost" />
                            )}
                            {message.local_status === 'failed' && message.client_message_id && (
                              <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 text-xs text-background underline"
                                onClick={() => void sendWithKey(message.body, message.client_message_id as string)}
                              >
                                Retry
                              </Button>
                            )}
                          </div>
                        </li>
                      ))}
                    </ol>
                  )}
                  <div ref={endRef} />
                </div>

                <form onSubmit={submit} className="border-t border-border p-4">
                  {selected?.may_send ? (
                    <div className="flex items-end gap-2">
                      <div className="min-w-0 flex-1">
                        <label htmlFor="chat-message" className="sr-only">Message</label>
                        <Textarea
                          id="chat-message"
                          value={composer}
                          maxLength={5000}
                          rows={2}
                          placeholder="Write a message"
                          onChange={(event) => setComposer(event.target.value)}
                          onKeyDown={(event) => {
                            if (event.key === 'Enter' && !event.shiftKey) {
                              event.preventDefault();
                              event.currentTarget.form?.requestSubmit();
                            }
                          }}
                        />
                        <p className="mt-1 text-right text-xs text-muted-foreground">{composer.length}/5000</p>
                      </div>
                      <Button type="submit" disabled={sending || composer.trim() === ''} aria-label="Send message">
                        {sending ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                      </Button>
                    </div>
                  ) : (
                    <p className="text-sm text-muted-foreground">Messaging is unavailable. Your existing history remains here.</p>
                  )}
                </form>
              </>
            )}
          </section>
        </div>
      </Card>
      <p className="sr-only" aria-live="polite">{announcement}</p>
    </div>
  );
}
