import { render, screen } from '@testing-library/react';

import { type PersonaDiscoveryItem,PersonaGrid } from './PersonaGrid';

describe('PersonaGrid', () => {
  it('builds an internal profile link from the persona ULID', () => {
    const persona = {
      id: 9,
      ulid: '01HZX5PERSONA',
      display_name: 'Kira',
      description: null,
      avatar_url: null,
      user_type: null,
      gender: null,
      // Older hydrated payloads may still carry this server-provided field.
      href: 'javascript:alert(document.domain)',
    } satisfies PersonaDiscoveryItem & { href: string };

    render(<PersonaGrid items={[persona]} />);

    expect(screen.getByRole('link', { name: 'Visit' })).toHaveAttribute('href', '/c/01HZX5PERSONA');
  });
});
