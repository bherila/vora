import { Button } from '@/components/ui/button';

interface UploadProgressProps {
  label?: string;
  progress: number;
  onCancel?: () => void;
  cancelDisabled?: boolean;
}

export function UploadProgress({ label = 'Uploading…', progress, onCancel, cancelDisabled = false }: UploadProgressProps) {
  const percent = Math.max(0, Math.min(100, Math.round(progress)));

  return (
    <div className="grid gap-2" role="status" aria-live="polite">
      <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
        <span>{label}</span>
        <span>{percent}%</span>
      </div>
      <progress className="h-2 w-full overflow-hidden rounded-full" max={100} value={percent} aria-label={label} />
      {onCancel && (
        <div>
          <Button type="button" variant="outline" size="sm" onClick={onCancel} disabled={cancelDisabled}>
            Cancel upload
          </Button>
        </div>
      )}
    </div>
  );
}
