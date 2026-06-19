import ReactMarkdown, { type Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';

interface MarkdownProps {
  source: string | null | undefined;
  className?: string;
}

function safeUrl(url: string | null | undefined): string | null {
  const trimmed = (url ?? '').trim();
  if (trimmed === '') {
    return null;
  }

  if (/^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(trimmed)) {
    return trimmed;
  }

  return null;
}

function transformUrl(url: string): string {
  return safeUrl(url) ?? '';
}

const components: Components = {
  a({ href, children, ...props }) {
    const safeHref = safeUrl(href);
    if (safeHref === null) {
      return <span>{children}</span>;
    }

    return (
      <a
        {...props}
        href={safeHref}
        className="text-primary underline underline-offset-4"
        rel={safeHref.startsWith('http') ? 'noopener noreferrer' : undefined}
        target={safeHref.startsWith('http') ? '_blank' : undefined}
      >
        {children}
      </a>
    );
  },
  img({ src, alt, ...props }) {
    const safeSrc = safeUrl(src);
    if (safeSrc === null) {
      return null;
    }

    return <img {...props} src={safeSrc} alt={alt ?? ''} loading="lazy" className="max-w-full rounded-md" />;
  },
  table({ children }) {
    return (
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-sm">{children}</table>
      </div>
    );
  },
  th({ children }) {
    return <th className="border border-border px-2 py-1 text-left font-semibold">{children}</th>;
  },
  td({ children }) {
    return <td className="border border-border px-2 py-1">{children}</td>;
  },
  input({ checked, disabled, type }) {
    if (type !== 'checkbox') {
      return null;
    }

    return <input type="checkbox" checked={checked} disabled={disabled ?? true} readOnly className="mr-2 align-middle" />;
  },
};

/**
 * Full CommonMark/GFM renderer for story text. It emits React elements (no
 * `dangerouslySetInnerHTML`) and does not enable raw HTML parsing, preserving
 * the app's CSP and XSS guarantees while supporting tables, task lists, images,
 * and nested lists.
 */
export function Markdown({ source, className }: MarkdownProps) {
  return (
    <div className={className ?? 'prose prose-sm dark:prose-invert max-w-none'}>
      <ReactMarkdown
        components={components}
        remarkPlugins={[remarkGfm]}
        urlTransform={transformUrl}
      >
        {source ?? ''}
      </ReactMarkdown>
    </div>
  );
}
