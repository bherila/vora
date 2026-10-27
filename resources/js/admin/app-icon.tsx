import { type FormEvent, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

interface AppIconData {
  app_name: string;
  icon_url: string | null;
  apple_touch_icon_url: string | null;
}

function failureMessage(failure: unknown, fallback: string): string {
  if (typeof failure === 'string' && failure) return failure;
  return failure instanceof Error && failure.message ? failure.message : fallback;
}

/**
 * This installation's Home Screen and browser icon. It is stored on the
 * server, so each installation can have its own without changing the code.
 */
export function AppIconPage() {
  const [data, setData] = useState<AppIconData | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    fetchWrapper.get('/api/admin/app-icon')
      .then((response: { data: AppIconData }) => setData(response.data))
      .catch((failure: unknown) => setError(failureMessage(failure, 'The current icon could not be loaded.')));
  }, []);

  const upload = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (!file) return;
    const body = new FormData();
    body.append('icon', file);
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetchWrapper.post('/api/admin/app-icon', body) as { data: AppIconData };
      setData(response.data);
      setFile(null);
      setNotice('Icon updated. Devices pick it up the next time the site is added to a Home Screen.');
    } catch (failure) {
      setError(failureMessage(failure, 'The icon could not be uploaded.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetchWrapper.delete('/api/admin/app-icon') as { data: AppIconData };
      setData(response.data);
      setNotice('Icon removed.');
    } catch (failure) {
      setError(failureMessage(failure, 'The icon could not be removed.'));
    } finally {
      setBusy(false);
    }
  };

  return <div className={`${READING_PAGE_WIDTH} space-y-6 px-4 py-6`}>
    <header className="space-y-1">
      <h1 className="text-2xl font-semibold">App icon</h1>
      <p className="text-sm text-muted-foreground">Shown in browser tabs and when someone adds {data?.app_name ?? 'this site'} to their Home Screen. Use a square PNG, JPEG or WebP image, at least 512 pixels wide.</p>
    </header>
    {data && <section aria-label="Current icon" className="flex items-center gap-4">
      {data.apple_touch_icon_url
        ? <img src={data.apple_touch_icon_url} alt="Current app icon" width={90} height={90} className="size-[90px] rounded-[20px] border" />
        : <div className="flex size-[90px] items-center justify-center rounded-[20px] border text-xs text-muted-foreground">No icon</div>}
      <div className="space-y-2 text-sm">
        <p className="font-medium">{data.app_name}</p>
        {data.icon_url && <Button variant="outline" size="sm" disabled={busy} onClick={() => void remove()}>Remove icon</Button>}
      </div>
    </section>}
    <form className="grid gap-3" onSubmit={(event) => void upload(event)}>
      <div className="grid gap-1">
        <Label htmlFor="app-icon-file">{data?.icon_url ? 'Replace icon' : 'Upload icon'}</Label>
        <Input id="app-icon-file" type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
      </div>
      <Button type="submit" className="w-fit" disabled={busy || !file}>{busy ? 'Saving…' : 'Save icon'}</Button>
    </form>
    {notice && <p role="status" className="text-sm">{notice}</p>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
  </div>;
}

const mountEl = document.getElementById('admin-app-icon');
if (mountEl) createRoot(mountEl).render(<AppIconPage />);
