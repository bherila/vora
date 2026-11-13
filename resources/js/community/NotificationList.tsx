import { type MouseEvent } from 'react';

import { Button } from '@/components/ui/button';
import { safeInternalUrl } from '@/security/dom-url';

import { notificationDate, type NotificationItem, notificationLabel } from './notifications';

interface NotificationListProps {
  items: NotificationItem[];
  pending: string | null;
  markRead: (item: NotificationItem) => Promise<boolean>;
}

export function NotificationList({ items, pending, markRead }: NotificationListProps) {
  function follow(event: MouseEvent<HTMLAnchorElement>, item: NotificationItem, href: string): void {
    if (item.read_at !== null) return;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      void markRead(item);
      return;
    }
    // Finish the read request before navigation can unload/cancel it. A failure
    // leaves the notification available with the shared retryable error message.
    event.preventDefault();
    void markRead(item).then((saved) => { if (saved) window.location.assign(href); });
  }

  return (
    <ul className="divide-y divide-border">
      {items.map((item) => {
        const label = notificationLabel(item);
        const date = notificationDate(item.created_at);
        const href = safeInternalUrl(item.data.url);
        return (
          <li key={item.id} className="flex items-start justify-between gap-3 px-3 py-3 text-sm">
            <div className="min-w-0 space-y-1">
              {href ? (
                <a href={href} className={`block break-words hover:underline ${item.read_at === null ? 'font-semibold' : ''}`} onClick={(event) => follow(event, item, href)}>{label}</a>
              ) : <p className={item.read_at === null ? 'font-semibold' : ''}>{label}</p>}
              <p className="text-xs text-muted-foreground">
                {date}{date && ' · '}{item.read_at === null ? 'Unread' : 'Read'}
              </p>
            </div>
            {item.read_at === null && (
              <Button type="button" variant="ghost" size="sm" className="shrink-0" disabled={pending !== null}
                aria-label={`Mark as read: ${label}`} onClick={() => void markRead(item)}>
                {pending === item.id ? 'Saving…' : 'Mark read'}
              </Button>
            )}
          </li>
        );
      })}
    </ul>
  );
}
