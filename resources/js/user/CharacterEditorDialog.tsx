import { useEffect, useState } from 'react';

import { AudienceField } from '@/community/AudienceField';
import { Avatar } from '@/components/avatar';
import { ProfileOptionButtonGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { CharacterInterestsEditor } from '@/interests/character-interests-editor';
import { type Audience } from '@/lib/audience';
import type { MediaItem } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';
import { GENDER_OPTIONS, normalizeProfileOptionValue, USER_TYPE_OPTIONS } from '@/profile-options';

export interface CharacterRecord {
  id: number;
  ulid?: string;
  display_name: string;
  description: string | null;
  is_linked: boolean;
  audience: Audience;
  audience_user_ids: number[];
  discoverable: boolean;
  gender: string | null;
  gender_other: string | null;
  user_type: string | null;
  user_type_other: string | null;
  inherit_interests: boolean;
  profile_picture: MediaItem | null;
}

interface CharacterFormState {
  display_name: string;
  description: string;
  is_linked: boolean;
  audience: Audience;
  audience_user_ids: number[];
  discoverable: boolean;
  gender: string;
  gender_other: string;
  user_type: string;
  user_type_other: string;
}

interface CharacterResponse {
  success: boolean;
  data: CharacterRecord;
}

interface ProfilePictureUploadResponse {
  success: boolean;
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
}

function blankForm(): CharacterFormState {
  return {
    display_name: '',
    description: '',
    is_linked: true,
    audience: 'everyone',
    audience_user_ids: [],
    discoverable: true,
    gender: '',
    gender_other: '',
    user_type: '',
    user_type_other: '',
  };
}

function formFromCharacter(character: CharacterRecord): CharacterFormState {
  return {
    display_name: character.display_name,
    description: character.description ?? '',
    is_linked: character.is_linked,
    audience: character.audience,
    audience_user_ids: character.audience_user_ids,
    discoverable: character.discoverable,
    gender: normalizeProfileOptionValue(GENDER_OPTIONS, character.gender),
    gender_other: character.gender_other ?? '',
    user_type: normalizeProfileOptionValue(USER_TYPE_OPTIONS, character.user_type),
    user_type_other: character.user_type_other ?? '',
  };
}

function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

interface LinkedSeparateFieldProps {
  personaName: string;
  /** true = Linked, false = Separate. */
  value: boolean;
  onChange: (isLinked: boolean) => void;
  disabled?: boolean;
}

/**
 * The Linked / Separate choice: one control, both consequences stated together
 * (H2 in the design doc's copy deck).
 *
 * The copy interpolates the persona's name and must stay pronoun-free: a
 * persona has its own gender, and the name here is arbitrary (it falls back to
 * "this persona" before one is typed). Asserting a pronoun misgenders every
 * persona that does not use it — see #132.
 */
function LinkedSeparateField({ personaName, value, onChange, disabled }: LinkedSeparateFieldProps) {
  const options = [
    {
      linked: true,
      title: 'Linked',
      copy: `People visiting ${personaName} can see this persona is yours, and anyone who follows you will also see ${personaName}'s followers-only posts.`,
    },
    {
      linked: false,
      title: 'Separate',
      copy: `Nobody can tell ${personaName} is yours. ${personaName} builds a following from scratch.`,
    },
  ];

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">Connection to your profile</legend>
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((option) => (
          <label
            key={option.title}
            className={`flex cursor-pointer items-start gap-2 rounded-lg border p-3 ${value === option.linked ? 'border-foreground bg-muted/40' : 'border-border hover:bg-muted/30'}`}
          >
            <input
              type="radio"
              name="character-is-linked"
              className="mt-1"
              checked={value === option.linked}
              onChange={() => onChange(option.linked)}
              disabled={disabled}
            />
            <span className="min-w-0 text-sm">
              <span className="block font-medium">{option.title}</span>
              <span className="block text-muted-foreground">{option.copy}</span>
            </span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

interface CharacterEditorDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The character to edit, or null to create a new one. */
  editing: CharacterRecord | null;
  /** Called after every successful create/update (incl. picture changes). */
  onSaved: (character: CharacterRecord) => void;
}

/**
 * Create or edit a character. Reused on the profile (the identity strip's Add /
 * Edit controls). Profile picture and custom interests need a saved character, so
 * they appear once it exists.
 */
export function CharacterEditorDialog({ open, onOpenChange, editing, onSaved }: CharacterEditorDialogProps) {
  // Working copy of the character being edited; updated locally when a picture
  // upload completes so the avatar refreshes without reopening.
  const [current, setCurrent] = useState<CharacterRecord | null>(editing);
  const [form, setForm] = useState<CharacterFormState>(() => (editing ? formFromCharacter(editing) : blankForm()));
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [uploading, setUploading] = useState(false);

  // Reset the form to the target whenever the dialog opens.
  useEffect(() => {
    if (open) {
      setCurrent(editing);
      setForm(editing ? formFromCharacter(editing) : blankForm());
      setDirty(false);
      setError('');
    }
  }, [open, editing]);

  const updateForm = (patch: Partial<CharacterFormState>): void => {
    setForm((value) => ({ ...value, ...patch }));
    setDirty(true);
  };

  const handleOpenChange = (next: boolean): void => {
    if (!next && dirty && !window.confirm('Discard your unsaved changes to this character?')) {
      return;
    }
    onOpenChange(next);
  };

  const validate = (): boolean => {
    if (!form.display_name.trim()) {
      setError('Character display name is required.');
      return false;
    }
    if (form.gender === 'other' && !form.gender_other.trim()) {
      setError('Please specify the character gender when choosing Other.');
      return false;
    }
    if (form.user_type === 'other' && !form.user_type_other.trim()) {
      setError('Please specify the character type when choosing Other.');
      return false;
    }
    return true;
  };

  const save = async (addAnother: boolean): Promise<void> => {
    if (!validate()) return;
    setSaving(true);
    setError('');

    const payload = {
      display_name: form.display_name.trim(),
      description: blankToNull(form.description),
      is_linked: form.is_linked,
      audience: form.audience,
      audience_user_ids: form.audience === 'specific' ? form.audience_user_ids : [],
      discoverable: form.discoverable,
      gender: blankToNull(form.gender),
      gender_other: form.gender === 'other' ? blankToNull(form.gender_other) : null,
      user_type: blankToNull(form.user_type),
      user_type_other: form.user_type === 'other' ? blankToNull(form.user_type_other) : null,
    };

    try {
      const response = current === null
        ? await fetchWrapper.post('/api/characters', payload) as CharacterResponse
        : await fetchWrapper.patch(`/api/characters/${current.id}`, payload) as CharacterResponse;
      onSaved(response.data);
      setDirty(false);
      if (addAnother) {
        setCurrent(null);
        setForm(blankForm());
      } else {
        onOpenChange(false);
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to save character.');
    } finally {
      setSaving(false);
    }
  };

  const handleProfilePicture = async (file: File | null): Promise<void> => {
    if (!file || current === null) return;
    if (!file.type.startsWith('image/')) {
      setError('Character profile pictures must be images.');
      return;
    }

    setUploading(true);
    setError('');
    try {
      const created = await fetchWrapper.post(`/api/characters/${current.id}/profile-picture`, {
        filename: file.name,
        content_type: file.type,
        size: file.size,
      }) as ProfilePictureUploadResponse;
      await putToSignedUrl(created.upload_url, file, created.upload_headers, () => {});
      const completed = await fetchWrapper.post(`/api/characters/${current.id}/profile-picture/${created.data.id}/complete`, {}) as CharacterResponse;
      setCurrent(completed.data);
      onSaved(completed.data);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to upload character profile picture.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{current === null ? 'New persona' : `Edit ${current.display_name}`}</DialogTitle>
          <DialogDescription>A persona is a character of yours — it does not change your real user account profile.</DialogDescription>
        </DialogHeader>

        {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}

        <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); void save(false); }}>
          <div className="space-y-1">
            <Label htmlFor="character-display-name">Display name</Label>
            <Input id="character-display-name" value={form.display_name} onChange={(event) => updateForm({ display_name: event.target.value })} required />
          </div>
          <div className="space-y-1">
            <Label htmlFor="character-description">Description</Label>
            <Textarea id="character-description" value={form.description} onChange={(event) => updateForm({ description: event.target.value })} />
          </div>
          <AudienceField
            audience={form.audience}
            onAudienceChange={(audience) => updateForm({ audience })}
            selectedUserIds={form.audience_user_ids}
            onSelectedUserIdsChange={(ids) => updateForm({ audience_user_ids: ids })}
            disabled={saving}
            label="Who can see this character?"
            specificRelationship="mutuals"
          />
          <div className="space-y-1">
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={form.discoverable}
                onCheckedChange={(checked) => updateForm({ discoverable: checked === true })}
                disabled={saving}
              />
              <span>Show this persona in Explore and People search</span>
            </label>
            <p className="text-xs text-muted-foreground">
              When the audience is Everyone, this lists the persona for discovery. Turn it off to keep the persona reachable only by direct link.
            </p>
          </div>
          <LinkedSeparateField
            personaName={form.display_name.trim() || 'this persona'}
            value={form.is_linked}
            onChange={(is_linked) => updateForm({ is_linked })}
            disabled={saving}
          />
          <ProfileOptionButtonGroup legend="Gender" name="character-gender" options={GENDER_OPTIONS} value={form.gender} onChange={(value) => updateForm({ gender: value })} />
          {form.gender === 'other' && (
            <div className="space-y-1">
              <Label htmlFor="character-gender-other">Other gender</Label>
              <Input id="character-gender-other" value={form.gender_other} onChange={(event) => updateForm({ gender_other: event.target.value })} required />
            </div>
          )}
          <ProfileOptionButtonGroup legend="Type" name="character-user-type" options={USER_TYPE_OPTIONS} value={form.user_type} onChange={(value) => updateForm({ user_type: value })} />
          {form.user_type === 'other' && (
            <div className="space-y-1">
              <Label htmlFor="character-user-type-other">Other type</Label>
              <Input id="character-user-type-other" value={form.user_type_other} onChange={(event) => updateForm({ user_type_other: event.target.value })} required />
            </div>
          )}
          {current !== null ? (
            <div className="space-y-4 border-t border-border pt-4">
              <div className="flex items-center gap-3">
                <Avatar name={current.display_name} src={current.profile_picture?.thumbnail_url ?? current.profile_picture?.url} sizeClassName="h-14 w-14" />
                <div className="space-y-1">
                  <Label htmlFor={`character-picture-${current.id}`}>Profile picture</Label>
                  <Input id={`character-picture-${current.id}`} type="file" accept="image/*" disabled={uploading} onChange={(event) => void handleProfilePicture(event.target.files?.[0] ?? null)} />
                </div>
              </div>
              <CharacterInterestsEditor
                characterId={current.id}
                initialInherit={current.inherit_interests}
                onInheritChange={(inherit) => { const next = { ...current, inherit_interests: inherit }; setCurrent(next); onSaved(next); }}
              />
            </div>
          ) : (
            <p className="border-t border-border pt-4 text-sm text-muted-foreground">Save the character to add a profile picture and custom interests.</p>
          )}

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => handleOpenChange(false)} disabled={saving}>Cancel</Button>
            {current === null && <Button type="button" variant="outline" disabled={saving} onClick={() => void save(true)}>Save &amp; add another</Button>}
            <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save character'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
