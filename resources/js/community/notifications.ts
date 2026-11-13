import { fetchWrapper } from '@/fetchWrapper';

export interface NotificationItem {
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

export interface NotificationPage {
  success: boolean;
  data: NotificationItem[];
  meta: { current_page: number; last_page: number; total: number; has_more: boolean };
}

export const NOTIFICATIONS_READ = 'vora:notifications-read';
export interface NotificationReadChange { id: string | null; readAt: string }

export async function loadNotifications(page: number): Promise<NotificationPage> {
  const response = await fetchWrapper.get(`/api/notifications?page=${page}`) as NotificationPage;
  if (response?.success !== true || !Array.isArray(response.data) || !response.meta) {
    throw new Error('Could not load notifications.');
  }
  return response;
}

export async function loadNotificationCount(): Promise<number> {
  const response = await fetchWrapper.get('/api/notifications/unread-count') as { success: boolean; data: { count: number } };
  if (response?.success !== true || typeof response.data?.count !== 'number') {
    throw new Error('Could not load the unread count.');
  }
  return response.data.count;
}

export async function markNotificationsRead(id: string | null): Promise<void> {
  const url = id === null ? '/api/notifications/read-all' : `/api/notifications/${encodeURIComponent(id)}/read`;
  const response = await fetchWrapper.post(url, {}) as { success: boolean };
  if (response?.success !== true) throw new Error('Could not mark notifications as read.');
  window.dispatchEvent(new CustomEvent<NotificationReadChange>(NOTIFICATIONS_READ, {
    detail: { id, readAt: new Date().toISOString() },
  }));
}

export function notificationLabel(notification: NotificationItem): string {
  const actor = notification.data.actor_name ?? 'Someone';
  switch (notification.type ?? notification.data.type) {
    case 'new_post': return `${actor} posted something new.`;
    case 'post_reaction': return `${actor} reacted to your post.`;
    case 'post_comment': return `${actor} commented on your post.`;
    case 'follow_request': return `${actor} sent a follow request.`;
    case 'follow_accepted': return `${actor} accepted your follow request.`;
    case 'co_author_invite': return `${actor} invited you to co-author.`;
    case 'co_author_invite_accepted': return `${actor} accepted a co-author invite.`;
    case 'favorite': return `${actor} saved your ${notification.data.item_type ?? 'content'}.`;
    case 'abuse_report': return 'A new abuse report was filed.';
    default: return 'New notification.';
  }
}

export function notificationDate(value: string | null): string {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
