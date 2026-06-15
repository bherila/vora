import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { loadInterests, persistRatings } from '@/interests/api';
import { InterestRatingList, type RatableInterest } from '@/interests/interest-rating-list';
import { RequestInterestForm } from '@/interests/request-interest-form';
import type { MediaItem } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';
import {
  GENDER_OPTIONS,
  normalizeProfileOptionValue,
  normalizeProfileSelections,
  USER_TYPE_OPTIONS,
} from '@/profile-options';

import { getAuthComponents } from './shared-components';

interface UserSettingsInitialPayload {
  name?: string | null;
  display_name?: string | null;
  birth_date?: string | null;
  email?: string | null;
  gender?: string | null;
  gender_other?: string | null;
  user_type?: string | null;
  user_type_other?: string | null;
  preferred_user_types?: unknown;
  preferred_genders?: unknown;
  id_verified_at?: string | null;
  name_locked?: boolean | null;
  email_locked?: boolean | null;
  email_follow_request_received?: boolean | null;
  email_follow_request_accepted?: boolean | null;
  can_manage_interests?: boolean | null;
}

interface UserSettingsInitialData {
  name: string;
  display_name: string;
  birth_date: string;
  email: string;
  gender: string;
  gender_other: string;
  user_type: string;
  user_type_other: string;
  preferred_user_types: string[];
  preferred_genders: string[];
  id_verified_at: string | null;
  name_locked: boolean;
  email_locked: boolean;
  email_follow_request_received: boolean;
  email_follow_request_accepted: boolean;
  can_manage_interests: boolean;
}

interface ProfilePictureUploadResponse {
  success: boolean;
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
}

interface ProfilePictureCompleteResponse {
  success: boolean;
  data: MediaItem;
}

interface UserSettingsResponse {
  success: boolean;
  message?: string;
  data?: {
    name: string;
    display_name: string;
    email: string;
    gender: string | null;
    gender_other: string | null;
    user_type: string | null;
    user_type_other: string | null;
    preferred_user_types: string[] | null;
    preferred_genders: string[] | null;
    email_follow_request_received: boolean;
    email_follow_request_accepted: boolean;
  };
}

interface AccountPayload {
  name: string;
  display_name: string;
  email: string;
  gender: string | null;
  gender_other: string | null;
  user_type: string | null;
  user_type_other: string | null;
  preferred_user_types: string[] | null;
  preferred_genders: string[] | null;
  email_follow_request_received: boolean;
  email_follow_request_accepted: boolean;
}

function emptyInitialData(): UserSettingsInitialData {
  return {
    name: '',
    display_name: '',
    birth_date: '',
    email: '',
    gender: '',
    gender_other: '',
    user_type: '',
    user_type_other: '',
    preferred_user_types: [],
    preferred_genders: [],
    id_verified_at: null,
    name_locked: false,
    email_locked: false,
    email_follow_request_received: false,
    email_follow_request_accepted: false,
    can_manage_interests: false,
  };
}

function normalizeInitialData(payload: UserSettingsInitialPayload): UserSettingsInitialData {
  return {
    name: payload.name ?? '',
    display_name: payload.display_name ?? '',
    birth_date: payload.birth_date ?? '',
    email: payload.email ?? '',
    gender: normalizeProfileOptionValue(GENDER_OPTIONS, payload.gender),
    gender_other: payload.gender_other ?? '',
    user_type: normalizeProfileOptionValue(USER_TYPE_OPTIONS, payload.user_type),
    user_type_other: payload.user_type_other ?? '',
    preferred_user_types: normalizeProfileSelections(USER_TYPE_OPTIONS, payload.preferred_user_types),
    preferred_genders: normalizeProfileSelections(GENDER_OPTIONS, payload.preferred_genders),
    id_verified_at: payload.id_verified_at ?? null,
    name_locked: payload.name_locked ?? false,
    email_locked: payload.email_locked ?? false,
    email_follow_request_received: payload.email_follow_request_received ?? false,
    email_follow_request_accepted: payload.email_follow_request_accepted ?? false,
    can_manage_interests: payload.can_manage_interests ?? false,
  };
}

function getInitialData(): UserSettingsInitialData {
  const element = document.getElementById('user-settings-initial-data');
  if (!element || !element.textContent) {
    return emptyInitialData();
  }

  try {
    return normalizeInitialData(JSON.parse(element.textContent) as UserSettingsInitialPayload);
  } catch {
    return emptyInitialData();
  }
}

