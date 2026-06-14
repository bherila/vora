import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { ProfileOptionCheckboxGroup } from '@/components/profile-option-checkbox-group';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import {
  GENDER_OPTIONS,
  hasProfileOptionValue,
  normalizeProfileSelections,
  USER_TYPE_OPTIONS,
} from '@/profile-options';

import { getAuthComponents } from './shared-components';

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
    gender: string;
    gender_other: string | null;
    user_type: string;
    user_type_other: string | null;
    preferred_user_types: string[];
    preferred_genders: string[];
  };
}

function getInitialData(): UserSettingsInitialData {
  const element = document.getElementById('user-settings-initial-data');
  if (!element || !element.textContent) {
    return {
      name: '',
      display_name: '',
      birth_date: '',
      email: '',
      gender: 'male',
      gender_other: '',
      user_type: 'human',
      user_type_other: '',
      preferred_user_types: normalizeProfileSelections(USER_TYPE_OPTIONS, null),
      preferred_genders: normalizeProfileSelections(GENDER_OPTIONS, null),
      id_verified_at: null,
      name_locked: false,
      email_locked: false,
    };
  }

  try {
    const parsed = JSON.parse(element.textContent) as UserSettingsInitialData;
    return {
      name: parsed.name ?? '',
      display_name: parsed.display_name ?? '',
      birth_date: parsed.birth_date ?? '',
      email: parsed.email ?? '',
      gender: parsed.gender ?? 'male',
      gender_other: parsed.gender_other ?? '',
      user_type: parsed.user_type ?? 'human',
      user_type_other: parsed.user_type_other ?? '',
      preferred_user_types: normalizeProfileSelections(USER_TYPE_OPTIONS, parsed.preferred_user_types),
      preferred_genders: normalizeProfileSelections(GENDER_OPTIONS, parsed.preferred_genders),
      id_verified_at: parsed.id_verified_at ?? null,
      name_locked: parsed.name_locked ?? false,
      email_locked: parsed.email_locked ?? false,
    };
  } catch {
    return {
      name: '',
      display_name: '',
      birth_date: '',
      email: '',
      gender: 'male',
      gender_other: '',
      user_type: 'human',
      user_type_other: '',
      preferred_user_types: normalizeProfileSelections(USER_TYPE_OPTIONS, null),
      preferred_genders: normalizeProfileSelections(GENDER_OPTIONS, null),
      id_verified_at: null,
      name_locked: false,
      email_locked: false,
    };
  }
}

