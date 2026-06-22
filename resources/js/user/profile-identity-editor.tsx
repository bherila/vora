import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { loadInterests, persistRatings } from '@/interests/api';
import { InterestRatingList, type RatableInterest } from '@/interests/interest-rating-list';
import { type Audience } from '@/lib/audience';
import { putToSignedUrl } from '@/media/upload';
import { GENDER_OPTIONS, normalizeProfileOptionValue, normalizeProfileSelections, USER_TYPE_OPTIONS } from '@/profile-options';

export interface ProfileEditable {
  name: string;
  email: string;
  display_name: string;
  gender: string | null;
  gender_other: string | null;
  user_type: string | null;
  user_type_other: string | null;
  preferred_user_types: string[];
  preferred_genders: string[];
  profile_audience: Audience;
  audience_user_ids: number[];
  can_manage_interests: boolean;
}

interface ProfileIdentityEditorProps {
  editable: ProfileEditable;
  onSaved: (summary: { display_name: string; user_type: string | null; gender: string | null }) => void;
}

interface AccountUpdateResponse {
  success: boolean;
  data?: { display_name: string; user_type: string | null; gender: string | null };
  message?: string;
}
interface ProfilePictureUploadResponse { success: boolean; data: { id: number }; upload_url: string; upload_headers: Record<string, string> }
interface ProfilePictureCompleteResponse { success: boolean; data: { upload_status: string } }

function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function selectionsToPayload(values: string[]): string[] | null {
  return values.length > 0 ? values : null;
}

/**
 * Inline editor for the user's public identity, shown on /me. It owns exactly the
 * fields that describe a profile (name passes through unchanged); the account and
 * security concerns (real name, email, notifications, password, deletion) stay in
 * Settings. Reuses the existing /api/account and profile-picture endpoints so
 * there is no parallel validation.
 */
