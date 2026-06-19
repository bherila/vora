import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { DatePicker } from '@/components/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

function getAdultBirthDateLimit(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 18);

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

interface WaitlistGeo {
  country?: string;
  city?: string;
  region?: string;
  postal?: string;
  latitude?: string;
  longitude?: string;
}

interface WaitlistInitialData {
  ip: string | null;
  geo: WaitlistGeo;
}

function getInitialData(): WaitlistInitialData {
  const fallback: WaitlistInitialData = { ip: null, geo: {} };

  const element = document.getElementById('waitlist-initial-data');
  if (!element?.textContent) {
    return fallback;
  }

  try {
    const parsed = JSON.parse(element.textContent) as Partial<WaitlistInitialData>;
    return {
      ip: typeof parsed.ip === 'string' ? parsed.ip : null,
      geo: parsed.geo && typeof parsed.geo === 'object' ? (parsed.geo as WaitlistGeo) : {},
    };
  } catch {
    return fallback;
  }
}

function formatLocation(geo: WaitlistGeo): string {
  const parts = [geo.city, geo.region, geo.country].filter((part): part is string => Boolean(part));
  return parts.length > 0 ? parts.join(', ') : 'Unknown';
}

function RequestInvitationPage() {
  const initialData = getInitialData();
  const adultBirthDateLimit = getAdultBirthDateLimit();

  const [email, setEmail] = useState('');
  const [birthDate, setBirthDate] = useState('');
  const [interests, setInterests] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  // Post-submit verification step.
  const [submittedUuid, setSubmittedUuid] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [verifyError, setVerifyError] = useState('');
  const [verified, setVerified] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    if (!birthDate) {
      setError('Birth date is required.');
      setLoading(false);
      return;
    }

    if (birthDate > adultBirthDateLimit) {
      setError('You must be at least 18 years old to request an invitation.');
      setLoading(false);
      return;
    }

    if (interests.trim().length < 20) {
      setError('Please write a few sentences about your interests and why you want to join.');
      setLoading(false);
      return;
    }

    try {
      const response = await fetchWrapper.post('/api/waitlist', {
        email: email.trim(),
        birth_date: birthDate,
        interests: interests.trim(),
      });

      if (response.success && response.data?.uuid) {
        setSubmittedUuid(response.data.uuid as string);
      } else {
        setError(response.message || 'Could not submit your request. Please try again.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'An error occurred. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerify = async (e: FormEvent) => {
    e.preventDefault();
    if (!submittedUuid) {
      return;
    }
    setVerifying(true);
    setVerifyError('');

    try {
      const response = await fetchWrapper.post('/api/waitlist/verify', {
        uuid: submittedUuid,
        code: code.trim(),
      });

      if (response.success) {
        setVerified(true);
      } else {
        setVerifyError(response.message || 'That code is incorrect or has expired.');
      }
    } catch (err) {
      setVerifyError(typeof err === 'string' ? err : 'That code is incorrect or has expired.');
    } finally {
      setVerifying(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Request an invitation</CardTitle>
          <CardDescription>
            {submittedUuid
              ? 'Check your email to verify your request.'
              : 'This is a private community. Tell us a little about yourself to request an invitation.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {verified ? (
            <Alert>
              <AlertDescription>
                Your email is verified. We&apos;ll review your request and email you an invitation if you&apos;re
                approved.
              </AlertDescription>
            </Alert>
          ) : submittedUuid ? (
            <form onSubmit={(e) => void handleVerify(e)} className="space-y-4">
              {verifyError && (
                <Alert variant="destructive">
                  <AlertDescription>{verifyError}</AlertDescription>
                </Alert>
              )}
              <p className="text-sm text-muted-foreground">
                We emailed a verification link and a 6-digit code to <strong>{email.trim()}</strong>. Click the link
                or enter the code below.
              </p>
              <div className="space-y-2">
                <Label htmlFor="code">Verification code</Label>
                <Input
                  id="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  required
                />
              </div>
              <Button type="submit" className="w-full" disabled={verifying}>
                {verifying ? 'Verifying...' : 'Verify email'}
              </Button>
            </form>
          ) : (
            <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
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
                  We&apos;ll email a verification link here, then an invitation if you&apos;re approved.
                </p>
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
                <p className="text-xs text-muted-foreground">You must be at least 18 years old to join.</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="interests">About you</Label>
                <Textarea
                  id="interests"
                  rows={5}
                  placeholder="Write a few sentences about your interests and why you'd like to join."
                  value={interests}
                  onChange={(e) => setInterests(e.target.value)}
                  required
                  minLength={20}
                  maxLength={2000}
                />
              </div>
              <div className="rounded-md border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                <p>
                  For security, we record where this request comes from:
                </p>
                <p className="mt-1">
                  IP address: <span className="font-medium text-foreground">{initialData.ip ?? 'Unknown'}</span>
                </p>
                <p>
                  Location: <span className="font-medium text-foreground">{formatLocation(initialData.geo)}</span>
                </p>
              </div>
              <Button type="submit" className="w-full" disabled={loading}>
                {loading ? 'Submitting...' : 'Request invitation'}
              </Button>
            </form>
          )}

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

const mountEl = document.getElementById('request-invitation');
if (mountEl) {
  createRoot(mountEl).render(<RequestInvitationPage />);
}
