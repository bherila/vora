import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { DatePicker } from '@/components/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

function getAdultBirthDateLimit(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 18);

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function RegisterPage() {
  const [name, setName] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [birthDate, setBirthDate] = useState('');
  const [email, setEmail] = useState('');
  const [gender, setGender] = useState('');
  const [genderOther, setGenderOther] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const isOtherGender = gender === 'other';
  const adultBirthDateLimit = getAdultBirthDateLimit();

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
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

    if (!['m', 'f', 'other'].includes(gender)) {
      setError('Please choose a gender option.');
      setLoading(false);
      return;
    }

    if (isOtherGender && genderOther.trim().length === 0) {
      setError('Please specify your gender when choosing Other.');
      setLoading(false);
      return;
    }

    try {
      const response = await fetchWrapper.post('/api/auth/register', {
        name: name.trim(),
        display_name: displayName.trim(),
        birth_date: birthDate,
        email,
        gender,
        gender_other: isOtherGender ? genderOther.trim() : '',
        password,
        password_confirmation: passwordConfirmation,
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
              />
              <p className="text-xs text-muted-foreground">
                Use an email address you can access. We will send verification and two-factor authentication messages there.
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="gender">Gender</Label>
              <select
                id="gender"
                value={gender}
                onChange={(e) => setGender(e.target.value)}
                required
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="">Select gender</option>
                <option value="m">M</option>
                <option value="f">F</option>
                <option value="other">Other</option>
              </select>
            </div>
            {isOtherGender && (
              <div className="space-y-2">
                <Label htmlFor="gender_other">Other</Label>
                <Input
                  id="gender_other"
                  type="text"
                  placeholder="Prefer to self-describe"
                  value={genderOther}
                  onChange={(e) => setGenderOther(e.target.value)}
                  required
                />
              </div>
            )}

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
            <Button type="submit" className="w-full" disabled={loading}>
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
