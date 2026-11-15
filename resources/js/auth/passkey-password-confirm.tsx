import { type FormEvent, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

/**
 * Adding a passkey creates a new way to sign in, so the server asks for a fresh
 * password confirmation first (POST /api/auth/confirm-password). The confirmation
 * lasts for the session's password timeout.
 */
export function PasskeyPasswordConfirm() {
  const [password, setPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await fetchWrapper.post('/api/auth/confirm-password', { password });
      setConfirmed(true);
      setPassword('');
    } catch (failure) {
      setError(typeof failure === 'string' ? failure : 'That password could not be confirmed.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Confirm your password</CardTitle>
        <CardDescription>Enter your current password before adding a passkey.</CardDescription>
      </CardHeader>
      <CardContent>
        {confirmed ? (
          <Alert><AlertDescription>Password confirmed. You can add a passkey now.</AlertDescription></Alert>
        ) : (
          <form className="space-y-2" onSubmit={(event) => void handleSubmit(event)}>
            <Label htmlFor="passkey-current-password">Current password</Label>
            <Input
              id="passkey-current-password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <Button type="submit" variant="outline" disabled={saving || password === ''}>
              {saving ? 'Confirming…' : 'Confirm password'}
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
