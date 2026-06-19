import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { readInitialData } from '@/initialData';

import { PostCard } from './PostCard';
import type { CommunityPost } from './types';

function SinglePostPage() {
  const [post] = useState<CommunityPost | null>(() => readInitialData<{ postView?: CommunityPost }>().postView ?? null);
  const error = post === null ? 'This post is unavailable.' : '';

  return (
    <div className="space-y-4">
      {error && <p className="text-sm text-destructive">{error}</p>}
      {post && <PostCard post={post} expanded />}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('post-view');
if (mountEl) createRoot(mountEl).render(<SinglePostPage />);
