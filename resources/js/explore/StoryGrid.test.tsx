import { render, screen } from '@testing-library/react';

import { StoryGrid } from '@/explore/StoryGrid';
import type { StoryDiscoveryItem } from '@/stories/types';

function story(): StoryDiscoveryItem {
  return {
    id: 7,
    ulid: '01HZX5STORY',
    title: 'Test story',
    type: 'long_form',
    owner: null,
    authors: [],
    interests: [],
    node_count: null,
    published_at: null,
  };
}

describe('StoryGrid links', () => {
  it('keeps valid internal story links', () => {
    render(<StoryGrid items={[story()]} />);

    expect(screen.getByRole('link', { name: 'Read' })).toHaveAttribute('href', '/s/01HZX5STORY');
  });

  it('does not turn an untrusted listing URL into a link', () => {
    render(<StoryGrid items={[story()]} getHref={() => 'javascript:alert(1)'} />);

    expect(screen.queryByRole('link', { name: 'Read' })).not.toBeInTheDocument();
  });
});
