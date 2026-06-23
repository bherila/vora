import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

interface NotificationItem {
  id: string;
  type: string | null;
  data: {
    actor_name?: string;
    url?: string;
    type?: string;
    item_type?: string;
    [key: string]: unknown;
  };
  read_at: string | null;
  created_at: string | null;
}

interface NotificationListResponse {
  success: boolean;
  data: NotificationItem[];
}

interface CountResponse {
  success: boolean;
  data: { count: number };
}

function labelFor(notification: NotificationItem): string {
  const actor = notification.data.actor_name ?? 'Someone';
  switch (notification.type ?? notification.data.type) {
    case 'new_post':
      return `${actor} posted something new.`;
    case 'post_reaction':
      return `${actor} reacted to your post.`;
    case 'post_comment':
      return `${actor} commented on your post.`;
    case 'follow_request':
      return `${actor} sent a follow request.`;
    case 'follow_accepted':
      return `${actor} accepted your follow request.`;
    case 'co_author_invite':
      return `${actor} invited you to co-author.`;
    case 'co_author_invite_accepted':
      return `${actor} accepted a co-author invite.`;
    case 'favorite':
      return `${actor} saved your ${notification.data.item_type ?? 'content'}.`;
    case 'abuse_report':
      return 'A new abuse report was filed.';
    default:
      return 'New notification.';
  }
}

function formatDate(value: string | null): string {
  if (!value) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [count, setCount] = useState(0);
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(false);
  const ref = useRef<HTMLDivElement | null>(null);

  const loadCount = async (): Promise<void> => {
    const response = await fetchWrapper.get('/api/notifications/unread-count') as CountResponse;
    setCount(response.data.count);
  };

  const loadItems = async (): Promise<void> => {
    setLoading(true);
    try {
      const response = await fetchWrapper.get('/api/notifications') as NotificationListResponse;
      setItems(response.data);
      await loadCount();
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadCount().catch(() => {});
  }, []);

  useEffect(() => {
    const handler = (event: MouseEvent): void => {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const toggleOpen = (): void => {
    const next = !open;
    setOpen(next);
    if (next) {
      void loadItems().catch(() => {});
    }
  };

  const markRead = async (notification: NotificationItem): Promise<void> => {
    await fetchWrapper.post(`/api/notifications/${notification.id}/read`, {});
    setItems((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item));
    setCount((current) => Math.max(0, current - (notification.read_at === null ? 1 : 0)));
  };

  const markAllRead = async (): Promise<void> => {
    await fetchWrapper.post('/api/notifications/read-all', {});
    setItems((current) => current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })));
    setCount(0);
  };

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className="relative rounded-md p-2 hover:bg-gray-50 dark:hover:bg-[#1f1f1e]"
        onClick={toggleOpen}
        aria-label="Notifications"
        aria-expanded={open}
      >
        <Bell className="h-4 w-4" />
        {count > 0 && (
          <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-medium text-white">
            {count > 99 ? '99+' : count}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute right-0 top-full z-50 mt-2 w-80 rounded-md border border-gray-200 bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#1a1a19]">
          <div className="flex items-center justify-between border-b border-border px-3 py-2">
            <span className="text-sm font-medium">Notifications</span>
            <Button type="button" size="sm" variant="ghost" onClick={() => void markAllRead()}>Mark all read</Button>
          </div>
          <div className="max-h-96 overflow-auto">
            {loading ? (
              <p className="px-3 py-4 text-sm text-muted-foreground">Loading...</p>
            ) : items.length === 0 ? (
              <p className="px-3 py-4 text-sm text-muted-foreground">No notifications.</p>
            ) : (
              items.map((item) => (
                <a
                  key={item.id}
                  className={`block border-b border-border px-3 py-3 text-sm hover:bg-gray-50 dark:hover:bg-[#262625] ${item.read_at === null ? 'font-medium' : ''}`}
                  href={item.data.url ?? '#'}
                  onClick={() => void markRead(item)}
                >
                  <span className="block">{labelFor(item)}</span>
                  <span className="text-xs text-muted-foreground">{formatDate(item.created_at)}</span>
                </a>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
