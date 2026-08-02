import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';

import { RestrictionBanners } from '@/components/restriction-banners';

describe('RestrictionBanners', () => {
  it('shows capability, reason, expiry, and the appeal path to the subject', () => {
    render(<RestrictionBanners restrictions={[{
      capability: 'media.view',
      label: 'Media viewing',
      reason: 'Safety review',
      expires_at: '2030-01-02T03:04:00Z',
    }]} />);

    expect(screen.getByText(/Media viewing restricted/)).toBeInTheDocument();
    expect(screen.getByText(/Safety review/)).toBeInTheDocument();
    expect(screen.getByText(/Expires/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Review or appeal' })).toHaveAttribute('href', '/account/restrictions');
  });
});
