import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Button } from '@/components/ui/button';

import { NotificationList } from './NotificationList';
import { useNotifications } from './useNotifications';

export function NotificationsPage() {
  const [page, setPage] = useState(1);
  const inbox = useNotifications(page);

  return (
    <main className={`${READING_PAGE_WIDTH} space-y-6 px-4 py-8`}>
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Notifications</h1>
          <p className="text-sm text-muted-foreground">Your recent and earlier notifications.</p>
        </div>
        <Button type="button" variant="outline" disabled={inbox.loading || inbox.pending !== null} onClick={() => void inbox.markRead(null)}>
          {inbox.pending === 'all' ? 'Saving…' : 'Mark all read'}
        </Button>
      </header>
      {inbox.loadError && <div role="alert" className="space-y-2"><p>{inbox.loadError}</p><Button type="button" variant="outline" onClick={inbox.retry}>Retry</Button></div>}
      {inbox.actionError && <p role="alert">{inbox.actionError}</p>}
      {inbox.loading ? <p role="status">Loading notifications…</p> : inbox.items.length === 0 && !inbox.loadError ? (
        <p className="text-muted-foreground">No notifications on this page.</p>
      ) : <NotificationList items={inbox.items} pending={inbox.pending} markRead={inbox.markRead} />}
      {(inbox.meta || page > 1) && (
        <nav aria-label="Notification pages" className="flex flex-wrap items-center justify-between gap-3">
          <Button type="button" variant="outline" disabled={inbox.loading || inbox.pending !== null || page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
          <p className="text-sm text-muted-foreground" aria-live="polite">Page {page}{inbox.meta && <> of {inbox.meta.last_page} · {inbox.meta.total} notifications</>}</p>
          <Button type="button" variant="outline" disabled={inbox.loading || inbox.pending !== null || !inbox.meta?.has_more} onClick={() => setPage(page + 1)}>Next</Button>
        </nav>
      )}
    </main>
  );
}

const element = document.getElementById('notifications-page');
if (element) createRoot(element).render(<NotificationsPage />);
