import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { AccountStorageUsage } from '@/library/types';
import { formatBytes } from '@/media/types';

interface StorageQuotaSummaryProps {
  usage: AccountStorageUsage;
}

export function StorageQuotaSummary({ usage }: StorageQuotaSummaryProps) {
  const limit = usage.limit_bytes ?? null;
  const used = usage.used_bytes ?? usage.active_bytes;
  const reserved = usage.reserved_bytes ?? 0;
  const remaining = limit === null ? null : usage.remaining_bytes ?? Math.max(0, limit - used);
  const percent = limit === null ? 0 : limit === 0 ? 100 : Math.min(100, used / limit * 100);

  return <section aria-label="Storage allowance" className="space-y-3">
    <div className="flex flex-wrap justify-between gap-2 text-sm">
      <p className="font-medium">{limit === null ? `${formatBytes(used)} used · No storage limit` : `${formatBytes(used)} of ${formatBytes(limit)} used`}</p>
      {remaining !== null && <p className="text-muted-foreground">{formatBytes(remaining)} available</p>}
    </div>
    {limit !== null && <progress
      aria-label="Storage allowance used"
      aria-valuetext={`${formatBytes(used)} of ${formatBytes(limit)} used`}
      max={100}
      value={percent}
      className="h-2 w-full overflow-hidden rounded-full [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-primary"
    />}
    {reserved > 0 && <p className="text-xs text-muted-foreground">{formatBytes(reserved)} reserved for unfinished uploads is included in your usage.</p>}
    <p className="text-xs text-muted-foreground">Active uploads and saved originals count once per account. Deleted files kept for admin retention, notes, and viewing shared files do not use your allowance.</p>
    {usage.warning === 'near_limit' && <Alert><AlertTitle>Storage is nearly full</AlertTitle><AlertDescription>You have used at least 90% of your allowance. Remove files you no longer need or ask an administrator for a larger limit.</AlertDescription></Alert>}
    {usage.warning === 'limit_reached' && <Alert variant="destructive"><AlertTitle>Storage allowance reached</AlertTitle><AlertDescription>Remove files or ask an administrator to increase your limit before uploading or saving more media. Existing items and notes remain available.</AlertDescription></Alert>}
    {usage.warning === 'unknown_size' && <Alert variant="destructive"><AlertTitle>Storage sizes need checking</AlertTitle><AlertDescription>Some active files have unknown sizes. New uploads and saves are paused until an administrator checks their storage metadata. Existing items and notes remain available.</AlertDescription></Alert>}
  </section>;
}
