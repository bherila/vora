import { ChangePasswordForm, PasskeySection } from 'bwh-auth';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { ProfileOptionButtonGroup, ProfileOptionCheckboxGroup } from '@/components/profile-option-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { loadInterests, persistRatings } from '@/interests/api';
import { InterestRatingList, type RatableInterest } from '@/interests/interest-rating-list';
import { RequestInterestForm } from '@/interests/request-interest-form';
import { type Audience } from '@/lib/audience';
import type { MediaItem } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';
import {
  GENDER_OPTIONS,
  normalizeProfileOptionValue,
  normalizeProfileSelections,
  USER_TYPE_OPTIONS,
} from '@/profile-options';
import {
  currentBrowserPushSubscription,
  isWebPushSupported,
  subscribeBrowserToWebPush,
  unsubscribeBrowserFromWebPush,
} from '@/push';

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
  profile_audience?: string | null;
  audience_user_ids?: unknown;
  id_verified_at?: string | null;
  name_locked?: boolean | null;
  email_locked?: boolean | null;
  notify_new_post?: boolean | null;
  notify_post_reaction?: boolean | null;
  notify_post_comment?: boolean | null;
  notify_follow_request?: boolean | null;
  notify_follow_accepted?: boolean | null;
  notify_co_author_invite?: boolean | null;
  notify_co_author_invite_accepted?: boolean | null;
  web_push_public_key?: string | null;
  web_push_subscription_count?: number | null;
  can_manage_interests?: boolean | null;
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
  profile_audience: Audience;
  audience_user_ids: number[];
  id_verified_at: string | null;
  name_locked: boolean;
  email_locked: boolean;
  notify_new_post: boolean;
  notify_post_reaction: boolean;
  notify_post_comment: boolean;
  notify_follow_request: boolean;
  notify_follow_accepted: boolean;
  notify_co_author_invite: boolean;
  notify_co_author_invite_accepted: boolean;
  web_push_public_key: string;
  web_push_subscription_count: number;
  can_manage_interests: boolean;
}

interface ProfilePictureUploadResponse {
  success: boolean;
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
}

interface ProfilePictureCompleteResponse {
  success: boolean;
  data: MediaItem;
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
    profile_audience: Audience;
    audience_user_ids: number[];
    notify_new_post: boolean;
    notify_post_reaction: boolean;
    notify_post_comment: boolean;
    notify_follow_request: boolean;
    notify_follow_accepted: boolean;
    notify_co_author_invite: boolean;
    notify_co_author_invite_accepted: boolean;
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
  profile_audience: Audience;
  audience_user_ids: number[];
  notify_new_post: boolean;
  notify_post_reaction: boolean;
  notify_post_comment: boolean;
  notify_follow_request: boolean;
  notify_follow_accepted: boolean;
  notify_co_author_invite: boolean;
  notify_co_author_invite_accepted: boolean;
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
    profile_audience: 'everyone',
    audience_user_ids: [],
    id_verified_at: null,
    name_locked: false,
    email_locked: false,
    notify_new_post: true,
    notify_post_reaction: true,
    notify_post_comment: true,
    notify_follow_request: true,
    notify_follow_accepted: true,
    notify_co_author_invite: true,
    notify_co_author_invite_accepted: true,
    web_push_public_key: '',
    web_push_subscription_count: 0,
    can_manage_interests: false,
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
    profile_audience: normalizeAudience(payload.profile_audience),
    audience_user_ids: normalizeNumberList(payload.audience_user_ids),
    id_verified_at: payload.id_verified_at ?? null,
    name_locked: payload.name_locked ?? false,
    email_locked: payload.email_locked ?? false,
    notify_new_post: payload.notify_new_post ?? true,
    notify_post_reaction: payload.notify_post_reaction ?? true,
    notify_post_comment: payload.notify_post_comment ?? true,
    notify_follow_request: payload.notify_follow_request ?? true,
    notify_follow_accepted: payload.notify_follow_accepted ?? true,
    notify_co_author_invite: payload.notify_co_author_invite ?? true,
    notify_co_author_invite_accepted: payload.notify_co_author_invite_accepted ?? true,
    web_push_public_key: payload.web_push_public_key ?? '',
    web_push_subscription_count: payload.web_push_subscription_count ?? 0,
    can_manage_interests: payload.can_manage_interests ?? false,
  };
}

