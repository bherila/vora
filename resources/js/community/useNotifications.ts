import { useEffect, useRef, useState } from 'react';

import {
  loadNotificationCount,
  loadNotifications,
  markNotificationsRead,
  type NotificationItem,
  type NotificationPage,
  type NotificationReadChange,
  NOTIFICATIONS_READ,
} from './notifications';

export function useNotifications(page: number, enabled = true) {
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [meta, setMeta] = useState<NotificationPage['meta'] | null>(null);
  const [loading, setLoading] = useState(enabled);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [countError, setCountError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [count, setCount] = useState(0);
  const [pending, setPending] = useState<string | null>(null);
  const [revision, setRevision] = useState(0);
  const busy = useRef(false);

  useEffect(() => {
    let active = true;
    let sequence = 0;
    async function refreshCount(): Promise<void> {
      const request = ++sequence;
      try {
        const next = await loadNotificationCount();
        if (active && request === sequence) { setCount(next); setCountError(null); }
      } catch {
        if (active && request === sequence) setCountError('Could not load the unread count.');
      }
    }
    function readChanged(event: Event): void {
      const { id, readAt } = (event as CustomEvent<NotificationReadChange>).detail;
      setItems((current) => current.map((item) => id === null || item.id === id ? { ...item, read_at: item.read_at ?? readAt } : item));
      void refreshCount();
    }
    void refreshCount();
    window.addEventListener(NOTIFICATIONS_READ, readChanged);
    return () => { active = false; window.removeEventListener(NOTIFICATIONS_READ, readChanged); };
  }, [revision, enabled]);

  useEffect(() => {
    if (!enabled) return;
    let active = true;
    setLoading(true);
    setLoadError(null);
    setItems([]);
    setMeta(null);
    const changes: NotificationReadChange[] = [];
    function captureRead(event: Event): void {
      changes.push((event as CustomEvent<NotificationReadChange>).detail);
    }
    window.addEventListener(NOTIFICATIONS_READ, captureRead);
    async function load(): Promise<void> {
      try {
        const response = await loadNotifications(page);
        if (active) {
          // A bell action may finish while this page's older request is still in
          // flight. Keep those confirmed reads when that response finally arrives.
          let rows = response.data;
          for (const { id, readAt } of changes) {
            rows = rows.map((item) => id === null || item.id === id ? { ...item, read_at: item.read_at ?? readAt } : item);
          }
          setItems(rows);
          setMeta(response.meta);
        }
      } catch {
        if (active) setLoadError('Could not load notifications.');
      } finally {
        window.removeEventListener(NOTIFICATIONS_READ, captureRead);
        if (active) setLoading(false);
      }
    }
    void load();
    return () => { active = false; window.removeEventListener(NOTIFICATIONS_READ, captureRead); };
  }, [page, enabled, revision]);

  async function markRead(item: NotificationItem | null): Promise<boolean> {
    if (item?.read_at) return true;
    if (busy.current) return false;
    busy.current = true;
    setPending(item?.id ?? 'all');
    setActionError(null);
    try {
      await markNotificationsRead(item?.id ?? null);
      return true;
    } catch {
      setActionError('Could not mark notifications as read. Please try again.');
      return false;
    } finally {
      busy.current = false;
      setPending(null);
    }
  }

  function retry(): void { setRevision((current) => current + 1); }

  return { items, meta, loading, count, pending, markRead, retry, loadError: loadError ?? countError, actionError };
}
