import { createElement, type ReactNode } from 'react';

/**
 * A small, dependency-free markdown renderer that emits React elements (never
 * `dangerouslySetInnerHTML`), so user-authored story text cannot inject HTML or
 * scripts and stays compatible with the app's CSP. It covers the common
 * CommonMark subset: headings, paragraphs, bold/italic/inline-code, links,
 * ordered/unordered lists, blockquotes, fenced code blocks, and horizontal
 * rules. Richer markdown (tables, images, nested lists) is intentionally out of
 * scope — see the follow-up issue.
 */
interface MarkdownProps {
  source: string | null | undefined;
  className?: string;
}

let keySeq = 0;
function nextKey(): string {
  keySeq += 1;
  return `md-${keySeq}`;
}

/** Only allow safe link protocols; everything else renders as plain text. */
function safeHref(href: string): string | null {
  const trimmed = href.trim();
  if (/^(https?:\/\/|\/|mailto:|#)/i.test(trimmed)) {
    return trimmed;
  }
  return null;
}

/** Parse inline markup (links, bold, italic, code) into React nodes. */
function renderInline(text: string): ReactNode[] {
  const nodes: ReactNode[] = [];
  let remaining = text;

  // Ordered by precedence; code first so its contents aren't re-parsed.
  const patterns: { re: RegExp; build: (m: RegExpExecArray) => ReactNode }[] = [
    { re: /`([^`]+)`/, build: (m) => <code key={nextKey()} className="rounded bg-muted px-1 py-0.5 text-sm">{m[1] ?? ''}</code> },
    {
      re: /\[([^\]]+)\]\(([^)\s]+)\)/,
      build: (m) => {
        const href = safeHref(m[2] ?? '');
        return href ? (
          <a key={nextKey()} href={href} className="text-primary underline underline-offset-4" rel="noopener noreferrer" target={href.startsWith('http') ? '_blank' : undefined}>
            {m[1] ?? ''}
          </a>
        ) : (
          <span key={nextKey()}>{m[0]}</span>
        );
      },
    },
    { re: /\*\*([^*]+)\*\*/, build: (m) => <strong key={nextKey()}>{renderInline(m[1] ?? '')}</strong> },
    { re: /(?:\*([^*]+)\*|_([^_]+)_)/, build: (m) => <em key={nextKey()}>{renderInline(m[1] ?? m[2] ?? '')}</em> },
  ];

  while (remaining.length > 0) {
    let earliest: { index: number; length: number; node: ReactNode } | null = null;

    for (const { re, build } of patterns) {
      const match = re.exec(remaining);
      if (match && (earliest === null || match.index < earliest.index)) {
        earliest = { index: match.index, length: match[0].length, node: build(match) };
      }
    }

    if (earliest === null) {
      nodes.push(remaining);
      break;
    }

    if (earliest.index > 0) {
      nodes.push(remaining.slice(0, earliest.index));
    }
    nodes.push(earliest.node);
    remaining = remaining.slice(earliest.index + earliest.length);
  }

  return nodes;
}

export function Markdown({ source, className }: MarkdownProps) {
  const blocks: ReactNode[] = [];
  const lines = (source ?? '').replace(/\r\n/g, '\n').split('\n');

  let i = 0;
  let paragraph: string[] = [];

  const flushParagraph = (): void => {
    if (paragraph.length > 0) {
      blocks.push(<p key={nextKey()}>{renderInline(paragraph.join(' '))}</p>);
      paragraph = [];
    }
  };

  while (i < lines.length) {
    const line = lines[i] ?? '';

    // Fenced code block.
    if (/^```/.test(line)) {
      flushParagraph();
      const code: string[] = [];
      i += 1;
      while (i < lines.length && !/^```/.test(lines[i] ?? '')) {
        code.push(lines[i] ?? '');
        i += 1;
      }
      i += 1; // closing fence
      blocks.push(
        <pre key={nextKey()} className="overflow-x-auto rounded-md bg-muted p-3 text-sm">
          <code>{code.join('\n')}</code>
        </pre>,
      );
      continue;
    }

    // Horizontal rule.
    if (/^(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
      flushParagraph();
      blocks.push(<hr key={nextKey()} className="my-4 border-border" />);
      i += 1;
      continue;
    }

    // Heading.
    const heading = /^(#{1,6})\s+(.*)$/.exec(line);
    if (heading) {
      flushParagraph();
      const level = (heading[1] ?? '#').length;
      blocks.push(createElement(`h${level}`, { key: nextKey() }, renderInline(heading[2] ?? '')));
      i += 1;
      continue;
    }

    // Blockquote (consecutive `>` lines).
    if (/^>\s?/.test(line)) {
      flushParagraph();
      const quote: string[] = [];
      while (i < lines.length && /^>\s?/.test(lines[i] ?? '')) {
        quote.push((lines[i] ?? '').replace(/^>\s?/, ''));
        i += 1;
      }
      blocks.push(
        <blockquote key={nextKey()} className="border-l-4 border-border pl-4 italic text-muted-foreground">
          {renderInline(quote.join(' '))}
        </blockquote>,
      );
      continue;
    }

    // Unordered list.
    if (/^\s*[-*]\s+/.test(line)) {
      flushParagraph();
      const items: string[] = [];
      while (i < lines.length && /^\s*[-*]\s+/.test(lines[i] ?? '')) {
        items.push((lines[i] ?? '').replace(/^\s*[-*]\s+/, ''));
        i += 1;
      }
      blocks.push(
        <ul key={nextKey()} className="list-disc pl-6">
          {items.map((item) => (
            <li key={nextKey()}>{renderInline(item)}</li>
          ))}
        </ul>,
      );
      continue;
    }

    // Ordered list.
    if (/^\s*\d+\.\s+/.test(line)) {
      flushParagraph();
      const items: string[] = [];
      while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i] ?? '')) {
        items.push((lines[i] ?? '').replace(/^\s*\d+\.\s+/, ''));
        i += 1;
      }
      blocks.push(
        <ol key={nextKey()} className="list-decimal pl-6">
          {items.map((item) => (
            <li key={nextKey()}>{renderInline(item)}</li>
          ))}
        </ol>,
      );
      continue;
    }

    // Blank line ends a paragraph.
    if (line.trim() === '') {
      flushParagraph();
      i += 1;
      continue;
    }

    paragraph.push(line.trim());
    i += 1;
  }
  flushParagraph();

  return <div className={className ?? 'prose prose-sm dark:prose-invert max-w-none'}>{blocks}</div>;
}
