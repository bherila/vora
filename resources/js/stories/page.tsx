import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { readInitialData } from '@/initialData';

import { StoryEditorPanel } from './StoryEditorPanel';

interface StoriesInitialData {
  stories?: {
    currentUserId?: number;
  };
}

function getCurrentUserId(): number {
  return readInitialData<StoriesInitialData>().stories?.currentUserId ?? 0;
}

/** The story id the URL points at (?edit=123). */
function readEditIdFromUrl(): number | null {
  const value = new URLSearchParams(window.location.search).get('edit');
  return value !== null && /^\d+$/.test(value) ? Number(value) : null;
}

/**
 * The dedicated story editor page. Reached as /stories?edit=<id> from the
 * profile's Stories tab; the story library and create flow live on the profile
 * now. With no edit target the page returns to the profile home (the server also
 * redirects, so this is just a fallback).
 */
function StoryEditorPage() {
  const [currentUserId] = useState<number>(getCurrentUserId);
  const [editingId] = useState<number | null>(readEditIdFromUrl);

  if (editingId === null) {
    window.location.replace('/me');
    return null;
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <StoryEditorPanel
        storyId={editingId}
        currentUserId={currentUserId}
        onBack={() => { window.location.href = '/me'; }}
        onChanged={() => {}}
        onDeleted={() => { window.location.href = '/me'; }}
      />
    </div>
  );
}

const mount = document.getElementById('stories-app');
if (mount) createRoot(mount).render(<StoryEditorPage />);
