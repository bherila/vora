import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { InterestPicker } from '@/components/interest-picker';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { RestrictionNotice } from '@/components/restriction-notice';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { useActiveIdentityId } from '@/identity';
import { type Audience } from '@/media/types';
import { discardUploadReservation, type MediaUploadDestination, UploadAttemptError, uploadMediaFile } from '@/media/uploadMediaFile';
import { type QueuedUpload,UploadQueue } from '@/media/UploadQueue';
import { activeRestriction } from '@/restrictions';

/** A character the upload can be associated with (privacy is inherited from it). */
export interface CharacterOption {
  id: number;
  display_name: string;
  audience: Audience;
  audience_user_ids: number[];
}

export interface EventUploadOption {
  ulid: string;
  name: string;
  visibility: 'everyone' | 'specific';
}

function defaultTitleForFile(file: File): string {
  const lastDot = file.name.lastIndexOf('.');
  return lastDot > 0 ? file.name.slice(0, lastDot) : file.name;
}

interface MediaUploadDialogProps {
  /** Characters the upload can be attached to (privacy is inherited from them). */
  characters: CharacterOption[];
  /** Explicit profile identity; omit to use the navbar's active authoring identity. */
  defaultCharacterId?: number | null;
  /** Interests pre-selected from the user's last upload. */
  lastInterestIds: number[];
  /** Called after at least one upload completes, so the caller can refresh. */
  onUploaded: () => void;
  /** Label for the trigger button (defaults to "Upload media"). */
  triggerLabel?: ReactNode;
  triggerSize?: 'default' | 'sm';
  triggerVariant?: 'default' | 'outline';
  /** Defined (including null) for private Library uploads. */
  libraryCollectionUlid?: string | null;
  /** Upload to an event, with access inherited from its attendee list. */
  eventUlid?: string | undefined;
  /** Presence keeps this flow in Event context, including when the list is empty. */
  eventOptions?: EventUploadOption[] | undefined;
  eventUploadsRequireReview?: boolean;
}

/**
 * The media upload flow as a self-contained dialog. Media is uploaded *on* a
 * profile (the user's own or one of their characters), so this lives next to the
 * profile container and is reused wherever an owner can add media.
 */
