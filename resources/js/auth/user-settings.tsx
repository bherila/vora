import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

import { getAuthComponents } from './shared-components';

interface UserSettingsInitialData {
  name: string;
  email: string;
  id_verified_at: string | null;
  name_locked: boolean;
  email_locked: boolean;
}

interface UserSettingsResponse {
  success: boolean;
  message?: string;
  data?: {
    name: string;
    email: string;
  };
}

function getInitialData(): UserSettingsInitialData {
  const element = document.getElementById('user-settings-initial-data');
  if (!element || !element.textContent) {
    return {
      name: '',
      email: '',
      id_verified_at: null,
      name_locked: false,
      email_locked: false,
    };
  }

  try {
    const parsed = JSON.parse(element.textContent) as UserSettingsInitialData;
    return {
      name: parsed.name ?? '',
      email: parsed.email ?? '',
      id_verified_at: parsed.id_verified_at ?? null,
      name_locked: parsed.name_locked ?? false,
      email_locked: parsed.email_locked ?? false,
    };
  } catch {
    return {
      name: '',
      email: '',
      id_verified_at: null,
      name_locked: false,
      email_locked: false,
    };
  }
}

function UserSettingsPage() {
  const initialData = getInitialData();

  const [accountName, setAccountName] = useState(initialData.name);
  const [accountEmail, setAccountEmail] = useState(initialData.email);
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
    const email = accountEmail.trim();
    if (!name || !email) {
      setAccountError('Name and email are required.');
      return;
    }

    setAccountSaving(true);
    setAccountError('');
    setAccountMessage('');

    try {
      const response = await fetchWrapper.put('/api/account', {
        name,
        email,
      }) as UserSettingsResponse;

      setAccountMessage(response.message ?? 'Account updated.');
      if (response.data) {
        setAccountName(response.data.name);
        setAccountEmail(response.data.email);
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
              <Label htmlFor="account-name">Name</Label>
              <Input
                id="account-name"
                value={accountName}
                disabled={nameLocked}
                onChange={(event) => setAccountName(event.target.value)}
                autoComplete="name"
                required
              />
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
              {nameLocked ? 'Name is locked by an administrator.' : 'You can edit your name.'}
            </p>
            <p className="text-sm text-muted-foreground">
              {emailLocked ? 'Email is locked by an administrator.' : 'You can edit your email.'}
            </p>
            <p className="text-sm text-muted-foreground">
              ID verification: {accountVerificationDate ? `Verified (${new Date(accountVerificationDate).toLocaleString()})` : 'Not verified yet'}
            </p>
            <Button type="submit" disabled={accountSaving || (nameLocked && emailLocked)}>
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
