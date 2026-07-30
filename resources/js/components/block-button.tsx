import { Ban } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { ReportButton } from '@/components/report-button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

type BlockableIdentityType = 'user' | 'character';

interface BlockButtonProps {
  type: BlockableIdentityType;
  id: number;
  displayName: string;
  initialBlockId?: number | null;
  onChanged?: (blockId: number | null) => void;
}

interface BlockResponse {
  data: {
    block_id: number;
  };
}

function errorMessage(error: unknown): string {
  return typeof error === 'string' ? error : 'Could not update this block.';
}

export function BlockButton({
  type,
  id,
  displayName,
  initialBlockId = null,
  onChanged,
}: BlockButtonProps) {
  const [blockId, setBlockId] = useState<number | null>(initialBlockId);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [reportSelected, setReportSelected] = useState(false);
  const [busy, setBusy] = useState(false);

  const finishChange = (nextBlockId: number | null): void => {
    setBlockId(nextBlockId);
    onChanged?.(nextBlockId);
  };

  const block = async (): Promise<void> => {
    setBusy(true);
    try {
      const response = await fetchWrapper.post(
        type === 'user' ? `/api/users/${id}/block` : `/api/characters/${id}/block`,
        {},
      ) as BlockResponse;
      finishChange(response.data.block_id);
      toast.success(`${displayName} blocked.`);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setReportSelected(false);
      setBusy(false);
    }
  };

  const unblock = async (): Promise<void> => {
    if (blockId === null) return;

    setBusy(true);
    try {
      await fetchWrapper.delete(`/api/blocks/${blockId}`);
      finishChange(null);
      toast.success(`${displayName} unblocked.`);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const confirm = (): void => {
    setConfirmOpen(false);
    if (blockId !== null) {
      void unblock();
      return;
    }
    if (reportSelected) {
      setReportOpen(true);
      return;
    }
    void block();
  };

  const finishReportFlow = (): void => {
    setReportOpen(false);
    void block();
  };

  const blocking = blockId === null;

  return (
    <>
      <AlertDialog
        open={confirmOpen}
        onOpenChange={(open) => {
          if (busy) return;
          setConfirmOpen(open);
          if (!open) setReportSelected(false);
        }}
      >
        <AlertDialogTrigger asChild>
          <Button
            type="button"
            variant={blocking ? 'destructive' : 'outline'}
            size="sm"
            aria-label={`${blocking ? 'Open block' : 'Open unblock'} confirmation for ${displayName}`}
          >
            <Ban className="h-4 w-4" aria-hidden="true" />
            {blocking ? 'Block' : 'Unblock'}
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{blocking ? `Block ${displayName}` : `Unblock ${displayName}`}</AlertDialogTitle>
            <AlertDialogDescription>
              {blocking
                ? `${displayName} won't be able to see your profile or posts, or interact with you. You won't see ${displayName} in your feed or search. ${displayName} isn't notified, but will be able to tell. Existing follow relationships will be removed.`
                : `Unblocking ${displayName} restores visibility. Follow relationships removed when you blocked ${displayName} will not be restored.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          {blocking && (
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                checked={reportSelected}
                onChange={(event) => setReportSelected(event.target.checked)}
              />
              <span>Also report {displayName} to the moderation team</span>
            </label>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className={buttonVariants({ variant: 'destructive' })}
              aria-label={blocking ? `Block ${displayName}` : `Unblock ${displayName}`}
              onClick={confirm}
            >
              {busy ? 'Saving…' : blocking ? 'Block' : 'Unblock'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <ReportButton
        type={type}
        id={id}
        open={reportOpen}
        showTrigger={false}
        onOpenChange={setReportOpen}
        onSubmitted={finishReportFlow}
        onCancelled={finishReportFlow}
        onFailed={finishReportFlow}
      />
    </>
  );
}
