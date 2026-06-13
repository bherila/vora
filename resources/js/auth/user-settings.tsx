import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';

import { getAuthComponents } from './shared-components';

function UserSettingsPage() {
  const [passkeyMessage, setPasskeyMessage] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');

  return (
    <div className="mx-auto max-w-2xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Settings</h1>

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
