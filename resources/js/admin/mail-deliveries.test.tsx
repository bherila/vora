import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { MailDeliveriesPage } from './mail-deliveries';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn() } }));

const meta = { current_page: 1, last_page: 1, per_page: 50, total: 2, has_more: false };
const rows = [
  { id: 2, type: 'VerifyEmail', recipient: 'typo@exmaple.test', user: { id: 9, name: 'Sam' }, mailer: 'hybrid', status: 'bounced', status_reason: '550 5.1.1 User unknown', sent_at: '2026-10-26T18:00:00Z', status_at: '2026-10-26T18:00:05Z' },
  { id: 1, type: 'ResetPassword', recipient: 'guest@example.test', user: null, mailer: 'hybrid', status: 'sent', status_reason: null, sent_at: '2026-10-26T17:00:00Z', status_at: '2026-10-26T17:00:00Z' },
];

describe('MailDeliveriesPage', () => {
  beforeEach(() => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ data: rows, meta, summary: { delivered: 4, sent: 1, bounced: 1, complained: 1 }, reports: { brevo: true, ses: false } });
  });

  it('shows outcomes, reasons and a problem count, and filters by status and recipient', async () => {
    render(<MailDeliveriesPage />);
    expect(await screen.findByText('typo@exmaple.test')).toBeInTheDocument();
    expect(screen.getByText('Bounced', { selector: '[data-slot=badge]' })).toBeInTheDocument();
    expect(screen.getByText('550 5.1.1 User unknown')).toBeInTheDocument();
    expect(screen.getByText('Sent, awaiting report', { selector: '[data-slot=badge]' })).toBeInTheDocument();
    expect(screen.getByText('Problems').nextSibling).toHaveTextContent('2');
    expect(screen.queryByText(/Delivery reports are not configured/)).toBeNull();

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'bounced' } });
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/mail-deliveries?page=1&status=bounced'));
    fireEvent.change(screen.getByLabelText('Recipient'), { target: { value: ' exmaple ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/mail-deliveries?page=1&status=bounced&search=exmaple'));
  });

  it('explains when no provider reports delivery', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ data: [], meta: { ...meta, total: 0 }, summary: {}, reports: { brevo: false, ses: false } });
    render(<MailDeliveriesPage />);
    expect(await screen.findByText(/Delivery reports are not configured/)).toBeInTheDocument();
    expect(screen.getByText('No emails match.')).toBeInTheDocument();
  });
});
