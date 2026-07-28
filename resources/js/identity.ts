import { useSyncExternalStore } from 'react';

import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

export interface IdentityOption {
  id: number | null;
  displayName: string;
  avatarUrl: string | null;
}

export interface IdentitySnapshot {
  identities: IdentityOption[];
  activeIdentityId: number | null;
}

interface IdentityInitialData {
  navbar?: {
    activeIdentityId?: number | null;
    identities?: IdentityOption[];
  };
}

type Listener = () => void;

let snapshot: IdentitySnapshot | null = null;
const listeners = new Set<Listener>();

function normalizedId(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) ? value : null;
}

function initialSnapshot(): IdentitySnapshot {
  const navbar = readInitialData<IdentityInitialData>().navbar;

  return {
    identities: navbar?.identities ?? [],
    activeIdentityId: normalizedId(navbar?.activeIdentityId),
  };
}

function currentSnapshot(): IdentitySnapshot {
  snapshot ??= initialSnapshot();

  return snapshot;
}

function publish(next: IdentitySnapshot): void {
  snapshot = next;
  listeners.forEach((listener) => listener());
}

function subscribe(listener: Listener): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

/**
 * Seeds the cross-root identity store from Blade hydration. The navbar entry
 * invokes this before mounting; it is also intentionally usable by tests that
 * render React roots directly.
 */
export function hydrateIdentityStore(identities: IdentityOption[], activeIdentityId: number | null): void {
  const id = normalizedId(activeIdentityId);
  publish({
    identities,
    activeIdentityId: identities.some((identity) => identity.id === id) ? id : null,
  });
}

export function useIdentityStore(): IdentitySnapshot {
  return useSyncExternalStore(subscribe, currentSnapshot, currentSnapshot);
}

export function useActiveIdentityId(): number | null {
  return useIdentityStore().activeIdentityId;
}

/**
 * Persists a global authorship choice, then publishes it to every React root:
 * navbar, /me identity rail, composers, uploads, and story editor.
 */
export async function switchActiveIdentity(activeIdentityId: number | null): Promise<void> {
  const id = normalizedId(activeIdentityId);
  const current = currentSnapshot();
  if (current.activeIdentityId === id) return;
  if (!current.identities.some((identity) => identity.id === id)) {
    throw new Error('Identity is no longer available.');
  }

  await fetchWrapper.post('/api/identity', { character_id: id });
  publish({ ...currentSnapshot(), activeIdentityId: id });
}

/** Adds or refreshes a persona option after same-page CRUD. */
export function upsertIdentityOption(identity: IdentityOption, humanIdentity?: IdentityOption): void {
  if (identity.id === null) return;
  const current = currentSnapshot();
  const next = current.identities.filter((option) => option.id !== identity.id);

  if (!next.some((option) => option.id === null) && humanIdentity?.id === null) {
    next.unshift(humanIdentity);
  }
  next.push(identity);

  publish({ ...current, identities: next });
}

/** Removes a stale/deleted persona option from every mounted consumer. */
export function removeIdentityOption(identityId: number): void {
  const current = currentSnapshot();
  const remaining = current.identities.filter((identity) => identity.id !== identityId);
  const identities = remaining.some((identity) => identity.id !== null) ? remaining : [];
  publish({
    identities,
    activeIdentityId: current.activeIdentityId === identityId ? null : current.activeIdentityId,
  });
}
