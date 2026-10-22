import { type FormEvent, useEffect, useRef, useState } from 'react';

import { UploadProgress } from '@/components/media/UploadProgress';
import { RestrictionNotice } from '@/components/restriction-notice';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { putToSignedUrl } from '@/media/upload';
import { postUpload } from '@/media/uploadRequest';
import { activeRestriction } from '@/restrictions';

interface AccountIdentitySettingsProps {
  displayName: string;
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : typeof error === 'string' ? error : 'The change could not be saved.';
}

/** Core account identity remains editable when the social profile module is off. */
export function AccountIdentitySettings({ displayName: initialName }: AccountIdentitySettingsProps) {
  const [displayName, setDisplayName] = useState(initialName);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const controllerRef = useRef<AbortController | null>(null);
  const restriction = activeRestriction('media.upload');
  useEffect(() => () => controllerRef.current?.abort(), []);

  const saveName = async (submit: FormEvent): Promise<void> => {
    submit.preventDefault();
    setSaving(true);
    setMessage('');
    setError('');
    try {
      const response = await fetchWrapper.patch('/api/account', { display_name: displayName.trim() }) as { data: { display_name: string } };
      setDisplayName(response.data.display_name);
      setMessage('Display name updated.');
    } catch (error) { setError(errorMessage(error)); }
    finally { setSaving(false); }
  };

  const uploadPhoto = async (file: File): Promise<void> => {
    if (!file.type.startsWith('image/')) { setError('Choose an image for your profile picture.'); return; }
    const controller = new AbortController();
    controllerRef.current = controller;
    let pendingId: number | null = null;
    setUploading(true);
    setProgress(0);
    setError('');
    setMessage('');
    try {
      const created = await postUpload<{ data: { id: number }; upload_url: string; upload_headers: Record<string, string> }>('/api/account/profile-picture', {
        filename: file.name, content_type: file.type, size: file.size,
      }, controller.signal);
      pendingId = created.data.id;
      await putToSignedUrl(created.upload_url, file, created.upload_headers, (fraction) => setProgress(fraction * 100), { signal: controller.signal });
      await postUpload(`/api/account/profile-picture/${pendingId}/complete`, {}, controller.signal);
      pendingId = null;
      setMessage('Profile picture uploaded. An administrator reviews new images before other attendees can see them.');
    } catch (error) {
      if (pendingId !== null) {
        try { await postUpload(`/api/media/${pendingId}/cancel-upload`, {}); }
        catch { /* A completed image is protected; pending uploads are also cleaned up server-side. */ }
      }
      setError(error instanceof DOMException && error.name === 'AbortError' ? 'Profile picture upload canceled.' : errorMessage(error));
    } finally { setUploading(false); controllerRef.current = null; }
  };

  const removePhoto = async (): Promise<void> => {
    setSaving(true);
    setMessage('');
    setError('');
    try {
      await fetchWrapper.delete('/api/account/profile-picture', {});
      setMessage('Profile picture removed.');
    } catch (error) { setError(errorMessage(error)); }
    finally { setSaving(false); }
  };

  return <Card>
    <CardHeader><CardTitle>Your display name and photo</CardTitle><CardDescription>These identify you to other attendees. Your real name and email remain private.</CardDescription></CardHeader>
    <CardContent className="space-y-4">
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      {message && <p role="status" className="text-sm">{message}</p>}
      <form className="grid gap-2" onSubmit={(submit) => void saveName(submit)}>
        <Label htmlFor="settings-display-name">Display name</Label><Input id="settings-display-name" required maxLength={255} value={displayName} onChange={(event) => setDisplayName(event.target.value)} disabled={saving} />
        <Button className="w-fit" disabled={saving || uploading}>{saving ? 'Saving…' : 'Save display name'}</Button>
      </form>
      {restriction ? <RestrictionNotice restriction={restriction} /> : <div className="grid gap-2"><Label htmlFor="settings-profile-photo">Profile picture</Label><Input id="settings-profile-photo" type="file" accept="image/*" disabled={saving || uploading} onChange={(event) => {
        const file = event.target.files?.[0];
        if (file) void uploadPhoto(file);
        event.target.value = '';
      }} /></div>}
      {uploading && <UploadProgress label="Uploading profile picture…" progress={progress} onCancel={() => controllerRef.current?.abort()} />}
      <Button variant="outline" disabled={saving || uploading} onClick={() => void removePhoto()}>Remove current picture</Button>
    </CardContent>
  </Card>;
}
