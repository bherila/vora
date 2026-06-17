import { type FormEvent, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

interface StaticPage {
  id: number;
  slug: string;
  title: string;
  body_markdown: string;
  variables: string | Record<string, string> | null;
  is_published: boolean;
  show_in_footer: boolean;
  footer_label: string | null;
  sort_order: number;
}

interface PageForm {
  slug: string;
  title: string;
  body_markdown: string;
  variables_json: string;
  is_published: boolean;
  show_in_footer: boolean;
  footer_label: string;
  sort_order: string;
}

function emptyForm(): PageForm {
  return {
    slug: '',
    title: '',
    body_markdown: '',
    variables_json: '{}',
    is_published: true,
    show_in_footer: false,
    footer_label: '',
    sort_order: '0',
  };
}

function formFromPage(page: StaticPage): PageForm {
  const variables = typeof page.variables === 'string'
    ? page.variables
    : JSON.stringify(page.variables ?? {}, null, 2);

  return {
    slug: page.slug,
    title: page.title,
    body_markdown: page.body_markdown,
    variables_json: variables,
    is_published: page.is_published,
    show_in_footer: page.show_in_footer,
    footer_label: page.footer_label ?? '',
    sort_order: String(page.sort_order),
  };
}

function payloadFromForm(form: PageForm) {
  return {
    slug: form.slug,
    title: form.title,
    body_markdown: form.body_markdown,
    variables: JSON.parse(form.variables_json || '{}') as Record<string, string>,
    is_published: form.is_published,
    show_in_footer: form.show_in_footer,
    footer_label: form.footer_label || null,
    sort_order: Number(form.sort_order || 0),
  };
}

function getErrorMessage(error: unknown): string {
  // fetchWrapper rejects failed responses with a plain string (Laravel's message),
  // so surface that instead of the generic fallback for validation/auth errors.
  if (typeof error === 'string') {
    return error;
  }

  return error instanceof Error ? error.message : 'Request failed.';
}

function AdminStaticPagesPage() {
  const [pages, setPages] = useState<StaticPage[]>([]);
  const [selected, setSelected] = useState<StaticPage | null>(null);
  const [form, setForm] = useState<PageForm>(emptyForm());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const loadPages = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/admin/pages') as { success: boolean; data: StaticPage[] };
      setPages(response.data ?? []);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadPages();
  }, []);

  const editPage = (page: StaticPage): void => {
    setSelected(page);
    setForm(formFromPage(page));
    setMessage('');
    setError('');
  };

  const newPage = (): void => {
    setSelected(null);
    setForm(emptyForm());
    setMessage('');
    setError('');
  };

  const savePage = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setMessage('');

    try {
      const payload = payloadFromForm(form);
      if (selected) {
        await fetchWrapper.put(`/api/admin/pages/${selected.id}`, payload);
      } else {
        await fetchWrapper.post('/api/admin/pages', payload);
      }
      setMessage('Page saved.');
      await loadPages();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const seedDefaults = async (): Promise<void> => {
    setSaving(true);
    setError('');
    try {
      await fetchWrapper.post('/api/admin/pages/seed-defaults', {});
      setMessage('Default pages created or refreshed.');
      await loadPages();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Static pages</h1>
          <p className="text-sm text-muted-foreground">Edit markdown pages, boilerplate legal text, footer links, and template variables.</p>
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={newPage}>New page</Button>
          <Button type="button" variant="secondary" disabled={saving} onClick={() => void seedDefaults()}>Seed defaults</Button>
        </div>
      </header>

      {error && <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
      {message && <div className="rounded-md border border-green-500/40 bg-green-500/10 p-3 text-sm text-green-700 dark:text-green-300">{message}</div>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <section className="rounded-lg border p-4">
          <h2 className="mb-3 text-lg font-semibold">Pages</h2>
          {loading ? <p>Loading…</p> : (
            <div className="space-y-2">
              {pages.map((page) => (
                <button key={page.id} type="button" className="block w-full rounded-md border p-3 text-left hover:bg-muted" onClick={() => editPage(page)}>
                  <span className="font-medium">{page.title}</span>
                  <span className="block text-xs text-muted-foreground">/{page.slug} {page.show_in_footer ? '· footer' : ''} {page.is_published ? '' : '· draft'}</span>
                </button>
              ))}
            </div>
          )}
        </section>

        <form className="space-y-4 rounded-lg border p-4" onSubmit={(event) => void savePage(event)}>
          <h2 className="text-lg font-semibold">{selected ? `Edit ${selected.title}` : 'Create page'}</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-1 text-sm font-medium">Slug<Input value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} /></label>
            <label className="space-y-1 text-sm font-medium">Title<Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
            <label className="space-y-1 text-sm font-medium">Footer label<Input value={form.footer_label} onChange={(e) => setForm({ ...form, footer_label: e.target.value })} /></label>
            <label className="space-y-1 text-sm font-medium">Sort order<Input type="number" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: e.target.value })} /></label>
          </div>
          <div className="flex flex-wrap gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_published} onChange={(e) => setForm({ ...form, is_published: e.target.checked })} /> Published</label>
            <label className="flex items-center gap-2"><input type="checkbox" checked={form.show_in_footer} onChange={(e) => setForm({ ...form, show_in_footer: e.target.checked })} /> Show in footer</label>
          </div>
          <label className="space-y-1 text-sm font-medium">Variables JSON<Textarea rows={5} value={form.variables_json} onChange={(e) => setForm({ ...form, variables_json: e.target.value })} /></label>
          <label className="space-y-1 text-sm font-medium">Markdown body<Textarea rows={18} value={form.body_markdown} onChange={(e) => setForm({ ...form, body_markdown: e.target.value })} /></label>
          <p className="text-xs text-muted-foreground">Use variables like {'{{app_name}}'} and define their values in the JSON field.</p>
          <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save page'}</Button>
        </form>
      </div>
    </div>
  );
}

const root = document.getElementById('admin-static-pages');
if (root) {
  createRoot(root).render(<AdminStaticPagesPage />);
}
