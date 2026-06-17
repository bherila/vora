import { fetchWrapper } from '@/fetchWrapper';

interface PushSubscriptionResponse {
  success: boolean;
  data: {
    subscription_count: number;
  };
}

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }

  return outputArray;
}

export function isWebPushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function currentBrowserPushSubscription(): Promise<PushSubscription | null> {
  if (!isWebPushSupported()) {
    return null;
  }

  const registration = await navigator.serviceWorker.register('/sw.js');

  return registration.pushManager.getSubscription();
}

export async function subscribeBrowserToWebPush(publicKey: string): Promise<number> {
  if (!isWebPushSupported()) {
    throw new Error('Browser push is not supported.');
  }

  if (!publicKey) {
    throw new Error('Browser push is not configured.');
  }

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Browser push permission was not granted.');
  }

  const registration = await navigator.serviceWorker.register('/sw.js');

  // Clear any pre-existing browser subscription first. The enable flow only runs
  // when this account is not subscribed, so an existing subscription here is stale
  // (e.g. it belongs to another account on a shared browser). Reusing it would POST
  // an endpoint that is already globally unique to that other account and fail, so
  // unsubscribe to force a fresh endpoint.
  const existing = await registration.pushManager.getSubscription();
  if (existing) {
    await existing.unsubscribe();
  }

  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  });

  const payload = subscription.toJSON();
  if (!payload.endpoint || !payload.keys?.p256dh || !payload.keys.auth) {
    throw new Error('Browser push subscription was incomplete.');
  }

  const response = await fetchWrapper.post('/api/push-subscriptions', {
    endpoint: payload.endpoint,
    keys: payload.keys,
    content_encoding: 'aes128gcm',
  }) as PushSubscriptionResponse;

  return response.data.subscription_count;
}

export async function unsubscribeBrowserFromWebPush(): Promise<number> {
  const subscription = await currentBrowserPushSubscription();
  if (!subscription) {
    return 0;
  }

  const endpoint = subscription.endpoint;
  await subscription.unsubscribe();

  const response = await fetchWrapper.delete('/api/push-subscriptions', { endpoint }) as PushSubscriptionResponse;

  return response.data.subscription_count;
}
