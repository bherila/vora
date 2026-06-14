import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
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
  const [profileSaving, setProfileSaving] = useState(false);
  const [accountSaving, setAccountSaving] = useState(false);
  const [passkeyMessage, setPasskeyMessage] = useState('');
  const [profileMessage, setProfileMessage] = useState('');
  const [profileError, setProfileError] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [accountError, setAccountError] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');

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
  });

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
    </div>
  );
}

const mountEl = document.getElementById('user-settings');
if (mountEl) {
  createRoot(mountEl).render(<UserSettingsPage />);
}