export function ProfileIdentityEditor({ editable, onSaved }: ProfileIdentityEditorProps) {
  const [displayName, setDisplayName] = useState(editable.display_name);
  const [gender, setGender] = useState(normalizeProfileOptionValue(GENDER_OPTIONS, editable.gender));
  const [genderOther, setGenderOther] = useState(editable.gender_other ?? '');
  const [userType, setUserType] = useState(normalizeProfileOptionValue(USER_TYPE_OPTIONS, editable.user_type));
  const [userTypeOther, setUserTypeOther] = useState(editable.user_type_other ?? '');
  const [preferredUserTypes, setPreferredUserTypes] = useState(normalizeProfileSelections(USER_TYPE_OPTIONS, editable.preferred_user_types));
  const [preferredGenders, setPreferredGenders] = useState(normalizeProfileSelections(GENDER_OPTIONS, editable.preferred_genders));
  const [audience, setAudience] = useState<Audience>(editable.profile_audience);
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>(editable.audience_user_ids);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const [pictureUploading, setPictureUploading] = useState(false);
  const [pictureProgress, setPictureProgress] = useState(0);
  const [pictureMessage, setPictureMessage] = useState('');
  const [pictureError, setPictureError] = useState('');
  const pictureAbortRef = useRef<AbortController | null>(null);

  const [interests, setInterests] = useState<RatableInterest[]>([]);

  useEffect(() => {
    if (!editable.can_manage_interests) return;
    let active = true;
    loadInterests(null)
      .then(({ interests: loaded }) => { if (active) setInterests(loaded); })
      .catch(() => { /* interests are optional; the rest of the editor still works */ });
    return () => { active = false; };
  }, [editable.can_manage_interests]);

  const saveInterest = async (interestId: number, level: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level }]);
      setInterests((current) => current.map((item) => item.id === interestId ? { ...item, rating: level } : item));
      toast.success('Interest saved.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to save interest.');
      throw err; // keep the row pending for retry
    }
  };

  const clearInterest = async (interestId: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level: null }]);
      setInterests((current) => current.map((item) => item.id === interestId ? { ...item, rating: null } : item));
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to clear interest.');
    }
  };

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (!displayName.trim()) { setError('Display name is required.'); return; }
    if (gender === 'other' && !genderOther.trim()) { setError('Specify your gender when choosing Other.'); return; }
    if (userType === 'other' && !userTypeOther.trim()) { setError('Specify your type when choosing Other.'); return; }

    setSaving(true);
    setError('');
    try {
      const response = await fetchWrapper.patch('/api/account', {
        // name + email are required by the endpoint; pass them through unchanged
        // so this editor never touches account/security fields.
        name: editable.name,
        email: editable.email,
        display_name: displayName.trim(),
        gender: blankToNull(gender),
        gender_other: gender === 'other' ? blankToNull(genderOther) : null,
        user_type: blankToNull(userType),
        user_type_other: userType === 'other' ? blankToNull(userTypeOther) : null,
        preferred_user_types: selectionsToPayload(preferredUserTypes),
        preferred_genders: selectionsToPayload(preferredGenders),
        profile_audience: audience,
        audience_user_ids: audience === 'specific' ? audienceUserIds : [],
      }) as AccountUpdateResponse;
      toast.success('Profile updated.');
      onSaved({
        display_name: response.data?.display_name ?? displayName.trim(),
        user_type: response.data?.user_type ?? blankToNull(userType),
        gender: response.data?.gender ?? blankToNull(gender),
      });
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update profile.');
    } finally {
      setSaving(false);
    }
  };

  const onPictureChange = async (selectedFiles: File[]): Promise<void> => {
    const file = selectedFiles[0] ?? null;
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      setPictureError('Profile pictures must be images, not videos.');
      setPictureMessage('');
      return;
    }

    const abortController = new AbortController();
    pictureAbortRef.current = abortController;
    setPictureUploading(true);
    setPictureProgress(0);
    setPictureError('');
    setPictureMessage('');

    try {
      const created = await fetchWrapper.post('/api/account/profile-picture', {
        filename: file.name,
        content_type: file.type,
        size: file.size,
      }) as ProfilePictureUploadResponse;
      await putToSignedUrl(created.upload_url, file, created.upload_headers, (fraction) => setPictureProgress(fraction * 100), { signal: abortController.signal });
      const completed = await fetchWrapper.post(`/api/account/profile-picture/${created.data.id}/complete`, {}) as ProfilePictureCompleteResponse;
      setPictureMessage(completed.data.upload_status === 'ready'
        ? 'Profile picture uploaded and waiting for admin review.'
        : 'Profile picture upload started.');
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        setPictureError('Profile picture upload canceled.');
      } else {
        setPictureError(typeof err === 'string' ? err : 'Failed to upload profile picture.');
      }
    } finally {
      setPictureUploading(false);
      pictureAbortRef.current = null;
    }
  };

  const removePicture = async (): Promise<void> => {
    setPictureError('');
    setPictureMessage('');
    try {
      await fetchWrapper.delete('/api/account/profile-picture', {});
      setPictureMessage('Profile picture removed.');
    } catch (err) {
      setPictureError(typeof err === 'string' ? err : 'Failed to remove profile picture.');
    }
  };

  return (
    <div className="space-y-5">
      <div className="space-y-3 rounded-lg border border-border p-4">
        <div>
          <h3 className="font-medium">Profile picture</h3>
          <p className="text-sm text-muted-foreground">An admin reviews new images before other users can see them.</p>
        </div>
        {pictureError && <Alert variant="destructive"><AlertDescription>{pictureError}</AlertDescription></Alert>}
        {pictureMessage && <Alert><AlertDescription>{pictureMessage}</AlertDescription></Alert>}
        <FileDropzone
          accept="image/*"
          files={[]}
          label="Drop a profile image here"
          onFilesChange={(nextFiles) => void onPictureChange(nextFiles)}
          disabled={pictureUploading}
          helperText="Select one image. Drag and drop here, or click to browse."
        />
        {pictureUploading && (
          <UploadProgress label="Uploading profile picture…" progress={pictureProgress} onCancel={() => pictureAbortRef.current?.abort()} />
        )}
        <Button type="button" variant="outline" size="sm" disabled={pictureUploading} onClick={() => void removePicture()}>
          Remove current picture
        </Button>
      </div>

      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}

      <form className="space-y-4" onSubmit={(event) => void submit(event)}>
        <div className="space-y-1">
          <Label htmlFor="me-display-name">Display name</Label>
          <Input id="me-display-name" value={displayName} onChange={(event) => setDisplayName(event.target.value)} autoComplete="nickname" required />
        </div>
        <ProfileOptionButtonGroup legend="User type" name="me-user-type" options={USER_TYPE_OPTIONS} value={userType} onChange={setUserType} />
        {userType === 'other' && (
          <div className="space-y-1">
            <Label htmlFor="me-user-type-other">Other user type</Label>
            <Input id="me-user-type-other" value={userTypeOther} onChange={(event) => setUserTypeOther(event.target.value)} required />
          </div>
        )}
        <ProfileOptionButtonGroup legend="Gender" name="me-gender" options={GENDER_OPTIONS} value={gender} onChange={setGender} />
        {gender === 'other' && (
          <div className="space-y-1">
            <Label htmlFor="me-gender-other">Other gender</Label>
            <Input id="me-gender-other" value={genderOther} onChange={(event) => setGenderOther(event.target.value)} required />
          </div>
        )}
        <ProfileOptionCheckboxGroup legend="User types you want to see" description="Used for discovery and matching." name="me-preferred-user-types" options={USER_TYPE_OPTIONS} values={preferredUserTypes} onChange={setPreferredUserTypes} />
        <ProfileOptionCheckboxGroup legend="Genders you want to see" description="Used for discovery and matching." name="me-preferred-genders" options={GENDER_OPTIONS} values={preferredGenders} onChange={setPreferredGenders} />
        <div className="space-y-2">
          <AudienceField audience={audience} onAudienceChange={setAudience} selectedUserIds={audienceUserIds} onSelectedUserIdsChange={setAudienceUserIds} label="Who can see your profile" />
          <p className="text-sm text-muted-foreground">Restricted profiles stay listed so people can still request to follow you — only your details are hidden.</p>
        </div>
        <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save profile'}</Button>
      </form>

      {editable.can_manage_interests && (
        <div className="space-y-2 border-t border-border pt-4">
          <h3 className="font-medium">Your interests</h3>
          <p className="text-sm text-muted-foreground">Rate interests from -10 to +10. Characters inherit these unless you set custom interests.</p>
          <InterestRatingList interests={interests} onSave={saveInterest} onClear={clearInterest} />
        </div>
      )}
    </div>
  );
}
