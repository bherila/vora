import { Flag } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

export type ReportableType = 'media' | 'story' | 'post' | 'character' | 'user';

/**
 * Reasons mirror the App\Enums\ReportReason cases (keep in sync). Ordered by
 * severity to match the admin queue.
 */
const REPORT_REASONS: Array<{ value: string; label: string }> = [
  { value: 'child_safety', label: 'Child sexual abuse or endangerment' },
  { value: 'nonconsensual_intimate', label: 'Non-consensual intimate imagery' },
  { value: 'violence', label: 'Violence or threats' },
  { value: 'harassment', label: 'Harassment or bullying' },
  { value: 'hate', label: 'Hate speech' },
  { value: 'self_harm', label: 'Self-harm or suicide' },
  { value: 'spam', label: 'Spam or scam' },
  { value: 'impersonation', label: 'Impersonation' },
  { value: 'intellectual_property', label: 'Intellectual-property violation' },
  { value: 'other', label: 'Something else' },
];

interface ReportButtonProps {
  type: ReportableType;
  id: number;
  /** Override the trigger label (defaults to "Report"). */
  label?: string;
  size?: 'default' | 'sm';
  variant?: 'outline' | 'ghost';
  open?: boolean;
  showTrigger?: boolean;
  onOpenChange?: (open: boolean) => void;
  onSubmitted?: () => void;
  onCancelled?: () => void;
  onFailed?: () => void;
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Could not submit the report.';
}

/**
 * A Report control that opens a dialog to pick a reason (and add detail) and
 * files an abuse report against the given media item, story, or post. Reusable
 * across every surface that shows reportable content.
 */
export function ReportButton({
  type,
  id,
  label = 'Report',
  size = 'sm',
  variant = 'outline',
  open: controlledOpen,
  showTrigger = true,
  onOpenChange,
  onSubmitted,
  onCancelled,
  onFailed,
}: ReportButtonProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState(false);
  const open = controlledOpen ?? internalOpen;

  const setOpen = (next: boolean): void => {
    if (controlledOpen === undefined) setInternalOpen(next);
    onOpenChange?.(next);
  };

  const cancel = (): void => {
    setOpen(false);
    onCancelled?.();
  };

  const submit = async (): Promise<void> => {
    if (reason === '') {
      toast.error('Choose a reason for the report.');
      return;
    }
    setBusy(true);
    try {
      const response = (await fetchWrapper.post('/api/reports', {
        type,
        id,
        reason,
        details: details.trim() === '' ? null : details.trim(),
      })) as { message?: string };
      toast.success(response.message ?? 'Thanks — our team will review this report.');
      setOpen(false);
      setReason('');
      setDetails('');
      onSubmitted?.();
    } catch (err) {
      toast.error(getErrorMessage(err));
      if (onFailed) {
        setOpen(false);
        onFailed();
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (busy) return;
        if (next) setOpen(true);
        else cancel();
      }}
    >
      {showTrigger && (
        <DialogTrigger asChild>
          <Button type="button" size={size} variant={variant}>
            <Flag className="h-4 w-4" aria-hidden="true" /> {label}
          </Button>
        </DialogTrigger>
      )}
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Report this content</DialogTitle>
          <DialogDescription>Tell us what’s wrong. Reports are reviewed by our team and are not shared with the uploader.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <label className="grid gap-1 text-sm">
            <span>Reason</span>
            <select
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={busy}
              className="w-full rounded-md border border-input bg-background px-3 py-2"
            >
              <option value="">Choose a reason…</option>
              {REPORT_REASONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <div className="grid gap-1">
            <Label htmlFor="report-details">Details (optional)</Label>
            <Textarea
              id="report-details"
              value={details}
              onChange={(event) => setDetails(event.target.value)}
              disabled={busy}
              maxLength={2000}
              placeholder="Add anything that will help us review this."
            />
          </div>
        </div>
        <DialogFooter>
          <Button type="button" variant="ghost" onClick={cancel} disabled={busy}>Cancel</Button>
          <Button type="button" onClick={() => void submit()} disabled={busy}>{busy ? 'Submitting…' : 'Submit report'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