function blankToNull(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

function selectionsToPayload(values: string[]): string[] | null {
  return values.length > 0 ? values : null;
}

function UserSettingsPage() {
  const initialData = getInitialData();

  const [accountName, setAccountName] = useState(initialData.name);
  const [profileDisplayName, setProfileDisplayName] = useState(initialData.display_name);
  const [accountBirthDate] = useState(initialData.birth_date);
  const [accountEmail, setAccountEmail] = useState(initialData.email);
  const [profileGender, setProfileGender] = useState(initialData.gender);
  const [profileGenderOther, setProfileGenderOther] = useState(initialData.gender_other);
  const [profileUserType, setProfileUserType] = useState(initialData.user_type);
  const [profileUserTypeOther, setProfileUserTypeOther] = useState(initialData.user_type_other);
  const [preferredUserTypes, setPreferredUserTypes] = useState(initialData.preferred_user_types);
  const [preferredGenders, setPreferredGenders] = useState(initialData.preferred_genders);
  const [accountVerificationDate] = useState(initialData.id_verified_at);
  const [nameLocked] = useState(initialData.name_locked);
  const [emailLocked] = useState(initialData.email_locked);
  const [emailFollowRequestReceived, setEmailFollowRequestReceived] = useState(initialData.email_follow_request_received);
  const [emailFollowRequestAccepted, setEmailFollowRequestAccepted] = useState(initialData.email_follow_request_accepted);
  const [profileSaving, setProfileSaving] = useState(false);
  const [accountSaving, setAccountSaving] = useState(false);
  const [passkeyMessage, setPasskeyMessage] = useState('');
  const [profileMessage, setProfileMessage] = useState('');
  const [profileError, setProfileError] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [accountError, setAccountError] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');
  const [profilePictureUploading, setProfilePictureUploading] = useState(false);
  const [profilePictureProgress, setProfilePictureProgress] = useState(0);
  const [profilePictureFiles, setProfilePictureFiles] = useState<File[]>([]);
  const profilePictureAbortRef = useRef<AbortController | null>(null);
  const [profilePictureMessage, setProfilePictureMessage] = useState('');
  const [profilePictureError, setProfilePictureError] = useState('');
  const [interests, setInterests] = useState<RatableInterest[]>([]);
  // Mirrors the /api/interests access gate. Changing the email clears email
  // verification server-side, which makes the API 403, so we drop access here
  // too rather than leave the panels interactive until a reload.
  const [interestsEnabled, setInterestsEnabled] = useState(initialData.can_manage_interests);

  useEffect(() => {
    // The interests API is gated behind the approval middleware (approved +
    // verified + not disabled), so only eligible users can load/rate them.
    // Other users still see the rest of Settings without a failing request.
    if (!interestsEnabled) {
      return;
    }

    let active = true;
    const load = async (): Promise<void> => {
      try {
        const { interests: loaded } = await loadInterests(null);
        if (active) {
          setInterests(loaded);
        }
      } catch (err) {
        toast.error(typeof err === 'string' ? err : 'Failed to load interests.');
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [interestsEnabled]);

  const handleSaveInterestRating = async (interestId: number, level: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level }]);
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: level } : item)));
      toast.success('Interest rating saved.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to save interest rating.');
      throw err; // let the list keep the row pending for retry
    }
  };

  const handleClearInterestRating = async (interestId: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level: null }]);
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: null } : item)));
      toast.success('Interest rating cleared.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to clear interest rating.');
    }
  };

  const applyResponseData = (data: UserSettingsResponse['data']) => {
    if (!data) {
      return;
    }

    setAccountName(data.name);
    setProfileDisplayName(data.display_name);
    setAccountEmail(data.email);
    setProfileGender(normalizeProfileOptionValue(GENDER_OPTIONS, data.gender));
    setProfileGenderOther(data.gender_other ?? '');
    setProfileUserType(normalizeProfileOptionValue(USER_TYPE_OPTIONS, data.user_type));
    setProfileUserTypeOther(data.user_type_other ?? '');
    setPreferredUserTypes(normalizeProfileSelections(USER_TYPE_OPTIONS, data.preferred_user_types));
    setPreferredGenders(normalizeProfileSelections(GENDER_OPTIONS, data.preferred_genders));
    setEmailFollowRequestReceived(data.email_follow_request_received);
    setEmailFollowRequestAccepted(data.email_follow_request_accepted);
  };

  const buildAccountPayload = (overrides: Partial<Pick<AccountPayload, 'name' | 'display_name' | 'email'>> = {}): AccountPayload => ({
    name: overrides.name ?? accountName.trim(),
    display_name: overrides.display_name ?? profileDisplayName.trim(),
    email: overrides.email ?? accountEmail.trim(),
    gender: blankToNull(profileGender),
    gender_other: profileGender === 'other' ? blankToNull(profileGenderOther) : null,
    user_type: blankToNull(profileUserType),
    user_type_other: profileUserType === 'other' ? blankToNull(profileUserTypeOther) : null,
    preferred_user_types: selectionsToPayload(preferredUserTypes),
    preferred_genders: selectionsToPayload(preferredGenders),
    email_follow_request_received: emailFollowRequestReceived,
    email_follow_request_accepted: emailFollowRequestAccepted,
  });

  const handleProfilePictureChange = async (selectedFiles: File[]): Promise<void> => {
    const file = selectedFiles[0] ?? null;
    setProfilePictureFiles(file ? [file] : []);
    if (!file) {
      return;
    }

    if (!file.type.startsWith('image/')) {
      setProfilePictureError('Profile pictures must be images, not videos.');
      setProfilePictureMessage('');
      return;
    }

    const abortController = new AbortController();
    profilePictureAbortRef.current = abortController;
    setProfilePictureUploading(true);
    setProfilePictureProgress(0);
    setProfilePictureError('');
    setProfilePictureMessage('');

    try {
      const created = await fetchWrapper.post('/api/account/profile-picture', {
        filename: file.name,
        content_type: file.type,
        size: file.size,
      }) as ProfilePictureUploadResponse;

      await putToSignedUrl(created.upload_url, file, created.upload_headers, (fraction) => {
        setProfilePictureProgress(fraction * 100);
      }, { signal: abortController.signal });

      const completed = await fetchWrapper.post(`/api/account/profile-picture/${created.data.id}/complete`, {}) as ProfilePictureCompleteResponse;
      setProfilePictureMessage(completed.data.upload_status === 'ready'
        ? 'Profile picture uploaded and waiting for admin review.'
        : 'Profile picture upload started.');
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        setProfilePictureError('Profile picture upload canceled.');
      } else {
        setProfilePictureError(typeof err === 'string' ? err : 'Failed to upload profile picture.');
      }
    } finally {
      setProfilePictureUploading(false);
      profilePictureAbortRef.current = null;
    }
  };

  const handleProfileSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const displayName = profileDisplayName.trim();
    if (!displayName) {
      setProfileError('Display name is required.');
      return;
    }

    if (profileUserType === 'other' && profileUserTypeOther.trim().length === 0) {
      setProfileError('Please specify your user type when choosing Other.');
      return;
    }

    if (profileGender === 'other' && profileGenderOther.trim().length === 0) {
      setProfileError('Please specify your gender when choosing Other.');
      return;
    }

    setProfileSaving(true);
    setProfileError('');
    setProfileMessage('');

    try {
      const response = await fetchWrapper.patch('/api/account', buildAccountPayload({
        display_name: displayName,
      })) as UserSettingsResponse;

      setProfileMessage('Profile updated.');
      applyResponseData(response.data);
    } catch (err) {
      setProfileError(typeof err === 'string' ? err : 'Failed to update profile.');
    } finally {
      setProfileSaving(false);
    }
  };

  const handleAccountSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const name = accountName.trim();
    const email = accountEmail.trim();
    if (!name || !email) {
      setAccountError('Real name and email are required.');
      return;
    }

    setAccountSaving(true);
    setAccountError('');
    setAccountMessage('');

    try {
      const response = await fetchWrapper.patch('/api/account', buildAccountPayload({
        name,
        email,
      })) as UserSettingsResponse;

      // Changing the email resets verification, so the interests API will 403
      // until the new address is verified — hide the panels immediately.
      if (email !== initialData.email) {
        setInterestsEnabled(false);
      }

      setAccountMessage(response.message ?? 'Account updated.');
      applyResponseData(response.data);
    } catch (err) {
      setAccountError(typeof err === 'string' ? err : 'Failed to update account.');
    } finally {
      setAccountSaving(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl space-y-8 px-4 py-8">
      <h1 className="text-2xl font-bold">Settings</h1>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">My Profile</h2>
        <Card>
          <CardHeader>
            <CardTitle>Profile details</CardTitle>
            <CardDescription>Control how your profile appears in discovery.</CardDescription>
          </CardHeader>
          <CardContent>
            {profileError && (
              <Alert variant="destructive" className="mb-4">
                <AlertDescription>{profileError}</AlertDescription>
              </Alert>
            )}
            {profileMessage && (
              <Alert className="mb-4">
                <AlertDescription>{profileMessage}</AlertDescription>
              </Alert>
            )}
            <div className="mb-6 space-y-3 rounded-lg border border-border p-4">
              <div>
                <h3 className="font-medium">Profile picture</h3>
                <p className="text-sm text-muted-foreground">
                  Upload an image for your profile. It will be reviewed by an admin before other users can see it.
                </p>
              </div>
              {profilePictureError && (
                <Alert variant="destructive">
                  <AlertDescription>{profilePictureError}</AlertDescription>
                </Alert>
              )}
              {profilePictureMessage && (
                <Alert>
                  <AlertDescription>{profilePictureMessage}</AlertDescription>
                </Alert>
              )}
              <div className="space-y-2">
                <FileDropzone
                  accept="image/*"
                  files={profilePictureFiles}
                  label="Drop a profile image here"
                  onFilesChange={(nextFiles) => void handleProfilePictureChange(nextFiles)}
                  disabled={profilePictureUploading}
                  helperText="Select one image. Drag and drop here, or click to browse."
                />
                {profilePictureUploading && (
                  <UploadProgress
                    label="Uploading profile picture…"
                    progress={profilePictureProgress}
                    onCancel={() => profilePictureAbortRef.current?.abort()}
                  />
                )}
              </div>
            </div>
            <form className="space-y-4" onSubmit={(event) => void handleProfileSubmit(event)}>
              <div className="space-y-1">
                <Label htmlFor="account-display-name">Display name</Label>
                <Input
                  id="account-display-name"
                  value={profileDisplayName}
                  onChange={(event) => setProfileDisplayName(event.target.value)}
                  autoComplete="nickname"
                  required
                />
              </div>
              <ProfileOptionButtonGroup
                legend="User type"
                name="account-user-type"
                options={USER_TYPE_OPTIONS}
                value={profileUserType}
                onChange={setProfileUserType}
              />
              {profileUserType === 'other' && (
                <div className="space-y-1">
                  <Label htmlFor="account-user-type-other">Other user type</Label>
                  <Input
                    id="account-user-type-other"
                    value={profileUserTypeOther}
                    onChange={(event) => setProfileUserTypeOther(event.target.value)}
                    required
                  />
                </div>
              )}
              <ProfileOptionButtonGroup
                legend="Gender"
                name="account-gender"
                options={GENDER_OPTIONS}
                value={profileGender}
                onChange={setProfileGender}
              />
              {profileGender === 'other' && (
                <div className="space-y-1">
                  <Label htmlFor="account-gender-other">Other gender</Label>
                  <Input
                    id="account-gender-other"
                    value={profileGenderOther}
                    onChange={(event) => setProfileGenderOther(event.target.value)}
                    required
                  />
                </div>
              )}
              <ProfileOptionCheckboxGroup
                legend="User types you want to see"
                description="Used for discovery and matching. You can update this at any time."
                name="account-preferred-user-types"
                options={USER_TYPE_OPTIONS}
                values={preferredUserTypes}
                onChange={setPreferredUserTypes}
              />
              <ProfileOptionCheckboxGroup
                legend="Genders you want to see"
                description="Used for discovery and matching. You can update this at any time."
                name="account-preferred-genders"
                options={GENDER_OPTIONS}
                values={preferredGenders}
                onChange={setPreferredGenders}
              />
              <Button type="submit" disabled={profileSaving}>
                {profileSaving ? 'Saving...' : 'Save profile'}
              </Button>
            </form>
          </CardContent>
        </Card>

        {interestsEnabled && (
          <>
            <Card>
              <CardHeader>
                <CardTitle>Your interests</CardTitle>
                <CardDescription>
                  Rate interests from -10 (fully uninterested) to +10 (fully interested). Characters inherit these unless you set custom interests for them.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <InterestRatingList
                  interests={interests}
                  onSave={handleSaveInterestRating}
                  onClear={handleClearInterestRating}
                />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Request a new interest</CardTitle>
                <CardDescription>Suggest an interest for an admin to review and add to the catalog.</CardDescription>
              </CardHeader>
              <CardContent>
                <RequestInterestForm interests={interests} />
              </CardContent>
            </Card>
          </>
        )}
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Account Settings</h2>
        <Card>
          <CardHeader>
            <CardTitle>Account information</CardTitle>
          </CardHeader>
          <CardContent>
            {accountError && (
              <Alert variant="destructive" className="mb-4">
                <AlertDescription>{accountError}</AlertDescription>
              </Alert>
            )}
            {accountMessage && (
              <Alert className="mb-4">
                <AlertDescription>{accountMessage}</AlertDescription>
              </Alert>
            )}
            <form className="space-y-4" onSubmit={(event) => void handleAccountSubmit(event)}>
              <div className="space-y-1">
                <Label htmlFor="account-name">Real name</Label>
                <Input
                  id="account-name"
                  value={accountName}
                  disabled={nameLocked}
                  onChange={(event) => setAccountName(event.target.value)}
                  autoComplete="name"
                  required
                />
                <p className="text-xs text-muted-foreground">
                  Used for account review and ID verification. Your real name is never displayed to others.
                </p>
              </div>
              <div className="space-y-1">
                <Label>Birth date</Label>
                <p className="rounded-md border border-input bg-muted/40 px-3 py-2 text-sm">
                  {accountBirthDate || 'Not provided'}
                </p>
                <p className="text-xs text-muted-foreground">
                  Used to verify age eligibility and never displayed to others. Contact an administrator if this date is incorrect.
                </p>
              </div>
              <div className="space-y-1">
                <Label htmlFor="account-email">Email</Label>
                <Input
                  id="account-email"
                  type="email"
                  value={accountEmail}
                  disabled={emailLocked}
                  onChange={(event) => setAccountEmail(event.target.value)}
                  autoComplete="email"
                  required
                />
              </div>
              <p className="text-sm text-muted-foreground">
                {nameLocked ? 'Real name is locked by an administrator.' : 'You can edit your real name.'}
              </p>
              <p className="text-sm text-muted-foreground">
                {emailLocked ? 'Email is locked by an administrator.' : 'You can edit your email.'}
              </p>
              <div className="space-y-3 rounded-md border border-input p-3">
                <p className="text-sm font-medium">Follow email notifications</p>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={emailFollowRequestReceived}
                    onChange={(event) => setEmailFollowRequestReceived(event.target.checked)}
                  />
                  Email me when I receive a follow request
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={emailFollowRequestAccepted}
                    onChange={(event) => setEmailFollowRequestAccepted(event.target.checked)}
                  />
                  Email me when one of my follow requests is accepted
                </label>
                <p className="text-xs text-muted-foreground">Declined follow requests do not send email.</p>
              </div>
              <p className="text-sm text-muted-foreground">
                ID verification: {accountVerificationDate ? `Verified (${new Date(accountVerificationDate).toLocaleString()})` : 'Not verified yet'}
              </p>
              <Button type="submit" disabled={accountSaving}>
                {accountSaving ? 'Saving...' : 'Save account settings'}
              </Button>
            </form>
          </CardContent>
        </Card>

        {passkeyMessage && (
          <Alert>
            <AlertDescription>{passkeyMessage}</AlertDescription>
          </Alert>
        )}
        <PasskeySection
          components={getAuthComponents()}
          onSuccess={(message) => setPasskeyMessage(message)}
          onError={(_field, message) => setPasskeyMessage(message)}
        />

        <Card>
          <CardHeader>
            <CardTitle>Password</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {passwordError && (
              <Alert variant="destructive">
                <AlertDescription>{passwordError}</AlertDescription>
              </Alert>
            )}
            {passwordMessage && (
              <Alert>
                <AlertDescription>{passwordMessage}</AlertDescription>
              </Alert>
            )}
            <ChangePasswordForm
              components={getAuthComponents()}
              onSuccess={(result) => {
                setPasswordMessage(result.message ?? 'Password changed successfully.');
                setPasswordError('');
              }}
              onError={(message) => {
                setPasswordError(message);
                setPasswordMessage('');
              }}
            />
          </CardContent>
        </Card>
      </section>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('user-settings');
if (mountEl) {
  createRoot(mountEl).render(<UserSettingsPage />);
}
