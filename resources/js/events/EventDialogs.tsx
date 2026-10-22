import { type FormEvent, useState } from 'react';

import { FavoriteButton } from '@/components/favorite-button';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type EventAttendee, eventError, type EventMediaItem, type EventProfile, type EventSettings } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import { moduleEnabled } from '@/instance';
import { SaveToLibraryButton } from '@/library/SaveToLibraryButton';
import { MediaPlayer } from '@/media/MediaPlayer';
import { safeInternalUrl } from '@/security/dom-url';

interface AttendeePickerProps {
  attendees: EventAttendee[];
  selected: number[];
  onChange: (ids: number[]) => void;
  disabled: boolean;
  label: string;
}

function AttendeePicker({ attendees, selected, onChange, disabled, label }: AttendeePickerProps) {
  return <fieldset className="grid gap-2" disabled={disabled}>
    <legend className="mb-2 text-sm font-medium">{label}</legend>
    <div className="grid max-h-48 gap-2 overflow-y-auto rounded border p-3">
      {attendees.length === 0 && <p className="text-sm text-muted-foreground">No attendees available.</p>}
      {attendees.map((attendee) => <label key={attendee.id} className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={selected.includes(attendee.id)} onChange={(event) => onChange(event.target.checked ? [...selected, attendee.id] : selected.filter((id) => id !== attendee.id))} />
        {attendee.display_name}
      </label>)}
    </div>
  </fieldset>;
}

interface EventEditorProps {
  event: EventProfile | null;
  attendees: EventAttendee[];
  onClose: () => void;
  onSaved: () => void;
}

