import { type FormEvent, useEffect, useState } from 'react';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

type ImportMode = 'update' | 'replace';
type ImportSource = 'download' | 'upload';

interface ImportResult {
  dry_run: boolean;
  parsed: number;
  added: number;
  updated: number;
  removed: number;
  kept_in_use: number;
  rehomed: number;
  accounts_affected: number;
  regions_removed: number;
  countries_removed: number;
  prune_skipped: boolean;
}

interface ImportStatus {
  state: 'running' | 'succeeded' | 'failed';
  source?: string;
  dataset?: string;
  mode?: ImportMode;
  rehome?: boolean;
  dry_run?: boolean;
  /** A command-line preview of files on the server; it cannot be applied here. */
  local_files?: boolean;
  started_at?: string;
  finished_at?: string;
  error?: string;
  result?: ImportResult;
}

export interface AdminPlacesData {
  dataset: string;
  datasets: string[];
  auto_refresh: boolean;
  counts: { places: number; current_places: number; regions: number; countries: number; accounts_with_location: number };
  imported_at: string | null;
  pending: { mode: ImportMode; rehome: boolean; dry_run: boolean; uploaded: boolean; source: string | null; requested_at: string | null } | null;
  status: ImportStatus | null;
  max_upload_bytes: number;
}

interface PayloadResponse { message?: string; data: AdminPlacesData }

const POLL_MS = 5000;

const DATASET_LABELS: Record<string, string> = {
  cities500: 'cities500 — places with 500+ people (largest)',
  cities1000: 'cities1000 — 1,000+ people',
  cities5000: 'cities5000 — 5,000+ people (recommended)',
  cities15000: 'cities15000 — 15,000+ people (smallest)',
};

function failureMessage(failure: unknown, fallback: string): string {
  if (typeof failure === 'string' && failure) return failure;
  return failure instanceof Error && failure.message ? failure.message : fallback;
}

function formatDate(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString() : '—';
}

function formatMegabytes(bytes: number): string {
  return `${Math.floor(bytes / (1024 * 1024))} MB`;
}

function ResultSummary({ status }: { status: ImportStatus }) {
  const result = status.result;
  if (!result) return null;
  const rows: Array<[string, number]> = [
    ['Cities read', result.parsed],
    ['Added', result.added],
    ['Updated', result.updated],
    ['Removed', result.removed],
    ['Kept because someone uses them', result.kept_in_use],
    ['Accounts on dropped cities', result.accounts_affected],
    ['Accounts moved to the nearest city', result.rehomed],
    ['Unused regions removed', result.regions_removed],
    ['Unused countries removed', result.countries_removed],
  ];
  return (
    <div className="space-y-2">
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
        {rows.map(([label, value]) => (
          <div key={label} className="contents">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right tabular-nums">{value.toLocaleString()}</dd>
          </div>
        ))}
      </dl>
      {result.prune_skipped && (
        <p className="text-sm text-muted-foreground">Nothing was removed: the file had fewer cities than the safety minimum, or pruning was off.</p>
      )}
    </div>
  );
}

