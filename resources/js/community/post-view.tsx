import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { communityApi } from './api';
import { PostCard } from './PostCard';
import type { CommunityPost } from './types';

function SinglePostPage({ ulid }: { ulid: string }) {
  const [post, setPost] = useState<CommunityPost | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    communityApi.postByUlid(ulid)
      .then((loaded) => {
        if (active) setPost(loaded);
      })
      .catch(() => {
        if (active) setError('This post is unavailable.');
      });

    return () => {
      active = false;
    };
  }, [ulid]);

  return (
    <div className="space-y-4">
      {error && <p className="text-sm text-destructive">{error}</p>}
      {!error && post === null && <p className="text-sm text-muted-foreground">Loading post...</p>}
      {post && <PostCard post={post} expanded />}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('post-view');
const ulid = mountEl?.dataset.ulid;
if (mountEl && ulid) createRoot(mountEl).render(<SinglePostPage ulid={ulid} />);