function getInitialData(): UserSettingsInitialData {
  const payload = readInitialData<{ userSettings?: UserSettingsInitialPayload }>().userSettings;
  return payload ? normalizeInitialData(payload) : emptyInitialData();
}

function blankToNull(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

function selectionsToPayload(values: string[]): string[] | null {
  return values.length > 0 ? values : null;
}

function normalizeAudience(value: unknown): Audience {
  return value === 'followers' || value === 'mutuals' || value === 'specific' ? value : 'everyone';
}

function normalizeNumberList(value: unknown): number[] {
  return Array.isArray(value)
    ? value.filter((item): item is number => typeof item === 'number')
    : [];
}

function UserSettingsPage() {
  const initialData = getInitialData();

  const [settingsTab, setSettingsTab] = useState<'profile' | 'account'>('profile');
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
  const [profileAudience, setProfileAudience] = useState(initialData.profile_audience);
  const [profileAudienceUserIds, setProfileAudienceUserIds] = useState(initialData.audience_user_ids);
  const [accountVerificationDate] = useState(initialData.id_verified_at);
  const [nameLocked] = useState(initialData.name_locked);
  const [emailLocked] = useState(initialData.email_locked);
  const [notifyNewPost, setNotifyNewPost] = useState(initialData.notify_new_post);
  const [notifyPostReaction, setNotifyPostReaction] = useState(initialData.notify_post_reaction);
  const [notifyPostComment, setNotifyPostComment] = useState(initialData.notify_post_comment);
  const [notifyFollowRequest, setNotifyFollowRequest] = useState(initialData.notify_follow_request);
  const [notifyFollowAccepted, setNotifyFollowAccepted] = useState(initialData.notify_follow_accepted);
  const [notifyCoAuthorInvite, setNotifyCoAuthorInvite] = useState(initialData.notify_co_author_invite);
  const [notifyCoAuthorInviteAccepted, setNotifyCoAuthorInviteAccepted] = useState(initialData.notify_co_author_invite_accepted);
  const [pushSupported, setPushSupported] = useState(false);
  const [pushPermission, setPushPermission] = useState<NotificationPermission>('default');
  const [pushSubscribed, setPushSubscribed] = useState(false);
  // True until the browser/server ownership check resolves. The enable path
  // unsubscribes any existing browser subscription first, so the button must stay
  // disabled until we know whether this device is already subscribed — otherwise a
  // premature Enable click could destroy a valid subscription mid-check.
  const [pushStatusLoading, setPushStatusLoading] = useState(true);
  const [pushSubscriptionCount, setPushSubscriptionCount] = useState(initialData.web_push_subscription_count);
  const [pushSaving, setPushSaving] = useState(false);
  const [pushMessage, setPushMessage] = useState('');
  const [pushError, setPushError] = useState('');
  const [profileSaving, setProfileSaving] = useState(false);
  const [accountSaving, setAccountSaving] = useState(false);
  const [passkeyMessage, setPasskeyMessage] = useState('');
  const [profileMessage, setProfileMessage] = useState('');
  const [profileError, setProfileError] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [accountError, setAccountError] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');
  const [profilePictureUploading, setProfilePictureUploading] = useState(false);
  const [profilePictureProgress, setProfilePictureProgress] = useState(0);
  const [profilePictureFiles, setProfilePictureFiles] = useState<File[]>([]);
  const profilePictureAbortRef = useRef<AbortController | null>(null);
  const [profilePictureMessage, setProfilePictureMessage] = useState('');
  const [profilePictureError, setProfilePictureError] = useState('');
  const [interests, setInterests] = useState<RatableInterest[]>([]);
  // Mirrors the /api/interests access gate. Changing the email clears email
  // verification server-side, which makes the API 403, so we drop access here
  // too rather than leave the panels interactive until a reload.
  const [interestsEnabled, setInterestsEnabled] = useState(initialData.can_manage_interests);

  useEffect(() => {
    // The interests API is gated behind the approval middleware (approved +
    // verified + not disabled), so only eligible users can load/rate them.
    // Other users still see the rest of Settings without a failing request.
    if (!interestsEnabled) {
      return;
    }

    let active = true;
    const load = async (): Promise<void> => {
      try {
        const { interests: loaded } = await loadInterests(null);
        if (active) {
          setInterests(loaded);
        }
      } catch (err) {
        toast.error(typeof err === 'string' ? err : 'Failed to load interests.');
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [interestsEnabled]);

  useEffect(() => {
    const supported = isWebPushSupported();
    setPushSupported(supported);
    setPushPermission(supported ? Notification.permission : 'denied');

    if (!supported) {
      setPushStatusLoading(false);
      return;
    }

    let active = true;
    currentBrowserPushSubscription()
      .then(async (subscription) => {
        if (!active) return;

        const endpoint = subscription?.endpoint ?? '';
        const status = await fetchWrapper.get(`/api/push-subscriptions${endpoint ? `?endpoint=${encodeURIComponent(endpoint)}` : ''}`).catch(() => null);
        const data = (status as { data?: { subscription_count?: number; endpoint_registered?: boolean } } | null)?.data;
        const serverCount = data?.subscription_count ?? 0;

        setPushSubscribed(subscription !== null && data?.endpoint_registered === true);
        setPushSubscriptionCount(serverCount);
      })
      .catch(() => {
        if (active) {
          setPushSubscribed(false);
        }
      })
      .finally(() => {
        if (active) {
          setPushStatusLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, []);

  const handleSaveInterestRating = async (interestId: number, level: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level }]);
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: level } : item)));
      toast.success('Interest rating saved.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to save interest rating.');
      throw err; // let the list keep the row pending for retry
    }
  };

  const handleClearInterestRating = async (interestId: number): Promise<void> => {
    try {
      await persistRatings(null, [{ interest_id: interestId, level: null }]);
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: null } : item)));
      toast.success('Interest rating cleared.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Failed to clear interest rating.');
    }
  };

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
    setProfileAudience(data.profile_audience);
    setProfileAudienceUserIds(data.audience_user_ids);
    setNotifyNewPost(data.notify_new_post);
    setNotifyPostReaction(data.notify_post_reaction);
    setNotifyPostComment(data.notify_post_comment);
    setNotifyFollowRequest(data.notify_follow_request);
    setNotifyFollowAccepted(data.notify_follow_accepted);
    setNotifyCoAuthorInvite(data.notify_co_author_invite);
    setNotifyCoAuthorInviteAccepted(data.notify_co_author_invite_accepted);
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
    profile_audience: profileAudience,
    audience_user_ids: profileAudience === 'specific' ? profileAudienceUserIds : [],
    notify_new_post: notifyNewPost,
    notify_post_reaction: notifyPostReaction,
    notify_post_comment: notifyPostComment,
    notify_follow_request: notifyFollowRequest,
    notify_follow_accepted: notifyFollowAccepted,
    notify_co_author_invite: notifyCoAuthorInvite,
    notify_co_author_invite_accepted: notifyCoAuthorInviteAccepted,
  });

  const handleProfilePictureChange = async (selectedFiles: File[]): Promise<void> => {
    const file = selectedFiles[0] ?? null;
    setProfilePictureFiles(file ? [file] : []);
    if (!file) {
      return;
    }

    if (!file.type.startsWith('image/')) {
      setProfilePictureError('Profile pictures must be images, not videos.');
      setProfilePictureMessage('');
      return;
    }

    const abortController = new AbortController();
    profilePictureAbortRef.current = abortController;
    setProfilePictureUploading(true);
    setProfilePictureProgress(0);
    setProfilePictureError('');
    setProfilePictureMessage('');

    try {
      const created = await fetchWrapper.post('/api/account/profile-picture', {
        filename: file.name,
        content_type: file.type,
        size: file.size,
      }) as ProfilePictureUploadResponse;

      await putToSignedUrl(created.upload_url, file, created.upload_headers, (fraction) => {
        setProfilePictureProgress(fraction * 100);
      }, { signal: abortController.signal });

      const completed = await fetchWrapper.post(`/api/account/profile-picture/${created.data.id}/complete`, {}) as ProfilePictureCompleteResponse;
      setProfilePictureMessage(completed.data.upload_status === 'ready'
        ? 'Profile picture uploaded and waiting for admin review.'
        : 'Profile picture upload started.');
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        setProfilePictureError('Profile picture upload canceled.');
      } else {
        setProfilePictureError(typeof err === 'string' ? err : 'Failed to upload profile picture.');
      }
    } finally {
      setProfilePictureUploading(false);
      profilePictureAbortRef.current = null;
    }
  };

  const handleRemoveProfilePicture = async (): Promise<void> => {
    setProfilePictureError('');
    setProfilePictureMessage('');
    try {
      await fetchWrapper.delete('/api/account/profile-picture', {});
      setProfilePictureFiles([]);
      setProfilePictureMessage('Profile picture removed.');
    } catch (err) {
      setProfilePictureError(typeof err === 'string' ? err : 'Failed to remove profile picture.');
    }
  };

  const handleDeactivateAccount = async (): Promise<void> => {
    if (!window.confirm('Deactivate your account? Other users will no longer be able to see you. You can reactivate any time by logging back in.')) {
      return;
    }
    try {
      await fetchWrapper.post('/api/account/deactivate', {});
      window.location.href = '/account/deactivated';
    } catch (err) {
      setAccountError(typeof err === 'string' ? err : 'Failed to deactivate account.');
    }
  };

  const handleDeleteAccount = async (): Promise<void> => {
    if (!window.confirm('Delete your account? This cannot be undone by you — only an administrator can restore or permanently remove it. You will be logged out.')) {
      return;
    }
    try {
      await fetchWrapper.post('/api/account/delete', {});
      window.location.href = '/login';
    } catch (err) {
      setAccountError(typeof err === 'string' ? err : 'Failed to delete account.');
    }
  };

  const handleExportAccount = (): void => {
    window.location.href = '/api/account/export';
  };

  const handleEnablePush = async (): Promise<void> => {
    setPushSaving(true);
    setPushMessage('');
    setPushError('');

    try {
      const count = await subscribeBrowserToWebPush(initialData.web_push_public_key);
      setPushSubscriptionCount(count);
      setPushPermission(Notification.permission);
      setPushSubscribed(true);
      setPushMessage('Browser push enabled for this device.');
    } catch (err) {
      setPushPermission(isWebPushSupported() ? Notification.permission : 'denied');
      setPushError(err instanceof Error ? err.message : 'Could not enable browser push.');
    } finally {
      setPushSaving(false);
    }
  };

  const handleDisablePush = async (): Promise<void> => {
    setPushSaving(true);
    setPushMessage('');
    setPushError('');

    try {
      const count = await unsubscribeBrowserFromWebPush();
      setPushSubscriptionCount(count);
      setPushSubscribed(false);
      setPushMessage('Browser push disabled for this device.');
    } catch (err) {
      setPushError(typeof err === 'string' ? err : 'Could not disable browser push.');
    } finally {
      setPushSaving(false);
    }
  };

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

      // Changing the email resets verification, so the interests API will 403
      // until the new address is verified — hide the panels immediately.
      if (email !== initialData.email) {
        setInterestsEnabled(false);
      }

      setAccountMessage(response.message ?? 'Account updated.');
      applyResponseData(response.data);
    } catch (err) {
      setAccountError(typeof err === 'string' ? err : 'Failed to update account.');
    } finally {
      setAccountSaving(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Settings</h1>

      <div className="flex flex-wrap gap-2" role="tablist" aria-label="Settings sections">
        <Button type="button" size="sm" variant={settingsTab === 'profile' ? 'default' : 'outline'} aria-pressed={settingsTab === 'profile'} onClick={() => setSettingsTab('profile')}>
          Profile
        </Button>
        <Button type="button" size="sm" variant={settingsTab === 'account' ? 'default' : 'outline'} aria-pressed={settingsTab === 'account'} onClick={() => setSettingsTab('account')}>
          Account &amp; security
        </Button>
      </div>

      {settingsTab === 'profile' && (
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
            <div className="mb-6 space-y-3 rounded-lg border border-border p-4">
              <div>
                <h3 className="font-medium">Profile picture</h3>
                <p className="text-sm text-muted-foreground">
                  Upload an image for your profile. It will be reviewed by an admin before other users can see it.
                </p>
              </div>
              {profilePictureError && (
                <Alert variant="destructive">
                  <AlertDescription>{profilePictureError}</AlertDescription>
                </Alert>
              )}
              {profilePictureMessage && (
                <Alert>
                  <AlertDescription>{profilePictureMessage}</AlertDescription>
                </Alert>
              )}
              <div className="space-y-2">
                <FileDropzone
                  accept="image/*"
                  files={profilePictureFiles}
                  label="Drop a profile image here"
                  onFilesChange={(nextFiles) => void handleProfilePictureChange(nextFiles)}
                  disabled={profilePictureUploading}
                  helperText="Select one image. Drag and drop here, or click to browse."
                />
                {profilePictureUploading && (
                  <UploadProgress
                    label="Uploading profile picture…"
                    progress={profilePictureProgress}
                    onCancel={() => profilePictureAbortRef.current?.abort()}
                  />
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={profilePictureUploading}
                  onClick={() => void handleRemoveProfilePicture()}
                >
                  Remove current picture
                </Button>
              </div>
            </div>
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
              <div className="space-y-2">
                <AudienceField
                  audience={profileAudience}
                  onAudienceChange={setProfileAudience}
                  selectedUserIds={profileAudienceUserIds}
                  onSelectedUserIdsChange={setProfileAudienceUserIds}
                  label="Who can see your profile"
                />
                <p className="text-sm text-muted-foreground">
                  Restricted profiles stay listed so people can still request to follow you — only your details are hidden.
                </p>
              </div>
              <Button type="submit" disabled={profileSaving}>
                {profileSaving ? 'Saving...' : 'Save profile'}
              </Button>
            </form>
          </CardContent>
        </Card>

        {interestsEnabled && (
          <>
            <Card>
              <CardHeader>
                <CardTitle>Your interests</CardTitle>
                <CardDescription>
                  Rate interests from -10 (fully uninterested) to +10 (fully interested). Characters inherit these unless you set custom interests for them.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <InterestRatingList
                  interests={interests}
                  onSave={handleSaveInterestRating}
                  onClear={handleClearInterestRating}
                />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Request a new interest</CardTitle>
                <CardDescription>Suggest an interest for an admin to review and add to the catalog.</CardDescription>
              </CardHeader>
              <CardContent>
                <RequestInterestForm interests={interests} />
              </CardContent>
            </Card>
          </>
        )}
      </section>
      )}

      {settingsTab === 'account' && (
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
              <div className="space-y-3 rounded-md border border-input p-3">
                <p className="text-sm font-medium">In-app notifications</p>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyNewPost}
                    onChange={(event) => setNotifyNewPost(event.target.checked)}
                  />
                  Notify me when someone I follow posts
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyPostReaction}
                    onChange={(event) => setNotifyPostReaction(event.target.checked)}
                  />
                  Notify me when someone reacts to my post
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyPostComment}
                    onChange={(event) => setNotifyPostComment(event.target.checked)}
                  />
                  Notify me when someone comments on my post
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyFollowRequest}
                    onChange={(event) => setNotifyFollowRequest(event.target.checked)}
                  />
                  Notify me when I receive a follow request
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyFollowAccepted}
                    onChange={(event) => setNotifyFollowAccepted(event.target.checked)}
                  />
                  Notify me when one of my follow requests is accepted
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyCoAuthorInvite}
                    onChange={(event) => setNotifyCoAuthorInvite(event.target.checked)}
                  />
                  Notify me when I receive a co-author invite
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={notifyCoAuthorInviteAccepted}
                    onChange={(event) => setNotifyCoAuthorInviteAccepted(event.target.checked)}
                  />
                  Notify me when a co-author invite is accepted
                </label>
                <p className="text-xs text-muted-foreground">Social notifications stay in your Vora inbox. Email is used only for account verification and password resets.</p>
              </div>
              <div className="space-y-3 rounded-md border border-input p-3">
                <div>
                  <p className="text-sm font-medium">Browser push notifications</p>
                  <p className="text-xs text-muted-foreground">
                    {pushSupported
                      ? `This account has ${pushSubscriptionCount} subscribed ${pushSubscriptionCount === 1 ? 'device' : 'devices'}.`
                      : 'This browser does not support push notifications.'}
                  </p>
                </div>
                {pushMessage && (
                  <Alert>
                    <AlertDescription>{pushMessage}</AlertDescription>
                  </Alert>
                )}
                {pushError && (
                  <Alert variant="destructive">
                    <AlertDescription>{pushError}</AlertDescription>
                  </Alert>
                )}
                {pushSupported && !initialData.web_push_public_key && (
                  <p className="text-sm text-muted-foreground">Browser push is not configured.</p>
                )}
                {pushSupported && initialData.web_push_public_key && (
                  <div className="flex flex-wrap items-center gap-3">
                    <Button
                      type="button"
                      variant={pushSubscribed ? 'outline' : 'default'}
                      disabled={pushSaving || pushStatusLoading || pushPermission === 'denied'}
                      onClick={() => void (pushSubscribed ? handleDisablePush() : handleEnablePush())}
                    >
                      {pushStatusLoading ? 'Checking...' : pushSaving ? 'Saving...' : (pushSubscribed ? 'Disable on this device' : 'Enable on this device')}
                    </Button>
                    {pushPermission === 'denied' && (
                      <span className="text-sm text-muted-foreground">Push permission is blocked in this browser.</span>
                    )}
                  </div>
                )}
              </div>
              <p className="text-sm text-muted-foreground">
                ID verification: {accountVerificationDate ? `Verified (${new Date(accountVerificationDate).toLocaleString()})` : 'Not verified yet'}
              </p>
              <Button type="submit" disabled={accountSaving}>
                {accountSaving ? 'Saving...' : 'Save account settings'}
              </Button>
              <Button type="button" variant="outline" onClick={handleExportAccount}>
                Export account data
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

        <Card>
          <CardHeader>
            <CardTitle>Deactivate or delete</CardTitle>
            <CardDescription>
              Deactivating hides your account from other users and can be reversed by logging back in. Deleting is permanent for you — only an admin can restore it.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-3">
            <Button type="button" variant="outline" onClick={() => void handleDeactivateAccount()}>
              Deactivate account
            </Button>
            <Button type="button" variant="destructive" onClick={() => void handleDeleteAccount()}>
              Delete account
            </Button>
          </CardContent>
        </Card>
      </section>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('user-settings');
if (mountEl) {
  createRoot(mountEl).render(<UserSettingsPage />);
}