function localDateTime(value: string | null | undefined): string {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const part = (number: number): string => String(number).padStart(2, '0');
  return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}T${part(date.getHours())}:${part(date.getMinutes())}:${part(date.getSeconds())}`;
}

export function EventEditor({ event, attendees, onClose, onSaved }: EventEditorProps) {
  const [name, setName] = useState(event?.name ?? '');
  const [description, setDescription] = useState(event?.description ?? '');
  const [timezone, setTimezone] = useState(event?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone);
  const [startsAt, setStartsAt] = useState(localDateTime(event?.starts_at));
  const [endsAt, setEndsAt] = useState(localDateTime(event?.ends_at));
  const [visibility, setVisibility] = useState(event?.visibility ?? 'everyone');
  const availableIds = new Set(attendees.map((attendee) => attendee.id));
  const unavailableMembers = event?.member_ids?.some((id) => !availableIds.has(id)) ?? false;
  const [members, setMembers] = useState(() => (event?.member_ids ?? []).filter((id) => availableIds.has(id)));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const save = async (submit: FormEvent): Promise<void> => {
    submit.preventDefault();
    setBusy(true);
    setError('');
    try {
      const body = { name: name.trim(), description: description.trim() || null, timezone: timezone.trim(), starts_at: startsAt ? new Date(startsAt).toISOString() : null, ends_at: endsAt ? new Date(endsAt).toISOString() : null, visibility, member_ids: visibility === 'specific' ? members : [] };
      if (event) await fetchWrapper.patch(`/api/admin/events/${event.ulid}`, body);
      else await fetchWrapper.post('/api/admin/events', body);
      onSaved();
      onClose();
    } catch (error) { setError(eventError(error)); }
    finally { setBusy(false); }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent className="max-h-[90vh] overflow-y-auto">
      <DialogHeader><DialogTitle>{event ? 'Edit event' : 'Create event'}</DialogTitle><DialogDescription>Organize attendee uploads with access controlled by this event.</DialogDescription></DialogHeader>
      <form className="grid gap-4" onSubmit={(submit) => void save(submit)}>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div className="grid gap-1"><Label htmlFor="event-name">Name</Label><Input id="event-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} disabled={busy} /></div>
        <div className="grid gap-1"><Label htmlFor="event-description">Description</Label><Textarea id="event-description" value={description} onChange={(event) => setDescription(event.target.value)} disabled={busy} /></div>
        <div className="grid gap-1"><Label htmlFor="event-timezone">Timezone</Label><Input id="event-timezone" placeholder="Europe/London" required value={timezone} onChange={(event) => setTimezone(event.target.value)} disabled={busy} /></div>
        <div className="grid gap-1"><Label htmlFor="event-start">Starts at (optional)</Label><Input id="event-start" type="datetime-local" step="1" value={startsAt} onChange={(event) => setStartsAt(event.target.value)} disabled={busy} /></div>
        <div className="grid gap-1"><Label htmlFor="event-end">Ends at (optional)</Label><Input id="event-end" type="datetime-local" step="1" value={endsAt} onChange={(event) => setEndsAt(event.target.value)} disabled={busy} /><p className="text-xs text-muted-foreground">Enter times in your device timezone ({Intl.DateTimeFormat().resolvedOptions().timeZone}). The timezone above controls how the event is displayed.</p></div>
        <div className="grid gap-1"><Label htmlFor="event-visibility">Who can access this event?</Label><select id="event-visibility" className="rounded border bg-background p-2" value={visibility} disabled={busy} onChange={(event) => setVisibility(event.target.value as EventProfile['visibility'])}><option value="everyone">All attendees</option><option value="specific">Selected attendees</option></select></div>
        {unavailableMembers && <p className="text-sm text-muted-foreground">Unavailable attendees will be removed when you save.</p>}
        {visibility === 'specific' && <AttendeePicker attendees={attendees} selected={members} onChange={setMembers} disabled={busy} label="Selected attendees" />}
        <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button disabled={busy}>{busy ? 'Saving…' : 'Save event'}</Button></DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}

interface CuratorEditorProps {
  settings: EventSettings;
  attendees: EventAttendee[];
  onClose: () => void;
  onSaved: () => void;
}

export function CuratorEditor({ settings, attendees, onClose, onSaved }: CuratorEditorProps) {
  const [ids, setIds] = useState(settings.featured_curators.map((curator) => curator.id));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const save = async (): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.put('/api/admin/events/settings', { featured_curator_ids: ids });
      onSaved();
      onClose();
    } catch (error) { setError(eventError(error)); }
    finally { setBusy(false); }
  };
  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent><DialogHeader><DialogTitle>Featured curators</DialogTitle><DialogDescription>Featured favorites show media favorited by these attendees, within each viewer’s event access.</DialogDescription></DialogHeader>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <AttendeePicker attendees={attendees} selected={ids} onChange={setIds} disabled={busy} label="Curators" />
      <DialogFooter><Button variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button disabled={busy} onClick={() => void save()}>{busy ? 'Saving…' : 'Save curators'}</Button></DialogFooter>
    </DialogContent>
  </Dialog>;
}

interface EventMediaDialogProps {
  item: EventMediaItem;
  onClose: () => void;
  onDeleted: () => void;
}

export function EventMediaDialog({ item, onClose, onDeleted }: EventMediaDialogProps) {
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const downloadUrl = safeInternalUrl(item.download_url);
  const remove = async (): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.delete(`/api/media/${item.id}`);
      onDeleted();
      onClose();
    } catch (error) { setError(eventError(error)); }
    finally { setBusy(false); }
  };
  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
      <DialogHeader><DialogTitle>{item.title || 'Event media'}</DialogTitle><DialogDescription>{item.uploader.display_name}{item.event ? ` · ${item.event.name}` : ''}{item.under_review ? ' · Awaiting review' : ''}</DialogDescription></DialogHeader>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <MediaPlayer item={item} className="max-h-[65vh] w-full object-contain" />
      <div className="flex flex-wrap items-center gap-2">
        {moduleEnabled('favorites') && <FavoriteButton type="media" id={item.id} initialFavorited={item.favorited} />}
        {moduleEnabled('library') && item.can_save_to_library && <SaveToLibraryButton mediaUlid={item.ulid} savedUlid={item.library_item_ulid ?? null} />}
        {downloadUrl && <a href={downloadUrl} download className="text-sm underline underline-offset-4">Download to device</a>}
        {item.owned && !confirmDelete && <Button variant="destructive" size="sm" onClick={() => setConfirmDelete(true)}>Delete my upload</Button>}
      </div>
      <p className="text-xs text-muted-foreground">Favorites are visible to attendees who can access the media. Items saved to your Library stay private and retain access; downloads are stored on your device.</p>
      {confirmDelete && <div className="grid gap-3 rounded border border-destructive p-3">
        <p className="text-sm">Delete this upload from the event? Items already saved to another account’s Library retain access.</p>
        <div className="flex gap-2"><Button variant="outline" disabled={busy} onClick={() => setConfirmDelete(false)}>Keep upload</Button><Button variant="destructive" disabled={busy} onClick={() => void remove()}>{busy ? 'Deleting…' : 'Confirm delete'}</Button></div>
      </div>}
    </DialogContent>
  </Dialog>;
}