export function MediaUploadDialog({
  characters,
  defaultCharacterId,
  lastInterestIds,
  onUploaded,
  triggerLabel = 'Upload media',
  triggerSize = 'default',
  triggerVariant = 'default',
  libraryCollectionUlid,
  eventUlid,
  eventOptions,
  eventUploadsRequireReview = true,
}: MediaUploadDialogProps) {
  const uploadRestriction = activeRestriction('media.upload');
  const activeIdentityId = useActiveIdentityId();
  const isEvent = eventOptions !== undefined || eventUlid !== undefined;
  const isLibrary = !isEvent && libraryCollectionUlid !== undefined;
  const resolvedDefaultCharacterId = isLibrary || isEvent ? null : defaultCharacterId === undefined ? activeIdentityId : defaultCharacterId;
  const [open, setOpen] = useState(false);
  const [selectedEventUlid, setSelectedEventUlid] = useState(eventUlid ?? '');
  const [batchStarted, setBatchStarted] = useState(false);
  const [discarding, setDiscarding] = useState(false);
  const nextIdRef = useRef(0);
  const [pending, setPending] = useState<QueuedUpload[]>([]);
  const [characterId, setCharacterId] = useState(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [announce, setAnnounce] = useState(true);
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(lastInterestIds);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('Uploading…');
  const selectedEvent = eventOptions?.find((option) => option.ulid === selectedEventUlid);
  const hasEvent = eventOptions === undefined ? selectedEventUlid !== '' : selectedEvent !== undefined;
  const locked = uploading || discarding || batchStarted;
  const completed = pending.filter((item) => item.status === 'completed').length;
  const uploadAbortRef = useRef<AbortController | null>(null);

  // Re-scope to the active identity each time the dialog opens.
  useEffect(() => {
    if (open && !batchStarted) {
      setCharacterId(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
    }
  }, [open, resolvedDefaultCharacterId, batchStarted]);

  useEffect(() => {
    if (open && !batchStarted) setSelectedEventUlid(eventUlid ?? '');
  }, [open, eventUlid, batchStarted]);

  useEffect(() => () => uploadAbortRef.current?.abort(), []);

  useEffect(() => {
    if (!uploading) return;
    const warn = (event: BeforeUnloadEvent): void => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [uploading]);

  const handleFilesChange = (newFiles: File[]): void => {
    if (locked) return;
    setPending(newFiles.map((file) => ({
      id: nextIdRef.current++, file, title: defaultTitleForFile(file), status: 'queued', progress: 0, error: null, reservation: null,
    })));
  };

  const updateItem = (id: number, update: Partial<QueuedUpload>): void => {
    setPending((items) => items.map((item) => item.id === id ? { ...item, ...update } : item));
  };

  const resetForm = (): void => {
    setPending([]);
    setBatchStarted(false);
    setCharacterId(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
    setAudience('everyone');
    setAudienceUserIds([]);
    setDiscoverable(true);
    setAnnounce(true);
    setUploadInterestIds(lastInterestIds);
    setProgress(0);
  };

  const discardBatch = async (): Promise<void> => {
    setDiscarding(true);
    try {
      for (const item of pending) {
        if (item.reservation !== null) await discardUploadReservation(item.reservation);
      }
      resetForm();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Could not clear unfinished uploads. Try again.');
    } finally {
      onUploaded();
      setDiscarding(false);
    }
  };

  const removeItem = async (id: number): Promise<void> => {
    const item = pending.find((candidate) => candidate.id === id);
    if (!item || item.status === 'completed') return;
    setDiscarding(true);
    try {
      if (item.reservation !== null) await discardUploadReservation(item.reservation);
      setPending((items) => items.filter((candidate) => candidate.id !== id));
      if (pending.length === 1) setBatchStarted(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Could not clear this unfinished upload. Try again.');
    } finally {
      onUploaded();
      setDiscarding(false);
    }
  };

  const upload = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (uploading || discarding) return;
    if (isEvent && !hasEvent) { toast.error('Choose an available event before uploading.'); return; }
    if (pending.length === 0) { toast.error('Choose at least one file to upload.'); return; }

    const destination: MediaUploadDestination = isEvent ? { kind: 'event', ulid: selectedEventUlid }
      : isLibrary ? { kind: 'library', collectionUlid: libraryCollectionUlid ?? null }
        : { kind: 'gallery', characterId: characterId === '' ? null : Number(characterId), audience, audienceUserIds, discoverable, announce, interestIds: uploadInterestIds };
    const controller = new AbortController();
    uploadAbortRef.current = controller;
    setUploading(true);
    setBatchStarted(true);
    let totalCompleted = completed;
    let lastError = '';
    let canceled = false;
    const outcomes = new Map(pending.map((item) => [item.id, item]));
    const updateAttempt = (id: number, update: Partial<QueuedUpload>): void => {
      const previous = outcomes.get(id);
      if (previous) outcomes.set(id, { ...previous, ...update });
      updateItem(id, update);
    };
    try {
      for (const [index, item] of pending.entries()) {
        if (item.status === 'completed') continue;
        if (controller.signal.aborted) { canceled = true; break; }
        updateAttempt(item.id, { status: 'preparing', progress: 0, error: null });
        try {
          await uploadMediaFile({ file: item.file, title: item.title, destination, signal: controller.signal, reservation: item.reservation,
            onProgress: (phase, percent) => {
              updateAttempt(item.id, { status: phase, progress: percent });
              setUploadLabel(`${phase === 'preparing' ? 'Preparing' : 'Uploading'} ${index + 1} of ${pending.length}: ${item.file.name}`);
              setProgress(percent);
            },
          });
          totalCompleted += 1;
          updateAttempt(item.id, { status: 'completed', progress: 100, error: null, reservation: null });
        } catch (error) {
          canceled = error instanceof UploadAttemptError ? error.canceled : error instanceof DOMException && error.name === 'AbortError';
          lastError = error instanceof Error ? error.message : 'Upload failed.';
          updateAttempt(item.id, { status: canceled ? 'canceled' : 'failed', error: canceled ? null : lastError,
            reservation: error instanceof UploadAttemptError ? error.reservation : null });
          if (canceled || controller.signal.aborted) { canceled = true; break; }
        }
      }
      if (canceled) {
        for (const item of outcomes.values()) {
          if (item.reservation === null) continue;
          try {
            await discardUploadReservation(item.reservation);
            updateAttempt(item.id, { status: 'canceled', reservation: null });
          } catch {
            updateAttempt(item.id, { error: 'Could not clear this unfinished upload. Choose different files to retry cleanup.' });
          }
        }
      }
      onUploaded();
      if (canceled) {
        toast.info(totalCompleted > 0 ? `${totalCompleted} upload${totalCompleted === 1 ? '' : 's'} completed before cancellation.` : 'Upload canceled.');
      } else if (totalCompleted === pending.length) {
        toast.success(isEvent ? 'Upload complete. Each item shows its sharing and processing status.' : pending.length === 1 ? 'Upload complete. It will be reviewed before others can see it.' : 'Uploads complete. They will be reviewed before others can see them.');
        setOpen(false);
        resetForm();
      } else {
        toast.error(pending.length === 1 ? lastError : `${totalCompleted} of ${pending.length} uploaded. Retry failed files below.`);
      }
    } finally {
      setUploading(false);
      uploadAbortRef.current = null;
    }
  };

  if (uploadRestriction) {
    return <RestrictionNotice restriction={uploadRestriction} />;
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!uploading && !discarding) setOpen(next); }}>
      <DialogTrigger asChild>
        <Button size={triggerSize} variant={triggerVariant}>{triggerLabel}</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] min-w-0 grid-cols-1 overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Upload media</DialogTitle>
        </DialogHeader>
        <form onSubmit={(event) => void upload(event)} className="grid min-w-0 grid-cols-1 gap-4">
          <FileDropzone
            accept="image/*,video/*,.heic,.heif"
            files={pending.map((item) => item.file)}
            label="Drop photos or videos here"
            multiple
            onFilesChange={handleFilesChange}
            disabled={locked}
            helperText="Choose photos and videos together. HEIC/HEIF originals are kept with JPEG previews."
          />
          {isEvent && <div className="grid gap-2 rounded-md border p-3">
            {eventOptions !== undefined ? <label className="grid gap-1 text-sm">Destination event
              <select value={hasEvent ? selectedEventUlid : ''} onChange={(event) => setSelectedEventUlid(event.target.value)} disabled={locked || eventOptions.length === 0}
                className="w-full rounded-md border border-input bg-background px-3 py-2">
                <option value="">Choose an event</option>
                {eventOptions.map((option) => <option key={option.ulid} value={option.ulid}>{option.name}</option>)}
              </select>
            </label> : <p className="text-sm font-medium">Destination: this event</p>}
            {selectedEvent ? <p className="text-sm text-muted-foreground">{selectedEvent.name}: {selectedEvent.visibility === 'specific' ? 'Only selected attendees can view uploads.' : 'All approved attendees can view uploads.'}</p>
              : eventOptions?.length === 0 ? <p className="text-sm text-muted-foreground">No events are available for uploads. Ask an administrator to add an event or grant access.</p>
                : !hasEvent && <p className="text-sm text-muted-foreground">{selectedEventUlid ? 'The selected event is no longer available. Choose another event.' : 'Choose where these photos and videos belong.'}</p>}
          </div>}
          {pending.length > 0 && <>
            <p role="status" className="text-sm">{completed} of {pending.length} uploaded</p>
            <UploadQueue items={pending} locked={locked} busy={uploading || discarding} onRemove={(id) => void removeItem(id)} onTitleChange={(id, title) => updateItem(id, { title })} />
          </>}
          {!isEvent && characters.length > 0 && (
            <label className="grid gap-1">
              <span className="text-sm">Character</span>
              <select
                value={characterId}
                onChange={(event) => setCharacterId(event.target.value)}
                disabled={locked}
                className="w-full rounded-md border border-input bg-background px-3 py-2"
              >
                <option value="">No character</option>
                {characters.map((character) => (
                  <option key={character.id} value={character.id}>{character.display_name}</option>
                ))}
              </select>
            </label>
          )}
          {isEvent ? (
            <p className="text-sm text-muted-foreground">{eventUploadsRequireReview ? 'Uploads are shared with people who can access this event after review.' : 'Your account can publish completed Event uploads without review. Each item shows its current status.'} Event access controls who can view them.</p>
          ) : isLibrary ? (
            <p className="text-sm text-muted-foreground">
              Uploads stay in your private Library. If you selected a shared collection, its recipients can view and save new uploads after review.
            </p>
          ) : characterId === '' ? (
            <>
              <AudienceField
                audience={audience}
                onAudienceChange={setAudience}
                selectedUserIds={audienceUserIds}
                onSelectedUserIdsChange={setAudienceUserIds}
                disabled={locked}
                specificRelationship="mutuals"
              />
              <label className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={discoverable}
                  onChange={(event) => setDiscoverable(event.target.checked)}
                  disabled={locked}
                  className="mt-0.5"
                />
                <span>List in discovery — otherwise only people with the link can find it.</span>
              </label>
            </>
          ) : (
            <p className="text-sm text-muted-foreground">This upload uses the selected character's privacy setting.</p>
          )}
          {!isLibrary && !isEvent && <><div className="grid gap-1">
            <span className="text-sm">Interests</span>
            <InterestPicker value={uploadInterestIds} onChange={setUploadInterestIds} disabled={locked} />
          </div>
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={announce}
              onChange={(event) => setAnnounce(event.target.checked)}
              disabled={locked}
              className="mt-0.5"
            />
            <span>Share this to your followers&apos; feeds. Unchecking keeps the upload private to your profile.</span>
          </label></>}
          <p className="text-xs text-muted-foreground">Keep this page open and your device awake until uploads finish. If interrupted, choose the same files and event again: videos may resume uploaded parts. Photos and some videos restart. Uploaded files are kept when you retry.</p>
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={uploading || discarding || (isEvent && !hasEvent) || pending.length === 0}>
              {uploading ? 'Uploading…' : batchStarted ? (completed === pending.length ? 'Done' : pending.some((item) => item.status === 'queued' || item.status === 'canceled') ? 'Continue unfinished' : 'Retry failed') : 'Upload'}
            </Button>
            {batchStarted && !uploading && <Button type="button" variant="outline" disabled={discarding} onClick={() => void discardBatch()}>{discarding ? 'Clearing…' : 'Choose different files'}</Button>}
          </div>
          {uploading && (
            <UploadProgress
              label={uploadLabel}
              progress={progress}
              onCancel={() => uploadAbortRef.current?.abort()}
            />
          )}
        </form>
      </DialogContent>
    </Dialog>
  );
}
