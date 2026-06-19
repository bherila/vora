import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { DatePicker } from '@/components/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

function getAdultBirthDateLimit(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 18);

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

interface RegisterInitialData {
  public_signups_enabled: boolean;
  invite: string | null;
  invite_valid: boolean;
  inviter_name: string | null;
  locked_email: string | null;
}

function getInitialData(): RegisterInitialData {
  const parsed = readInitialData<{ register?: Partial<RegisterInitialData> }>().register;
  return {
    public_signups_enabled: parsed?.public_signups_enabled !== false,
    invite: typeof parsed?.invite === 'string' ? parsed.invite : null,
    invite_valid: parsed?.invite_valid === true,
    inviter_name: typeof parsed?.inviter_name === 'string' ? parsed.inviter_name : null,
    locked_email: typeof parsed?.locked_email === 'string' ? parsed.locked_email : null,
  };
}

function RegisterPage() {
  const initialData = getInitialData();
  // When public signups are closed, registration is only possible with a valid
  // invite link. A missing/invalid invite blocks the form entirely.
  const inviteOnly = !initialData.public_signups_enabled;
  const formBlocked = inviteOnly && !initialData.invite_valid;
  const [name, setName] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [birthDate, setBirthDate] = useState('');
  // A waitlist-admit invite binds the account to the already-verified email.
  const emailLocked = initialData.locked_email !== null;
  const [email, setEmail] = useState(initialData.locked_email ?? '');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const adultBirthDateLimit = getAdultBirthDateLimit();

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();

    if (formBlocked) {
      return;
    }

    setLoading(true);
    setError('');

    if (password !== passwordConfirmation) {
      setError('Passwords do not match.');
      setLoading(false);
      return;
    }

    if (password.length < 8) {
      setError('Password must be at least 8 characters long.');
      setLoading(false);
      return;
    }

    if (!displayName.trim()) {
      setError('Display name is required.');
      setLoading(false);
      return;
    }

    if (!birthDate) {
      setError('Birth date is required.');
      setLoading(false);
      return;
    }

    if (birthDate > adultBirthDateLimit) {
      setError('You must be at least 18 years old to sign up.');
      setLoading(false);
      return;
    }

    try {
      const response = await fetchWrapper.post('/api/auth/register', {
        name: name.trim(),
        display_name: displayName.trim(),
        birth_date: birthDate,
        email,
        password,
        password_confirmation: passwordConfirmation,
        invite: initialData.invite ?? undefined,
      });

      if (response.success && response.redirect) {
        window.location.href = response.redirect;
      } else if (response.redirect) {
        window.location.href = response.redirect;
      } else if (response.success) {
        window.location.href = '/dashboard';
      } else {
        setError(response.message || 'Registration failed. Please try again.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'An error occurred. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Create Account</CardTitle>
          <CardDescription>Sign up to get started</CardDescription>
        </CardHeader>
        <CardContent>
          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {formBlocked && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>
                {initialData.invite
                  ? 'This invite link is invalid or has expired. Ask whoever invited you for a new one.'
                  : 'Public sign-ups are currently closed. You need a valid invite link to join.'}
              </AlertDescription>
            </Alert>
          )}

          {!formBlocked && initialData.invite_valid && initialData.inviter_name && (
            <Alert className="mb-4">
              <AlertDescription>Invited by {initialData.inviter_name}.</AlertDescription>
            </Alert>
          )}

          <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">Real name</Label>
              <Input
                id="name"
                type="text"
                placeholder="John Doe"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                autoComplete="name"
              />
              <p className="text-xs text-muted-foreground">
                Used for account review and ID verification. Your real name is never displayed to others.
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="display_name">Display name</Label>
              <Input
                id="display_name"
                type="text"
                placeholder="How others should see you"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                required
                autoComplete="nickname"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="birth_date">Birth date</Label>
              <DatePicker
                id="birth_date"
                value={birthDate}
                max={adultBirthDateLimit}
                onChange={setBirthDate}
                placeholder="Select birth date"
              />
              <p className="text-xs text-muted-foreground">
                Used to verify age eligibility and never displayed to others. You cannot change this later. ID verification will be required after signup before age-restricted content is available; if verification fails, you will need to delete this account and start over.
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                type="email"
                placeholder="you@example.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                readOnly={emailLocked}
                disabled={emailLocked}
              />
              <p className="text-xs text-muted-foreground">
                {emailLocked
                  ? 'This invitation is tied to the email you verified, so it cannot be changed here.'
                  : 'Use an email address you can access. We will send verification and two-factor authentication messages there.'}
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
                autoComplete="new-password"
              />
              <p className="text-xs text-muted-foreground">Must be at least 8 characters long</p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="password_confirmation">Confirm Password</Label>
              <Input
                id="password_confirmation"
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                required
                minLength={8}
                autoComplete="new-password"
              />
            </div>
            <Button type="submit" className="w-full" disabled={loading || formBlocked}>
              {loading ? 'Creating account...' : 'Create Account'}
            </Button>
          </form>

          <div className="mt-6 text-center text-sm">
            Already have an account?{' '}
            <a href="/login" className="text-primary hover:underline">
              Sign in
            </a>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('register');
if (mountEl) {
  createRoot(mountEl).render(<RegisterPage />);
}
