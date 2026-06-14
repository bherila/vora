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
    return { name: '', email: '' };
  }

  try {
    const parsed = JSON.parse(element.textContent) as UserSettingsInitialData;
    return {
      name: parsed.name ?? '',
      email: parsed.email ?? '',
    };
  } catch {
    return { name: '', email: '' };
  }
}

function UserSettingsPage() {
  const initialData = getInitialData();

  const [accountName, setAccountName] = useState(initialData.name);
  const [accountEmail, setAccountEmail] = useState(initialData.email);
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
                onChange={(event) => setAccountEmail(event.target.value)}
                autoComplete="email"
                required
              />
            </div>
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