function UserSettingsPage() {
  const initialData = getInitialData();

  const [accountName, setAccountName] = useState(initialData.name);
  const [accountDisplayName, setAccountDisplayName] = useState(initialData.display_name);
  const [accountBirthDate] = useState(initialData.birth_date);
  const [accountEmail, setAccountEmail] = useState(initialData.email);
  const [accountGender, setAccountGender] = useState(initialData.gender);
  const [accountGenderOther, setAccountGenderOther] = useState(initialData.gender_other);
  const [accountUserType, setAccountUserType] = useState(initialData.user_type);
  const [accountUserTypeOther, setAccountUserTypeOther] = useState(initialData.user_type_other);
  const [preferredUserTypes, setPreferredUserTypes] = useState(initialData.preferred_user_types);
  const [preferredGenders, setPreferredGenders] = useState(initialData.preferred_genders);
  const [accountVerificationDate] = useState(initialData.id_verified_at);
  const [nameLocked] = useState(initialData.name_locked);
  const [emailLocked] = useState(initialData.email_locked);
  const [accountSaving, setAccountSaving] = useState(false);
  const [passkeyMessage, setPasskeyMessage] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [accountError, setAccountError] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');

  const handleProfileSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const name = accountName.trim();
    const displayName = accountDisplayName.trim();
    const email = accountEmail.trim();
    if (!name || !displayName || !email) {
      setAccountError('Real name, display name, and email are required.');
      return;
    }

    if (!hasProfileOptionValue(USER_TYPE_OPTIONS, accountUserType)) {
      setAccountError('Please choose a user type.');
      return;
    }

    if (accountUserType === 'other' && accountUserTypeOther.trim().length === 0) {
      setAccountError('Please specify your user type when choosing Other.');
      return;
    }

    if (!hasProfileOptionValue(GENDER_OPTIONS, accountGender)) {
      setAccountError('Please choose a gender option.');
      return;
    }

    if (accountGender === 'other' && accountGenderOther.trim().length === 0) {
      setAccountError('Please specify your gender when choosing Other.');
      return;
    }

    if (preferredUserTypes.length === 0) {
      setAccountError('Please choose at least one user type you want to see.');
      return;
    }

    if (preferredGenders.length === 0) {
      setAccountError('Please choose at least one gender you want to see.');
      return;
    }

    setAccountSaving(true);
    setAccountError('');
    setAccountMessage('');

    try {
      const response = await fetchWrapper.patch('/api/account', {
        name,
        display_name: displayName,
        email,
        gender: accountGender,
        gender_other: accountGender === 'other' ? accountGenderOther.trim() : '',
        user_type: accountUserType,
        user_type_other: accountUserType === 'other' ? accountUserTypeOther.trim() : '',
        preferred_user_types: preferredUserTypes,
        preferred_genders: preferredGenders,
      }) as UserSettingsResponse;

      setAccountMessage(response.message ?? 'Account updated.');
      if (response.data) {
        setAccountName(response.data.name);
        setAccountDisplayName(response.data.display_name);
        setAccountEmail(response.data.email);
        setAccountGender(response.data.gender);
        setAccountGenderOther(response.data.gender_other ?? '');
        setAccountUserType(response.data.user_type);
        setAccountUserTypeOther(response.data.user_type_other ?? '');
        setPreferredUserTypes(response.data.preferred_user_types);
        setPreferredGenders(response.data.preferred_genders);
      }
    } catch (err) {
      setAccountError(typeof err === 'string' ? err : 'Failed to update account.');
    } finally {
      setAccountSaving(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Settings</h1>

      <Card>
        <CardHeader>
          <CardTitle>Account information</CardTitle>
        </CardHeader>
        <CardContent>
          {accountError && (
            <Alert variant="destructive">
              <AlertDescription>{accountError}</AlertDescription>
            </Alert>
          )}
          {accountMessage && (
            <Alert>
              <AlertDescription>{accountMessage}</AlertDescription>
            </Alert>
          )}
          <form className="space-y-4" onSubmit={(event) => void handleProfileSubmit(event)}>
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
              <Label htmlFor="account-display-name">Display name</Label>
              <Input
                id="account-display-name"
                value={accountDisplayName}
                onChange={(event) => setAccountDisplayName(event.target.value)}
                autoComplete="nickname"
                required
              />
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
            <div className="space-y-1">
              <Label htmlFor="account-user-type">User type</Label>
              <select
                id="account-user-type"
                value={accountUserType}
                onChange={(event) => setAccountUserType(event.target.value)}
                required
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                {USER_TYPE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
            {accountUserType === 'other' && (
              <div className="space-y-1">
                <Label htmlFor="account-user-type-other">Other user type</Label>
                <Input
                  id="account-user-type-other"
                  value={accountUserTypeOther}
                  onChange={(event) => setAccountUserTypeOther(event.target.value)}
                  required
                />
              </div>
            )}
            <div className="space-y-1">
              <Label htmlFor="account-gender">Gender</Label>
              <select
                id="account-gender"
                value={accountGender}
                onChange={(event) => setAccountGender(event.target.value)}
                required
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                {GENDER_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
            {accountGender === 'other' && (
              <div className="space-y-1">
                <Label htmlFor="account-gender-other">Other gender</Label>
                <Input
                  id="account-gender-other"
                  value={accountGenderOther}
                  onChange={(event) => setAccountGenderOther(event.target.value)}
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
              {accountSaving ? 'Saving…' : 'Save account details'}
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

      <div className="space-y-2">
        <h2 className="text-lg font-semibold">Change Password</h2>
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
      </div>
    </div>
  );
}

const mountEl = document.getElementById('user-settings');
if (mountEl) {
  createRoot(mountEl).render(<UserSettingsPage />);
}
