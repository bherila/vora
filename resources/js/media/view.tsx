import { ArrowLeft, ListOrdered } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { CommentThread } from '@/community/CommentThread';
import { StartDiscussion } from '@/community/StartDiscussion';
import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
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
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { SaveToLibraryButton } from '@/library/SaveToLibraryButton';
import { ChapterBar } from '@/media/ChapterBar';
import { ChapterEditor } from '@/media/ChapterEditor';
import { ChapterList } from '@/media/ChapterList';
import { chapterListProblems } from '@/media/chapters';
import { MediaPlayer } from '@/media/MediaPlayer';
import { MomentCapture } from '@/media/MomentCapture';
import { listMoments } from '@/media/moments';
import { MomentsStrip } from '@/media/MomentsStrip';
import { formatBytes, type MediaItem, type VideoChapter, type VideoMoment } from '@/media/types';
import type { VideoPlayback } from '@/media/videoPlayback';
import { formatClock } from '@/media/videoTime';
import { VideoToolbar } from '@/media/VideoToolbar';
import { safeInternalUrl } from '@/security/dom-url';

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

function getInitialMedia(): MediaItem | null {
  return readInitialData<{ mediaView?: MediaItem }>().mediaView ?? null;
}

export function MediaViewPage() {
  const [item, setItem] = useState<MediaItem | null>(getInitialMedia);
  const [deleted, setDeleted] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editTitle, setEditTitle] = useState('');
  const [editCharacterId, setEditCharacterId] = useState('');
  const [editAudience, setEditAudience] = useState(item?.editable?.audience ?? 'everyone');
  const [editAudienceUserIds, setEditAudienceUserIds] = useState<number[]>([]);
  const [editDiscoverable, setEditDiscoverable] = useState(true);
  const [editBusy, setEditBusy] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [playback, setPlayback] = useState<VideoPlayback | null>(null);
  const [chapters, setChapters] = useState<VideoChapter[]>(() => item?.chapters ?? []);
  const [editingChapters, setEditingChapters] = useState(false);
  // Bumped after each save so the player re-fetches its WebVTT chapters track.
  const [chaptersVersion, setChaptersVersion] = useState(0);
  const [moments, setMoments] = useState<VideoMoment[]>([]);
  const [capturing, setCapturing] = useState(false);
  const [localPreviews, setLocalPreviews] = useState<ReadonlyMap<string, string>>(() => new Map());
  const previewUrls = useRef(new Set<string>());
  const pendingPolls = useRef(0);

  const momentsUlid = item?.type === 'video' ? item.ulid : null;
  useEffect(() => {
    if (momentsUlid === null) {
      return undefined;
    }
    let cancelled = false;
    listMoments(momentsUlid).then((loaded) => {
      if (!cancelled) {
        setMoments(loaded);
      }
    }).catch(() => {
      // Moments are an addition to the page; it works without them.
    });
    return () => {
      cancelled = true;
    };
  }, [momentsUlid]);

  // While any visible frame is being prepared, check back every 10 s for up
  // to 5 minutes. Other viewers need this most: until the server's frame
  // exists they see only a placeholder.
  const waitingForFrames = moments.some((moment) => moment.frame_status === 'pending');
  useEffect(() => {
    if (momentsUlid === null || !waitingForFrames || pendingPolls.current >= 30) {
      return undefined;
    }
    const timer = window.setTimeout(() => {
      pendingPolls.current += 1;
      listMoments(momentsUlid).then(setMoments).catch(() => undefined);
    }, 10_000);
    return () => window.clearTimeout(timer);
  }, [momentsUlid, waitingForFrames, moments]);

  useEffect(() => () => {
    previewUrls.current.forEach((url) => URL.revokeObjectURL(url));
  }, []);

  if (deleted) {
    return (
      <div className="mx-auto max-w-3xl space-y-3 px-4 py-8">
        <h1 className="text-xl font-semibold">Media deleted.</h1>
        <p className="text-muted-foreground">The item is hidden from your profile and retained for admin recovery.</p>
        <a href="/me" className="inline-flex text-sm underline underline-offset-4">Return to your profile</a>
        <Toaster position="top-right" richColors closeButton />
      </div>
    );
  }

  if (!item) {
    return <div className="mx-auto max-w-3xl px-4 py-8"><p className="text-muted-foreground">This media is unavailable.</p></div>;
  }

  const isVideo = item.type === 'video';

  const replaceMoment = (moment: VideoMoment): void => {
    setMoments((current) => current.map((candidate) => (candidate.ulid === moment.ulid ? moment : candidate)));
  };

  const makeChapterFromMoment = async (moment: VideoMoment): Promise<void> => {
    const start = moment.time_ms + 1;
    if (chapters.some((chapter) => Math.abs(chapter.start_ms - start) < 1000)) {
      toast.error('A chapter already starts there.');
      return;
    }
    const next = [
      ...(chapters.length === 0 && start > 0 ? [{ start_ms: 0, title: 'Beginning' }] : []),
      ...chapters,
      { start_ms: start, title: moment.caption ?? `Moment at ${formatClock(start / 1000)}` },
    ].sort((a, b) => a.start_ms - b.start_ms);
    const problems = chapterListProblems(next, item.video_duration_ms ?? null);
    if (problems.length > 0) {
      toast.error(problems[0] ?? 'Could not add the chapter.');
      return;
    }
    try {
      const response = await fetchWrapper.put(`/api/media/by-ulid/${encodeURIComponent(item.ulid)}/chapters`, { chapters: next }) as { data: VideoChapter[] };
      setChapters(response.data);
      setChaptersVersion((version) => version + 1);
      toast.success('Chapter added.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    }
  };
  const showChapterRail = isVideo && chapters.length > 0;
  const chaptersTrackSrc = isVideo && chapters.length > 0 && item.chapters_vtt_url !== undefined
    ? `${item.chapters_vtt_url}?v=${chaptersVersion}`
    : null;
  const owner = item.owner ?? null;
  const ownerHref = safeInternalUrl(owner?.href);
  const editable = item.editable;

  const openEditor = (): void => {
    if (!editable) {
      return;
    }
    setEditTitle(editable.title ?? '');
    setEditCharacterId(editable.character_id === null ? '' : String(editable.character_id));
    setEditAudience(editable.audience);
    setEditAudienceUserIds(editable.audience_user_ids);
    setEditDiscoverable(editable.discoverable);
    setEditOpen(true);
  };

  const saveEdit = async (): Promise<void> => {
    if (!editable) {
      return;
    }

    const payload: Record<string, unknown> = {
      title: editTitle.trim() === '' ? null : editTitle.trim(),
    };
    if (editCharacterId !== '') {
      payload.character_id = Number(editCharacterId);
    } else if (editable.character_id !== null) {
      payload.character_id = null;
    } else {
      payload.audience = editAudience;
      payload.audience_user_ids = editAudience === 'specific' ? editAudienceUserIds : [];
      payload.discoverable = editDiscoverable;
    }

    setEditBusy(true);
    try {
      const response = await fetchWrapper.patch(`/api/media/${item.id}`, payload) as {
        data: MediaItem;
      };
      const updated = response.data;
      setItem({
        ...item,
        ...updated,
        editable: {
          ...editable,
          title: updated.title,
          character_id: updated.character_id,
          audience: updated.audience,
          audience_user_ids: updated.character_id === null && updated.audience === 'specific'
            ? editAudienceUserIds
            : editable.audience_user_ids,
          discoverable: updated.discoverable,
        },
      });
      setEditOpen(false);
      toast.success('Media updated.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setEditBusy(false);
    }
  };

  const deleteItem = async (): Promise<void> => {
    setDeleteBusy(true);
    try {
      await fetchWrapper.delete(`/api/media/${item.id}`);
      setDeleted(true);
      toast.success('Media deleted.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setDeleteBusy(false);
    }
  };

  return (
    // A wide container plus a viewport-sized stage so the media uses the space
    // available on large screens and portrait phones alike. The stage is a fixed
    // fraction of the viewport height; object-contain scales the photo/video up
    // or down to fill it while preserving the original aspect ratio.
    <div className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-6">
      <a href="/explore" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Back to Explore
      </a>

      {/* Frame the item inside the uploader's profile: their identity heads the
          page and links through to the full profile. */}
      {owner && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
          {ownerHref ? (
            <a href={ownerHref} className="flex min-w-0 items-center gap-3">
              <Avatar name={owner.display_name} src={owner.avatar_url} sizeClassName="h-10 w-10" />
              <span className="min-w-0">
                <span className="block text-xs text-muted-foreground">{owner.is_self ? 'Your media' : 'Uploaded by'}</span>
                <span className="block truncate font-medium">{owner.display_name}</span>
              </span>
            </a>
          ) : (
            <div className="flex min-w-0 items-center gap-3">
              <Avatar name={owner.display_name} src={owner.avatar_url} sizeClassName="h-10 w-10" />
              <span className="min-w-0">
                <span className="block text-xs text-muted-foreground">{owner.is_self ? 'Your media' : 'Uploaded by'}</span>
                <span className="block truncate font-medium">{owner.display_name}</span>
              </span>
            </div>
          )}
          {ownerHref && (
            <a href={ownerHref} className="text-sm underline underline-offset-4">
              {owner.is_self ? 'Go to your profile' : 'View profile'}
            </a>
          )}
        </div>
      )}

      {owner?.is_self && item.under_review && (
        <p className="rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-200">
          Only you can see this — it’s awaiting review before others can.
        </p>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="truncate text-xl font-semibold">{item.title || item.original_filename || 'Untitled media'}</h1>
        <div className="flex items-center gap-3">
          {editable && (
            <>
              <Button type="button" size="sm" variant="outline" onClick={openEditor}>Edit</Button>
              <Button type="button" size="sm" variant="destructive" onClick={() => setDeleteOpen(true)}>Delete</Button>
            </>
          )}
          {typeof item.favorite_count === 'number' && item.favorite_count > 0 && (
            <span className="text-sm text-muted-foreground">{item.favorite_count} {item.favorite_count === 1 ? 'favorite' : 'favorites'}</span>
          )}
          {item.favorited !== undefined && <FavoriteButton type="media" id={item.id} initialFavorited={item.favorited} />}
          {(item.can_save_to_library || item.library_item_ulid) && <SaveToLibraryButton mediaUlid={item.ulid} savedUlid={item.library_item_ulid ?? null} />}
          {item.can_report && <ReportButton type="media" id={item.id} />}
        </div>
      </div>
      {/* With chapters, a rail beside the stage on wide screens (below it on
          phones) lists them; the stage keeps its viewport-sized height. */}
      <div className={showChapterRail ? 'grid gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]' : undefined}>
        <div className="flex h-[78svh] items-center justify-center overflow-hidden rounded-md bg-muted">
          <MediaPlayer
            item={item}
            className="mx-auto h-full w-full object-contain"
            onPlayback={isVideo ? setPlayback : undefined}
            chaptersTrackSrc={chaptersTrackSrc}
          />
        </div>
        {showChapterRail && playback !== null && (
          <aside className="lg:h-[78svh] lg:overflow-y-auto">
            <h2 className="mb-2 px-2 text-sm font-semibold">Chapters</h2>
            <ChapterList chapters={chapters} playback={playback} />
          </aside>
        )}
      </div>
      {isVideo && playback !== null && (
        <>
          <ChapterBar chapters={chapters} playback={playback} durationMs={item.video_duration_ms ?? null} />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <VideoToolbar playback={playback} mediaUlid={item.ulid} chapters={chapters} onSaveMoment={() => setCapturing(true)} />
            {item.can_edit_chapters && !editingChapters && (
              <Button type="button" size="sm" variant="outline" onClick={() => setEditingChapters(true)}>
                <ListOrdered aria-hidden="true" /> {chapters.length > 0 ? 'Edit chapters' : 'Add chapters'}
              </Button>
            )}
          </div>
          {capturing && (
            <MomentCapture
              mediaUlid={item.ulid}
              playback={playback}
              onClose={() => setCapturing(false)}
              onSaved={(moment, preview) => {
                setMoments((current) => [...current, moment].sort((a, b) => a.time_ms - b.time_ms));
                if (preview !== null) {
                  previewUrls.current.add(preview);
                  setLocalPreviews((current) => new Map(current).set(moment.ulid, preview));
                }
                pendingPolls.current = 0;
                setCapturing(false);
                toast.success('Moment saved.');
              }}
            />
          )}
          <MomentsStrip
            mediaUlid={item.ulid}
            playback={playback}
            moments={moments}
            localPreviews={localPreviews}
            onChange={replaceMoment}
            onRemove={(ulid) => setMoments((current) => current.filter((moment) => moment.ulid !== ulid))}
            onMakeChapter={item.can_edit_chapters ? (moment) => void makeChapterFromMoment(moment) : undefined}
          />
          {item.can_edit_chapters && editingChapters && (
            <ChapterEditor
              mediaUlid={item.ulid}
              chapters={chapters}
              playback={playback}
              durationMs={item.video_duration_ms ?? null}
              onCancel={() => setEditingChapters(false)}
              onSaved={(saved) => {
                setChapters(saved);
                setChaptersVersion((version) => version + 1);
                setEditingChapters(false);
                toast.success('Chapters saved.');
              }}
            />
          )}
        </>
      )}
      <p className="text-xs text-muted-foreground">
        {item.type} · {formatBytes(item.size_bytes)}
      </p>
      {item.interests.length > 0 && (
        <p className="text-sm text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
      )}
      {item.canonical_post && (
        <section className="mx-auto w-full max-w-3xl">
          <h2 className="mb-3 text-lg font-semibold">Discussion</h2>
          <CommentThread postId={item.canonical_post.id} initialCount={item.canonical_post.comment_count} />
        </section>
      )}
      {!item.canonical_post && (
        <StartDiscussion
          endpoint={`/api/media/by-ulid/${encodeURIComponent(item.ulid)}/discussion`}
          onStarted={(post) => setItem((current) => current === null ? current : { ...current, canonical_post: post })}
        />
      )}

      {editable && (
        <Dialog open={editOpen} onOpenChange={(open) => { if (!editBusy) setEditOpen(open); }}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Edit media</DialogTitle>
              <DialogDescription>Fix the title, change its privacy, or attach a character.</DialogDescription>
            </DialogHeader>
            <div className="space-y-4">
              <div className="space-y-1">
                <Label htmlFor="edit-media-title">Title</Label>
                <Input
                  id="edit-media-title"
                  value={editTitle}
                  onChange={(event) => setEditTitle(event.target.value)}
                  disabled={editBusy}
                />
              </div>
              {editable.characters.length > 0 && (
                <label className="grid gap-1 text-sm">
                  <span>Character</span>
                  <select
                    value={editCharacterId}
                    onChange={(event) => setEditCharacterId(event.target.value)}
                    disabled={editBusy}
                    className="w-full rounded-md border border-input bg-background px-3 py-2"
                  >
                    <option value="">No character</option>
                    {editable.characters.map((character) => (
                      <option key={character.id} value={character.id}>{character.display_name}</option>
                    ))}
                  </select>
                </label>
              )}
              {editCharacterId !== '' ? (
                <p className="text-sm text-muted-foreground">Privacy follows the selected character.</p>
              ) : editable.character_id !== null ? (
                <p className="text-sm text-muted-foreground">Detaching the character. Save, then reopen to set this item’s own privacy.</p>
              ) : (
                <>
                  <AudienceField
                    audience={editAudience}
                    onAudienceChange={setEditAudience}
                    selectedUserIds={editAudienceUserIds}
                    onSelectedUserIdsChange={setEditAudienceUserIds}
                    disabled={editBusy}
                    specificRelationship="mutuals"
                  />
                  <label className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={editDiscoverable}
                      onChange={(event) => setEditDiscoverable(event.target.checked)}
                      disabled={editBusy}
                      className="mt-0.5"
                    />
                    <span>List in discovery — otherwise only people with the link can find it.</span>
                  </label>
                </>
              )}
            </div>
            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => setEditOpen(false)} disabled={editBusy}>Cancel</Button>
              <Button type="button" onClick={() => void saveEdit()} disabled={editBusy}>
                {editBusy ? 'Saving…' : 'Save changes'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      <AlertDialog open={deleteOpen} onOpenChange={(open) => { if (!deleteBusy) setDeleteOpen(open); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this item?</AlertDialogTitle>
            <AlertDialogDescription>
              It will be hidden from your profile and retained for admin recovery.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteBusy}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-white hover:bg-destructive/90"
              disabled={deleteBusy}
              onClick={() => void deleteItem()}
            >
              {deleteBusy ? 'Deleting…' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('media-view');
if (mountEl) {
  createRoot(mountEl).render(<MediaViewPage />);
}
