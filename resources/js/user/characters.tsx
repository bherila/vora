import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { Avatar } from '@/components/avatar';
import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { CharacterInterestsEditor } from '@/interests/character-interests-editor';
import { type Audience, AUDIENCE_WITH_SPECIFIC_OPTIONS } from '@/lib/audience';
import type { MediaItem } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';
import { GENDER_OPTIONS, normalizeProfileOptionValue, normalizeProfileSelections, USER_TYPE_OPTIONS } from '@/profile-options';

interface CharacterRecord {
  id: number;
  display_name: string;
  description: string | null;
  audience: Audience;
  audience_user_ids: number[];
  gender: string | null;
  gender_other: string | null;
  user_type: string | null;
  user_type_other: string | null;
  preferred_user_types: string[] | null;
  preferred_genders: string[] | null;
  inherit_interests: boolean;
  profile_picture: MediaItem | null;
}

interface CharacterFormState {
  display_name: string;
  description: string;
  audience: Audience;
  audience_user_ids: number[];
  gender: string;
  gender_other: string;
  user_type: string;
  user_type_other: string;
  preferred_user_types: string[];
  preferred_genders: string[];
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
    audience: 'everyone',
    audience_user_ids: [],
    gender: '',
    gender_other: '',
    user_type: '',
    user_type_other: '',
    preferred_user_types: [],
    preferred_genders: [],
  };
}

function formFromCharacter(character: CharacterRecord): CharacterFormState {
  return {
    display_name: character.display_name,
    description: character.description ?? '',
    audience: character.audience,
    audience_user_ids: character.audience_user_ids,
    gender: normalizeProfileOptionValue(GENDER_OPTIONS, character.gender),
    gender_other: character.gender_other ?? '',
    user_type: normalizeProfileOptionValue(USER_TYPE_OPTIONS, character.user_type),
    user_type_other: character.user_type_other ?? '',
    preferred_user_types: normalizeProfileSelections(USER_TYPE_OPTIONS, character.preferred_user_types),
    preferred_genders: normalizeProfileSelections(GENDER_OPTIONS, character.preferred_genders),
  };
}

function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function selectionsToPayload(values: string[]): string[] | null {
  return values.length > 0 ? values : null;
}

function audienceLabel(audience: Audience, selectedCount: number): string {
  if (audience === 'specific') {
    return selectedCount === 0 ? 'Only me' : 'Specific people';
  }

  return AUDIENCE_WITH_SPECIFIC_OPTIONS.find((option) => option.value === audience)?.label ?? audience;
}

