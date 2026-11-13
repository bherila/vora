import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { NotificationBell } from './NotificationBell';
import { notificationDate, type NotificationItem } from './notifications';
import { NotificationsPage } from './notifications-page';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));
const get = jest.mocked(fetchWrapper.get);
const post = jest.mocked(fetchWrapper.post);

function item(id: string, name: string, read = false): NotificationItem {
  return { id, type: 'post_comment', data: { actor_name: name, url: `/p/${id}` }, read_at: read ? '2026-11-01T12:00:00Z' : null, created_at: '2026-11-01T12:00:00Z' };
}

function page(data: NotificationItem[], current = 1, last = 2) {
  return { success: true, data, meta: { current_page: current, last_page: last, total: 25, has_more: current < last } };
}

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  post.mockResolvedValue({ success: true });
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count')
    ? { success: true, data: { count: 1 } }
    : url.endsWith('page=2') ? page([item('older', 'Earlier', true)], 2) : page([item('new', 'Recent')])));
});

it('fetches and renders earlier pages and read state, then returns to recent notifications', async () => {
  render(<NotificationsPage />);
  expect(await screen.findByRole('link', { name: 'Recent commented on your post.' })).toHaveAttribute('href', '/p/new');
  expect(screen.getByText('Page 1 of 2 · 25 notifications')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  expect(await screen.findByRole('link', { name: 'Earlier commented on your post.' })).toBeInTheDocument();
  expect(get).toHaveBeenCalledWith('/api/notifications?page=2');
  expect(screen.queryByRole('button', { name: /Mark as read:/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
  expect(await screen.findByText('Recent commented on your post.')).toBeInTheDocument();
});

it('distinguishes a failed load from an empty history and retries the same page', async () => {
  get.mockImplementation((url: string) => url.includes('unread-count') ? Promise.resolve({ success: true, data: { count: 0 } }) : Promise.reject(new Error('Unavailable')));
  render(<NotificationsPage />);
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not load notifications.');
  expect(screen.queryByText('No notifications on this page.')).not.toBeInTheDocument();
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count') ? { success: true, data: { count: 0 } } : page([], 1, 1)));
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
  expect(await screen.findByText('No notifications on this page.')).toBeInTheDocument();
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('keeps a failed read unread, prevents duplicate submissions and permits retry', async () => {
  let rejectRead: (error: Error) => void = () => { throw new Error('Read did not start'); };
  post.mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectRead = reject; }));
  render(<NotificationsPage />);
  const mark = await screen.findByRole('button', { name: 'Mark as read: Recent commented on your post.' });
  fireEvent.click(mark);
  fireEvent.click(mark);
  expect(post).toHaveBeenCalledTimes(1);
  expect(mark).toBeDisabled();
  rejectRead(new Error('Network failure'));
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not mark notifications as read.');
  expect(mark).toBeEnabled();
  expect(screen.getByText(/Unread/)).toBeInTheDocument();
  fireEvent.click(mark);
  await waitFor(() => expect(mark).not.toBeInTheDocument());
  expect(post).toHaveBeenLastCalledWith('/api/notifications/new/read', {});
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('synchronizes mark-all and badge state between the history and bell', async () => {
  let unread = 1;
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count') ? { success: true, data: { count: unread } } : page([item('new', 'Recent')])));
  post.mockImplementation(() => { unread = 0; return Promise.resolve({ success: true }); });
  render(<><NotificationBell /><NotificationsPage /></>);
  const bell = screen.getByRole('button', { name: 'Notifications' });
  await waitFor(() => expect(within(bell).getByText('1')).toBeInTheDocument());
  await screen.findByText('Recent commented on your post.');
  fireEvent.click(screen.getByRole('button', { name: 'Mark all read' }));
  await waitFor(() => expect(within(bell).queryByText('1')).not.toBeInTheDocument());
  expect(post).toHaveBeenCalledWith('/api/notifications/read-all', {});
  expect(screen.queryByRole('button', { name: /Mark as read:/ })).not.toBeInTheDocument();
});

it('keeps View all reachable on bell load failure and retries instead of silently showing empty', async () => {
  get.mockImplementation((url: string) => url.includes('unread-count') ? Promise.resolve({ success: true, data: { count: 0 } }) : Promise.reject(new Error('Unavailable')));
  render(<NotificationBell />);
  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not load notifications.');
  expect(screen.getByRole('link', { name: 'View all notifications' })).toHaveAttribute('href', '/notifications');
  expect(screen.queryByText('No notifications.')).not.toBeInTheDocument();
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count') ? { success: true, data: { count: 0 } } : page([])));
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
  expect(await screen.findByText('No notifications.')).toBeInTheDocument();
});

it('renders unsafe destinations as text and tolerates malformed dates', async () => {
  const unsafe = item('bad', 'Unsafe');
  unsafe.data.url = 'javascript:alert(1)';
  unsafe.created_at = 'invalid date';
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count') ? { success: true, data: { count: 0 } } : page([unsafe])));
  render(<NotificationsPage />);
  expect(await screen.findByText('Unsafe commented on your post.')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Unsafe commented on your post.' })).not.toBeInTheDocument();
  expect(notificationDate('invalid date')).toBe('');
});


it('does not let a delayed history response undo a read completed in the bell', async () => {
  let finishHistory: (value: ReturnType<typeof page>) => void = () => { throw new Error('History did not start'); };
  let listRequests = 0;
  get.mockImplementation((url: string) => {
    if (url.includes('unread-count')) return Promise.resolve({ success: true, data: { count: 0 } });
    listRequests++;
    return listRequests === 1 ? new Promise((resolve) => { finishHistory = resolve; }) : Promise.resolve(page([item('new', 'Recent')]));
  });
  render(<><NotificationBell /><NotificationsPage /></>);
  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Mark as read: Recent commented on your post.' }));
  await waitFor(() => expect(screen.queryByRole('button', { name: /Mark as read:/ })).not.toBeInTheDocument());
  finishHistory(page([item('new', 'Recent')]));
  await waitFor(() => expect(screen.getAllByText('Recent commented on your post.')).toHaveLength(2));
  expect(screen.queryByRole('button', { name: /Mark as read:/ })).not.toBeInTheDocument();
});


it('refreshes the unread badge when notifications arrive before the bell reopens', async () => {
  let unread = 0;
  get.mockImplementation((url: string) => Promise.resolve(url.includes('unread-count')
    ? { success: true, data: { count: unread } } : page([item('new', 'Recent')])));
  render(<NotificationBell />);
  const bell = screen.getByRole('button', { name: 'Notifications' });
  await waitFor(() => expect(get).toHaveBeenCalledWith('/api/notifications/unread-count'));
  expect(within(bell).queryByText('2')).not.toBeInTheDocument();
  unread = 2;
  fireEvent.click(bell);
  await waitFor(() => expect(within(bell).getByText('2')).toBeInTheDocument());
  await screen.findByText('Recent commented on your post.');
  fireEvent.click(bell);
  unread = 3;
  fireEvent.click(bell);
  await waitFor(() => expect(within(bell).getByText('3')).toBeInTheDocument());
});
