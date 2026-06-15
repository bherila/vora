import { render, screen } from '@testing-library/react';

import { Markdown } from './Markdown';

describe('Markdown', () => {
  it('renders headings, emphasis, and inline code', () => {
    const { container } = render(<Markdown source={'# Title\n\nSome **bold** and *italic* and `code`.'} />);
    expect(container.querySelector('h1')?.textContent).toBe('Title');
    expect(container.querySelector('strong')?.textContent).toBe('bold');
    expect(container.querySelector('em')?.textContent).toBe('italic');
    expect(container.querySelector('code')?.textContent).toBe('code');
  });

  it('renders safe links and target=_blank for external urls', () => {
    render(<Markdown source={'[click](https://example.com)'} />);
    const link = screen.getByText('click') as HTMLAnchorElement;
    expect(link.tagName).toBe('A');
    expect(link.getAttribute('href')).toBe('https://example.com');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it('does not create anchors for dangerous protocols', () => {
    const { container } = render(<Markdown source={'[x](javascript:alert(1))'} />);
    expect(container.querySelector('a')).toBeNull();
    expect(container.textContent).toContain('[x](javascript:alert(1))');
  });

  it('escapes raw HTML instead of rendering it (no injection)', () => {
    const { container } = render(<Markdown source={'<img src=x onerror=alert(1)> <script>alert(1)</script>'} />);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('script')).toBeNull();
    expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
  });

  it('renders unordered lists', () => {
    const { container } = render(<Markdown source={'- one\n- two'} />);
    expect(container.querySelectorAll('li')).toHaveLength(2);
  });

  it('renders an empty container for empty input', () => {
    const { container } = render(<Markdown source={''} />);
    expect(container.querySelector('div')).not.toBeNull();
  });
});