function CharactersPage() {
  const [characters, setCharacters] = useState<CharacterRecord[]>(() => readInitialData<{ characters?: CharacterRecord[] }>().characters ?? []);
  const [dialogOpen, setDialogOpen] = useState(false);
  // The character being edited, or null when creating a new one.
  const [editing, setEditing] = useState<CharacterRecord | null>(null);
  const [form, setForm] = useState<CharacterFormState>(blankForm());
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [uploadingId, setUploadingId] = useState<number | null>(null);

  const updateForm = (patch: Partial<CharacterFormState>): void => {
    setForm((current) => ({ ...current, ...patch }));
    setDirty(true);
  };

  const upsertCharacter = (character: CharacterRecord): void => {
    setCharacters((current) => {
      const exists = current.some((item) => item.id === character.id);
      return exists ? current.map((item) => item.id === character.id ? character : item) : [character, ...current];
    });
  };

  const openCreate = (): void => {
    setEditing(null);
    setForm(blankForm());
    setDirty(false);
    setError('');
    setDialogOpen(true);
  };

  const openEdit = (character: CharacterRecord): void => {
    setEditing(character);
    setForm(formFromCharacter(character));
    setDirty(false);
    setError('');
    setDialogOpen(true);
  };

  const handleOpenChange = (open: boolean): void => {
    if (open) {
      setDialogOpen(true);
      return;
    }
    if (dirty && !window.confirm('Discard your unsaved changes to this character?')) {
      return;
    }
    setDialogOpen(false);
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
    setMessage('');

    const payload = {
      display_name: form.display_name.trim(),
      description: blankToNull(form.description),
      audience: form.audience,
      audience_user_ids: form.audience === 'specific' ? form.audience_user_ids : [],
      gender: blankToNull(form.gender),
      gender_other: form.gender === 'other' ? blankToNull(form.gender_other) : null,
      user_type: blankToNull(form.user_type),
      user_type_other: form.user_type === 'other' ? blankToNull(form.user_type_other) : null,
      preferred_user_types: selectionsToPayload(form.preferred_user_types),
      preferred_genders: selectionsToPayload(form.preferred_genders),
    };

    try {
      const response = editing === null
        ? await fetchWrapper.post('/api/characters', payload) as CharacterResponse
        : await fetchWrapper.patch(`/api/characters/${editing.id}`, payload) as CharacterResponse;
      upsertCharacter(response.data);
      setMessage(editing === null ? 'Character created.' : 'Character updated.');
      setDirty(false);
      if (addAnother) {
        setEditing(null);
        setForm(blankForm());
      } else {
        setDialogOpen(false);
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to save character.');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (character: CharacterRecord): Promise<void> => {
    if (!window.confirm(`Delete ${character.display_name}? Art ownership stays with your user account.`)) {
      return;
    }
    try {
      await fetchWrapper.delete(`/api/characters/${character.id}`);
      setCharacters((current) => current.filter((item) => item.id !== character.id));
      setMessage('Character deleted.');
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to delete character.');
    }
  };

  const handleProfilePicture = async (character: CharacterRecord, file: File | null): Promise<void> => {
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      setError('Character profile pictures must be images.');
      return;
    }

    setUploadingId(character.id);
    setError('');
    setMessage('');

    try {
      const created = await fetchWrapper.post(`/api/characters/${character.id}/profile-picture`, {
        filename: file.name,
        content_type: file.type,
        size: file.size,
      }) as ProfilePictureUploadResponse;
      await putToSignedUrl(created.upload_url, file, created.upload_headers, () => {});
      const completed = await fetchWrapper.post(`/api/characters/${character.id}/profile-picture/${created.data.id}/complete`, {}) as CharacterResponse;
      upsertCharacter(completed.data);
      setEditing(completed.data);
      setMessage(`${character.display_name}'s profile picture uploaded and waiting for admin review.`);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to upload character profile picture.');
    } finally {
      setUploadingId(null);
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-2">
          <h1 className="text-2xl font-bold">Characters</h1>
          <p className="max-w-2xl text-sm text-muted-foreground">
            Optional fictional personas for art attribution. Your logged-in user profile stays the default when no character is selected.
          </p>
        </div>
        <Button type="button" onClick={openCreate}><Plus className="h-4 w-4" /> Add character</Button>
      </div>

      {error && !dialogOpen && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}

      {characters.length === 0 ? (
        <Card><CardContent className="py-8 text-center text-sm text-muted-foreground">No characters yet — they’re optional. Add one when a persona helps you organize your art.</CardContent></Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {characters.map((character) => (
            <Card key={character.id} className="flex flex-col">
              <CardHeader className="flex-row items-center gap-3 space-y-0">
                <Avatar name={character.display_name} src={character.profile_picture?.thumbnail_url ?? character.profile_picture?.url} sizeClassName="h-12 w-12" />
                <div className="min-w-0">
                  <CardTitle className="truncate text-base">{character.display_name}</CardTitle>
                  <CardDescription className="truncate">{audienceLabel(character.audience, character.audience_user_ids.length)}</CardDescription>
                </div>
              </CardHeader>
              <CardContent className="flex flex-1 flex-col gap-3">
                <p className="line-clamp-3 text-sm text-muted-foreground">{character.description || 'No description yet.'}</p>
                <div className="mt-auto flex gap-2">
                  <Button type="button" size="sm" variant="outline" onClick={() => openEdit(character)}><Pencil className="h-4 w-4" /> Edit</Button>
                  <Button type="button" size="sm" variant="ghost" onClick={() => void handleDelete(character)} aria-label={`Delete ${character.display_name}`}><Trash2 className="h-4 w-4" /></Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={dialogOpen} onOpenChange={handleOpenChange}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editing === null ? 'Add character' : `Edit ${editing.display_name}`}</DialogTitle>
            <DialogDescription>This does not change your real user account profile.</DialogDescription>
          </DialogHeader>

          {error && dialogOpen && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}

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
            <ProfileOptionCheckboxGroup legend="User types to see" description="Optional discovery preferences for this character." name="character-user-types" options={USER_TYPE_OPTIONS} values={form.preferred_user_types} onChange={(values) => updateForm({ preferred_user_types: values })} />
            <ProfileOptionCheckboxGroup legend="Genders to see" description="Optional discovery preferences for this character." name="character-genders" options={GENDER_OPTIONS} values={form.preferred_genders} onChange={(values) => updateForm({ preferred_genders: values })} />

            {/* Picture + interests need a saved character (they hit per-character
                endpoints), so they appear once the character exists. */}
            {editing !== null ? (
              <div className="space-y-4 border-t border-border pt-4">
                <div className="flex items-center gap-3">
                  <Avatar name={editing.display_name} src={editing.profile_picture?.thumbnail_url ?? editing.profile_picture?.url} sizeClassName="h-14 w-14" />
                  <div className="space-y-1">
                    <Label htmlFor={`character-picture-${editing.id}`}>Profile picture</Label>
                    <Input id={`character-picture-${editing.id}`} type="file" accept="image/*" disabled={uploadingId === editing.id} onChange={(event) => void handleProfilePicture(editing, event.target.files?.[0] ?? null)} />
                  </div>
                </div>
                <CharacterInterestsEditor
                  characterId={editing.id}
                  initialInherit={editing.inherit_interests}
                  onInheritChange={(inherit) => { setCharacters((current) => current.map((item) => item.id === editing.id ? { ...item, inherit_interests: inherit } : item)); setEditing((current) => current ? { ...current, inherit_interests: inherit } : current); }}
                />
              </div>
            ) : (
              <p className="border-t border-border pt-4 text-sm text-muted-foreground">Save the character to add a profile picture and custom interests.</p>
            )}

            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => handleOpenChange(false)} disabled={saving}>Cancel</Button>
              {editing === null && <Button type="button" variant="outline" disabled={saving} onClick={() => void save(true)}>Save & add another</Button>}
              <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save character'}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('characters');
if (mountEl) {
  createRoot(mountEl).render(<CharactersPage />);
}