/** Admin → City list: the GeoNames dataset, imports and their outcome. */
export function AdminPlacesPage() {
  const [data, setData] = useState<AdminPlacesData | null>(() => readInitialData<{ adminPlaces?: AdminPlacesData }>().adminPlaces ?? null);
  const [dataset, setDataset] = useState(data?.dataset ?? 'cities5000');
  const [autoRefresh, setAutoRefresh] = useState(data?.auto_refresh ?? false);
  const [mode, setMode] = useState<ImportMode>('update');
  const [rehome, setRehome] = useState(false);
  const [source, setSource] = useState<ImportSource>('download');
  const [cities, setCities] = useState<File | null>(null);
  const [admin1, setAdmin1] = useState<File | null>(null);
  const [countries, setCountries] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const active = Boolean(data?.pending) || data?.status?.state === 'running';

  useEffect(() => {
    if (!active) return undefined;
    const timer = window.setInterval(() => {
      fetchWrapper.get('/api/admin/places')
        .then((response: PayloadResponse) => setData(response.data))
        .catch(() => undefined);
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [active]);

  async function run(action: () => Promise<PayloadResponse>, fallback: string) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await action();
      setData(response.data);
      setNotice(response.message ?? '');
    } catch (failure) {
      setError(failureMessage(failure, fallback));
    } finally {
      setBusy(false);
    }
  }

  function saveSettings(event: FormEvent) {
    event.preventDefault();
    void run(() => fetchWrapper.put('/api/admin/places/settings', { dataset, auto_refresh: autoRefresh }), 'The settings could not be saved.');
  }

  function queue(dryRun: boolean, fromPreview = false) {
    // Applying a preview repeats exactly what was previewed.
    const previewed = fromPreview ? data?.status : null;
    const runMode = previewed?.mode ?? mode;
    const runRehome = previewed ? Boolean(previewed.rehome) : rehome;
    const body = new FormData();
    body.append('source', fromPreview ? 'preview' : source);
    body.append('mode', runMode);
    body.append('rehome', runMode === 'replace' && runRehome ? '1' : '0');
    body.append('dry_run', dryRun ? '1' : '0');
    if (!fromPreview && source === 'upload') {
      if (cities) body.append('cities', cities);
      if (admin1) body.append('admin1', admin1);
      if (countries) body.append('countries', countries);
    }
    void run(() => fetchWrapper.post('/api/admin/places/import', body), 'The import could not be queued.');
  }

  if (!data) return <div className={`${READING_PAGE_WIDTH} px-4 py-6`}>Loading…</div>;

  const uploadReady = source === 'download' || (cities !== null && admin1 !== null && countries !== null);
  const status = data.status;

  return (
    <div className={`${READING_PAGE_WIDTH} space-y-6 px-4 py-6`}>
      <div>
        <h1 className="text-2xl font-bold">City list</h1>
        <p className="text-sm text-muted-foreground">
          The cities people choose for their location, from a local copy of GeoNames (CC BY 4.0). Imports run in the background within a minute of being queued.
        </p>
      </div>

      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {notice && <Alert><AlertDescription>{notice}</AlertDescription></Alert>}

      <Card>
        <CardHeader>
          <CardTitle>Current list</CardTitle>
          <CardDescription>Last complete import: {formatDate(data.imported_at)}</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
            <dt className="text-muted-foreground">Cities offered in search</dt><dd className="text-right tabular-nums">{data.counts.current_places.toLocaleString()}</dd>
            <dt className="text-muted-foreground">Cities stored (including ones kept for their owners)</dt><dd className="text-right tabular-nums">{data.counts.places.toLocaleString()}</dd>
            <dt className="text-muted-foreground">Regions</dt><dd className="text-right tabular-nums">{data.counts.regions.toLocaleString()}</dd>
            <dt className="text-muted-foreground">Countries</dt><dd className="text-right tabular-nums">{data.counts.countries.toLocaleString()}</dd>
            <dt className="text-muted-foreground">Accounts with a location</dt><dd className="text-right tabular-nums">{data.counts.accounts_with_location.toLocaleString()}</dd>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Settings</CardTitle>
          <CardDescription>The dataset used by downloads and the monthly refresh.</CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={saveSettings}>
            <div className="grid gap-2">
              <Label htmlFor="places-dataset">Dataset</Label>
              <select id="places-dataset" className="h-9 rounded-md border border-input bg-background px-2 text-sm" value={dataset} onChange={(event) => setDataset(event.target.value)} disabled={busy}>
                {data.datasets.map((value) => <option key={value} value={value}>{DATASET_LABELS[value] ?? value}</option>)}
              </select>
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={autoRefresh} onChange={(event) => setAutoRefresh(event.target.checked)} disabled={busy} />
              Refresh from GeoNames every month
            </label>
            <Button type="submit" disabled={busy}>Save settings</Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Import</CardTitle>
          <CardDescription>Preview first to see what would change. Nothing removes a city someone has chosen unless you move them.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Mode</legend>
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" name="places-mode" value="update" checked={mode === 'update'} onChange={() => setMode('update')} disabled={busy} />
              <span><strong>Update</strong> — add and refresh cities; remove cities the new data dropped unless someone uses them.</span>
            </label>
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" name="places-mode" value="replace" checked={mode === 'replace'} onChange={() => setMode('replace')} disabled={busy} />
              <span><strong>Replace</strong> — Update, and also remove regions and countries no city uses.</span>
            </label>
            {mode === 'replace' && (
              <label className="ml-6 flex items-start gap-2 text-sm">
                <input type="checkbox" checked={rehome} onChange={(event) => setRehome(event.target.checked)} disabled={busy} />
                <span>Move people whose city was dropped to the nearest remaining city, then remove it. This changes the location they chose.</span>
              </label>
            )}
          </fieldset>

          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Source</legend>
            <label className="flex items-center gap-2 text-sm">
              <input type="radio" name="places-source" value="download" checked={source === 'download'} onChange={() => setSource('download')} disabled={busy} />
              Download {data.dataset} from GeoNames
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input type="radio" name="places-source" value="upload" checked={source === 'upload'} onChange={() => setSource('upload')} disabled={busy} />
              Upload GeoNames files
            </label>
            {source === 'upload' && (
              <div className="ml-6 grid gap-3">
                <p className="text-xs text-muted-foreground">
                  From download.geonames.org/export/dump. Up to {formatMegabytes(data.max_upload_bytes)} each on this server.
                </p>
                <div className="grid gap-1">
                  <Label htmlFor="places-cities">Cities (.zip or .txt, e.g. {data.dataset}.zip)</Label>
                  <input id="places-cities" type="file" accept=".zip,.txt" onChange={(event) => setCities(event.target.files?.[0] ?? null)} disabled={busy} />
                </div>
                <div className="grid gap-1">
                  <Label htmlFor="places-admin1">Regions (admin1CodesASCII.txt)</Label>
                  <input id="places-admin1" type="file" accept=".txt" onChange={(event) => setAdmin1(event.target.files?.[0] ?? null)} disabled={busy} />
                </div>
                <div className="grid gap-1">
                  <Label htmlFor="places-countries">Countries (countryInfo.txt)</Label>
                  <input id="places-countries" type="file" accept=".txt" onChange={(event) => setCountries(event.target.files?.[0] ?? null)} disabled={busy} />
                </div>
              </div>
            )}
          </fieldset>

          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" disabled={busy || active || !uploadReady} onClick={() => queue(true)}>Preview changes</Button>
            <Button type="button" disabled={busy || active || !uploadReady} onClick={() => queue(false)}>Import now</Button>
          </div>
        </CardContent>
      </Card>

      {data.pending && (
        <Card>
          <CardHeader>
            <CardTitle>Queued</CardTitle>
            <CardDescription>
              {data.pending.dry_run ? 'Preview' : 'Import'} ({data.pending.mode}{data.pending.rehome ? ', moving people' : ''}{data.pending.uploaded ? ', uploaded files' : ''}) requested {formatDate(data.pending.requested_at)}. It starts within a minute.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button type="button" variant="outline" disabled={busy} onClick={() => void run(() => fetchWrapper.delete('/api/admin/places/import'), 'The queued import could not be cancelled.')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      )}

      {status && (
        <Card>
          <CardHeader>
            <CardTitle>
              {status.state === 'running' ? 'Running' : status.state === 'failed' ? 'Last run failed' : status.dry_run ? 'Preview' : 'Last import'}
            </CardTitle>
            <CardDescription>
              {status.dry_run ? 'Preview' : 'Import'} of {status.dataset} ({status.mode}{status.rehome ? ', moving people' : ''}) · started {formatDate(status.started_at)}
              {status.finished_at ? ` · finished ${formatDate(status.finished_at)}` : ''}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {status.state === 'failed' && <p role="alert" className="text-sm text-destructive">{status.error}</p>}
            {status.state === 'running' && <p role="status" className="text-sm text-muted-foreground">Importing… this page updates on its own.</p>}
            <ResultSummary status={status} />
            {status.state === 'succeeded' && status.dry_run && status.local_files && (
              <p className="text-sm text-muted-foreground">This preview read files on the server from the command line, so it can only be applied there.</p>
            )}
            {status.state === 'succeeded' && status.dry_run && !status.local_files && (
              <Button type="button" disabled={busy || active} onClick={() => queue(false, true)}>Apply this preview</Button>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
