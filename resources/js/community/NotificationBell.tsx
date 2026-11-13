import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

import { NotificationList } from './NotificationList';
import { useNotifications } from './useNotifications';

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const inbox = useNotifications(1, open);
  const ref = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    function outside(event: MouseEvent): void {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    }
    function escape(event: KeyboardEvent): void { if (event.key === 'Escape') setOpen(false); }
    document.addEventListener('mousedown', outside);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', outside); document.removeEventListener('keydown', escape); };
  }, []);

  return (
    <div className="relative" ref={ref}>
      <button type="button" className="relative rounded-md p-2 hover:bg-muted" onClick={() => setOpen(!open)} aria-label="Notifications" aria-expanded={open}>
        <Bell className="h-4 w-4" />
        {inbox.count > 0 && <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-medium text-white">{inbox.count > 99 ? '99+' : inbox.count}</span>}
      </button>
      {open && (
        <div className="absolute right-0 top-full z-50 mt-2 w-80 rounded-md border border-border bg-background shadow-lg">
          <div className="flex items-center justify-between border-b border-border px-3 py-2">
            <span className="text-sm font-medium">Notifications</span>
            <Button type="button" size="sm" variant="ghost" disabled={inbox.loading || inbox.pending !== null} onClick={() => void inbox.markRead(null)}>Mark all read</Button>
          </div>
          <div className="max-h-96 overflow-auto">
            {inbox.loadError && <div role="alert" className="space-y-2 p-3"><p>{inbox.loadError}</p><Button type="button" size="sm" variant="outline" onClick={inbox.retry}>Retry</Button></div>}
            {inbox.actionError && <p role="alert" className="p-3 text-sm">{inbox.actionError}</p>}
            {inbox.loading ? <p role="status" className="px-3 py-4 text-sm text-muted-foreground">Loading notifications…</p> : inbox.items.length === 0 && !inbox.loadError ? (
              <p className="px-3 py-4 text-sm text-muted-foreground">No notifications.</p>
            ) : <NotificationList items={inbox.items} pending={inbox.pending} markRead={inbox.markRead} />}
          </div>
          <a href="/notifications" className="block border-t border-border px-3 py-3 text-center text-sm font-medium hover:underline">View all notifications</a>
        </div>
      )}
    </div>
  );
}
