import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { ProtectedImage } from '@/components/protected-image';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { CharacterInterestsEditor } from '@/interests/character-interests-editor';
import { type Audience,AUDIENCE_WITH_SPECIFIC_OPTIONS } from '@/lib/audience';
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

interface CharacterListResponse {
  success: boolean;
  data: CharacterRecord[];
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
  const [form, setForm] = useState<CharacterFormState>(blankForm());
  const [editingId, setEditingId] = useState<number | null>(null);
  const [loading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [uploadingId, setUploadingId] = useState<number | null>(null);


  const resetForm = () => {
    setEditingId(null);
    setForm(blankForm());
  };

  const upsertCharacter = (character: CharacterRecord) => {
    setCharacters((current) => {
      const exists = current.some((item) => item.id === character.id);
      return exists ? current.map((item) => item.id === character.id ? character : item) : [character, ...current];
    });
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!form.display_name.trim()) {
      setError('Character display name is required.');
      return;
    }

    if (form.gender === 'other' && !form.gender_other.trim()) {
      setError('Please specify the character gender when choosing Other.');
      return;
    }

    if (form.user_type === 'other' && !form.user_type_other.trim()) {
      setError('Please specify the character type when choosing Other.');
      return;
    }

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
      const response = editingId === null
        ? await fetchWrapper.post('/api/characters', payload) as CharacterResponse
        : await fetchWrapper.patch(`/api/characters/${editingId}`, payload) as CharacterResponse;
      upsertCharacter(response.data);
      setMessage(editingId === null ? 'Character created.' : 'Character updated.');
      resetForm();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to save character.');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (character: CharacterRecord) => {
    if (!window.confirm(`Delete ${character.display_name}? Art ownership stays with your user account.`)) {
      return;
    }

    try {
      await fetchWrapper.delete(`/api/characters/${character.id}`);
      setCharacters((current) => current.filter((item) => item.id !== character.id));
      if (editingId === character.id) resetForm();
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
      setMessage(`${character.display_name}'s profile picture uploaded and waiting for admin review.`);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to upload character profile picture.');
    } finally {
      setUploadingId(null);
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-8 px-4 py-8">
      <div className="space-y-2">
        <h1 className="text-2xl font-bold">Characters</h1>
        <p className="max-w-3xl text-muted-foreground">
          Characters are optional fictional personas you can prepare for art attribution. Your logged-in user profile is still the default profile when no character is selected.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>How characters work</CardTitle>
          <CardDescription>Use characters only if they fit how you organize your art.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3 text-sm text-muted-foreground">
          <p>Each character can have a separate display name, gender, type, discovery preferences, and profile picture. This keeps persona details separate from your real account settings.</p>
          <p>By default a character inherits your profile interests. Choose “Set custom interests” on a character to override them; switching back to inherit clears the character's overrides.</p>
          <p>Follow requests are still between users only. Following a user follows that account and all of its characters for now; character-specific follows may be added later.</p>
          <p>To change your own default user profile, use Account → Settings. To change a fictional persona, edit it here.</p>
        </CardContent>
      </Card>

      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
        <section className="space-y-4">
          <h2 className="text-xl font-semibold">Your characters</h2>
          {loading && <p className="text-sm text-muted-foreground">Loading characters…</p>}
          {!loading && characters.length === 0 && (
            <Card><CardContent className="py-6 text-sm text-muted-foreground">You have not added any characters. That is completely fine—characters are optional.</CardContent></Card>
          )}
          {characters.map((character) => (
            <Card key={character.id}>
              <CardHeader>
                <CardTitle>{character.display_name}</CardTitle>
                <CardDescription>{character.description || 'No description yet.'}</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {character.profile_picture?.url && (
                  <ProtectedImage src={character.profile_picture.url} alt="" className="h-24 w-24 rounded-full object-cover" />
                )}
                <div className="text-sm text-muted-foreground">
                  <p>Gender: {character.gender === 'other' ? character.gender_other : character.gender || 'Not set'}</p>
                  <p>Type: {character.user_type === 'other' ? character.user_type_other : character.user_type || 'Not set'}</p>
                  <p>Visible to: {audienceLabel(character.audience, character.audience_user_ids.length)}</p>
                  <p>Discovery preferences: {[...(character.preferred_user_types ?? []), ...(character.preferred_genders ?? [])].join(', ') || 'Not set'}</p>
                </div>
                <CharacterInterestsEditor
                  characterId={character.id}
                  initialInherit={character.inherit_interests}
                  onInheritChange={(inherit) => setCharacters((current) => current.map((item) => item.id === character.id ? { ...item, inherit_interests: inherit } : item))}
                />
                <div className="space-y-2">
                  <Label htmlFor={`character-picture-${character.id}`}>Character profile picture</Label>
                  <Input id={`character-picture-${character.id}`} type="file" accept="image/*" disabled={uploadingId === character.id} onChange={(event) => void handleProfilePicture(character, event.target.files?.[0] ?? null)} />
                </div>
                <div className="flex gap-2">
                  <Button type="button" variant="secondary" onClick={() => { setEditingId(character.id); setForm(formFromCharacter(character)); }}>Edit character</Button>
                  <Button type="button" variant="destructive" onClick={() => void handleDelete(character)}>Delete</Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </section>

        <section className="space-y-4">
          <h2 className="text-xl font-semibold">{editingId === null ? 'Add character' : 'Edit character'}</h2>
          <Card>
            <CardHeader>
              <CardTitle>{editingId === null ? 'New fictional persona' : 'Character profile'}</CardTitle>
              <CardDescription>This does not change your real user account profile.</CardDescription>
            </CardHeader>
            <CardContent>
              <form className="space-y-4" onSubmit={(event) => void handleSubmit(event)}>
                <div className="space-y-1">
                  <Label htmlFor="character-display-name">Character display name</Label>
                  <Input id="character-display-name" value={form.display_name} onChange={(event) => setForm({ ...form, display_name: event.target.value })} required />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="character-description">Description</Label>
                  <Textarea id="character-description" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
                </div>
                <AudienceField
                  audience={form.audience}
                  onAudienceChange={(audience) => setForm({ ...form, audience })}
                  selectedUserIds={form.audience_user_ids}
                  onSelectedUserIdsChange={(ids) => setForm({ ...form, audience_user_ids: ids })}
                  disabled={saving}
                  label="Who can see this character?"
                  specificRelationship="mutuals"
                />
                <ProfileOptionButtonGroup legend="Character gender" name="character-gender" options={GENDER_OPTIONS} value={form.gender} onChange={(value) => setForm({ ...form, gender: value })} />
                {form.gender === 'other' && (
                  <div className="space-y-1">
                    <Label htmlFor="character-gender-other">Other character gender</Label>
                    <Input id="character-gender-other" value={form.gender_other} onChange={(event) => setForm({ ...form, gender_other: event.target.value })} required />
                  </div>
                )}
                <ProfileOptionButtonGroup legend="Character type" name="character-user-type" options={USER_TYPE_OPTIONS} value={form.user_type} onChange={(value) => setForm({ ...form, user_type: value })} />
                {form.user_type === 'other' && (
                  <div className="space-y-1">
                    <Label htmlFor="character-user-type-other">Other character type</Label>
                    <Input id="character-user-type-other" value={form.user_type_other} onChange={(event) => setForm({ ...form, user_type_other: event.target.value })} required />
                  </div>
                )}
                <ProfileOptionCheckboxGroup legend="Character user types to see" description="Optional discovery preferences for this character." name="character-user-types" options={USER_TYPE_OPTIONS} values={form.preferred_user_types} onChange={(values) => setForm({ ...form, preferred_user_types: values })} />
                <ProfileOptionCheckboxGroup legend="Character genders to see" description="Optional discovery preferences for this character." name="character-genders" options={GENDER_OPTIONS} values={form.preferred_genders} onChange={(values) => setForm({ ...form, preferred_genders: values })} />
                <div className="flex gap-2">
                  <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save character'}</Button>
                  {editingId !== null && <Button type="button" variant="secondary" onClick={resetForm}>Cancel</Button>}
                </div>
              </form>
            </CardContent>
          </Card>
        </section>
      </div>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('characters');
if (mountEl) {
  createRoot(mountEl).render(<CharactersPage />);
}
