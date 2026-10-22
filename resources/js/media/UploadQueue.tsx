import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { mediaTypeForFile } from '@/media/types';
import { type UploadReservation } from '@/media/uploadMediaFile';

export interface QueuedUpload {
  id: number;
  file: File;
  title: string;
  status: 'queued' | 'preparing' | 'uploading' | 'completed' | 'failed' | 'canceled';
  progress: number;
  error: string | null;
  reservation: UploadReservation | null;
}

interface UploadQueueProps {
  items: QueuedUpload[];
  locked: boolean;
  busy: boolean;
  onRemove: (id: number) => void;
  onTitleChange: (id: number, title: string) => void;
}

interface FilePreviewProps {
  file: File;
}

function FilePreview({ file }: FilePreviewProps) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (typeof URL.createObjectURL !== 'function' || mediaTypeForFile(file) === null) return;
    const preview = URL.createObjectURL(file);
    setUrl(preview);
    return () => URL.revokeObjectURL(preview);
  }, [file]);

  const type = mediaTypeForFile(file);
  return <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted text-xs text-muted-foreground">
    {url === null ? (type === 'video' ? 'Video' : type === 'photo' ? 'Photo' : 'File') : type === 'video'
      ? <video src={url} muted playsInline preload="metadata" aria-label={`Preview of ${file.name}`} className="size-full object-cover" />
      : <img src={url} alt={`Preview of ${file.name}`} className="size-full object-cover" />}
  </div>;
}

function statusLabel(item: QueuedUpload): string {
  switch (item.status) {
    case 'queued': return 'Waiting';
    case 'preparing': return `Preparing · ${Math.round(item.progress)}%`;
    case 'uploading': return `Uploading · ${Math.round(item.progress)}%`;
    case 'completed': return 'Uploaded · awaiting review';
    case 'failed': return 'Failed';
    case 'canceled': return 'Canceled';
  }
}

/** Local previews are revoked as soon as their queue row leaves the dialog. */
export function UploadQueue({ items, locked, busy, onRemove, onTitleChange }: UploadQueueProps) {
  return <ol aria-label="Selected uploads" className="grid max-h-64 gap-3 overflow-y-auto rounded-md border p-3">
    {items.map((item) => <li key={item.id} className="flex min-w-0 items-start gap-3" aria-label={item.file.name}>
      <FilePreview file={item.file} />
      <div className="grid min-w-0 flex-1 gap-1">
        <div className="flex items-center justify-between gap-2"><p className="truncate text-sm font-medium" title={item.file.name}>{item.file.name}</p>
          {item.status !== 'completed' && <Button type="button" size="sm" variant="outline" aria-label={`Remove ${item.file.name}`} disabled={busy} onClick={() => onRemove(item.id)}>Remove</Button>}
        </div>
        <p className="text-xs text-muted-foreground">{statusLabel(item)}</p>
        {['preparing', 'uploading'].includes(item.status) && <progress value={item.progress} max={100} aria-label={`Progress for ${item.file.name}`} className="h-2 w-full" />}
        {item.error && <p role="alert" className="break-words text-xs text-destructive">{item.error}</p>}
        <details open={items.length === 1}>
          <summary className="cursor-pointer text-xs text-muted-foreground">Edit title (optional)</summary>
          <Input value={item.title} aria-label={`Title for ${item.file.name}`} placeholder="Title (optional)" maxLength={255} disabled={locked}
            onChange={(event) => onTitleChange(item.id, event.target.value)} className="mt-1" />
        </details>
      </div>
    </li>)}
  </ol>;
}
