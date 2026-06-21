import { Download, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { ProtectedImage } from '@/components/protected-image';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StoryGrid } from '@/explore/StoryGrid';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaGrid } from '@/media/MediaGrid';
import type { AdminMediaItem, MediaItem, PagedResponse,PageMeta } from '@/media/types';
import type { Audience, StoryDiscoveryItem } from '@/stories/types';

type DeletedContentType = 'media' | 'stories' | 'characters' | 'posts';

interface DeletedBase {
  id: number;
  deleted_at: string | null;
}

type DeletedMediaItem = AdminMediaItem & DeletedBase;

interface DeletedStoryItem extends StoryDiscoveryItem, DeletedBase {
  audience: Audience;
  discoverable: boolean;
  status: string;
  body: string | null;
}

interface DeletedCharacterItem extends DeletedBase {
  display_name: string;
  description: string | null;
  audience: Audience;
  discoverable: boolean;
  media_count: number;
  profile_picture: MediaItem | null;
  user: { id: number; name: string | null; email: string | null };
}

interface DeletedPostItem extends DeletedBase {
  ulid: string;
  body: string;
  audience: Audience;
  discoverable: boolean;
  author: { id: number; display_name: string } | null;
  comment_count: number;
  reaction_count: number;
}

type DeletedItem = DeletedMediaItem | DeletedStoryItem | DeletedCharacterItem | DeletedPostItem;

const TABS: Array<{ value: DeletedContentType; label: string }> = [
  { value: 'media', label: 'Media' },
  { value: 'stories', label: 'Stories' },
  { value: 'characters', label: 'Characters' },
  { value: 'posts', label: 'Posts' },
];

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

function formatDate(value: string | null): string {
  if (!value) {
    return 'Unknown';
  }

  return new Date(value).toLocaleString();
}

function AdminDeletedContentPage() {
  const [tab, setTab] = useState<DeletedContentType>('media');
  const [items, setItems] = useState<DeletedItem[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [busy, setBusy] = useState<Record<number, boolean>>({});

  const load = async (type: DeletedContentType, page = 1): Promise<void> => {
    if (page > 1) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }

    try {
      const params = new URLSearchParams({ type, page: String(page) });
      const response = (await fetchWrapper.get(`/api/admin/deleted-content?${params.toString()}`)) as PagedResponse<DeletedItem>;
      const rows = response.data ?? [];
      setItems((current) => (page > 1 ? [...current, ...rows] : rows));
      setMeta(response.meta ?? null);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  };

  useEffect(() => {
    void load(tab);
  }, [tab]);

  const restore = async (id: number): Promise<void> => {
    setBusy((current) => ({ ...current, [id]: true }));
    try {
      await fetchWrapper.post(`/api/admin/deleted-content/${tab}/${id}/restore`, {});
      toast.success('Content restored.');
      await load(tab);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy((current) => ({ ...current, [id]: false }));
    }
  };

  const permanentlyDelete = async (id: number): Promise<void> => {
    if (!window.confirm('Permanently delete this item? This removes the retained record and cannot be undone.')) {
      return;
    }

    setBusy((current) => ({ ...current, [id]: true }));
    try {
      await fetchWrapper.delete(`/api/admin/deleted-content/${tab}/${id}`);
      toast.success('Content permanently deleted.');
      await load(tab);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy((current) => ({ ...current, [id]: false }));
    }
  };

  const actions = (item: DeletedBase, downloadUrl?: string | null) => (
    <div className="flex flex-wrap gap-2">
      {downloadUrl && (
        <Button asChild type="button" size="sm" variant="outline">
          <a href={downloadUrl} download target="_blank" rel="noreferrer">
            <Download className="h-4 w-4" />
            Download
          </a>
        </Button>
      )}
      <Button type="button" size="sm" variant="outline" disabled={busy[item.id]} onClick={() => void restore(item.id)}>
        <RotateCcw className="h-4 w-4" />
        Restore
      </Button>
      <Button type="button" size="sm" variant="destructive" disabled={busy[item.id]} onClick={() => void permanentlyDelete(item.id)}>
        <Trash2 className="h-4 w-4" />
        Delete forever
      </Button>
    </div>
  );

  const renderContent = () => {
    if (loading) {
      return <p className="text-muted-foreground">Loading...</p>;
    }

    if (items.length === 0) {
      return <p className="text-muted-foreground">No deleted {TABS.find((item) => item.value === tab)?.label.toLowerCase()}.</p>;
    }

    if (tab === 'media') {
      const media = items as DeletedMediaItem[];

      return (
        <MediaGrid
          items={media}
          getHref={() => null}
          renderActions={(item) => {
            const deleted = item as DeletedMediaItem;

            return (
              <div className="grid gap-2">
                <p className="text-xs text-muted-foreground">Deleted {formatDate(deleted.deleted_at)}</p>
                {actions(deleted, deleted.download_url)}
              </div>
            );
          }}
        />
      );
    }

    if (tab === 'stories') {
      return (
        <StoryGrid
          items={items as DeletedStoryItem[]}
          getHref={() => null}
          renderActions={(story) => (
            <div className="grid gap-2">
              <p className="text-xs text-muted-foreground">Deleted {formatDate((story as DeletedStoryItem).deleted_at)}</p>
              {actions(story as DeletedStoryItem)}
            </div>
          )}
        />
      );
    }

    if (tab === 'characters') {
      return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {(items as DeletedCharacterItem[]).map((character) => (
            <Card key={character.id}>
              <CardHeader>
                <CardTitle className="text-base">{character.display_name}</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3 text-sm">
                {character.profile_picture?.thumbnail_url || character.profile_picture?.url ? (
                  <div className="aspect-video overflow-hidden rounded-md bg-muted">
                    <ProtectedImage
                      src={character.profile_picture.thumbnail_url ?? character.profile_picture.url ?? ''}
                      alt={character.display_name}
                      className="h-full w-full object-cover"
                    />
                  </div>
                ) : null}
                {character.description && <p className="line-clamp-3 text-muted-foreground">{character.description}</p>}
                <dl className="grid gap-1 text-xs text-muted-foreground">
                  <div className="flex justify-between gap-3">
                    <dt>Owner</dt>
                    <dd className="truncate">{character.user.name ?? character.user.email ?? `#${character.user.id}`}</dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt>Media</dt>
                    <dd>{character.media_count}</dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt>Deleted</dt>
                    <dd>{formatDate(character.deleted_at)}</dd>
                  </div>
                </dl>
                {actions(character)}
              </CardContent>
            </Card>
          ))}
        </div>
      );
    }

    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {(items as DeletedPostItem[]).map((post) => (
          <Card key={post.id}>
            <CardHeader>
              <CardTitle className="text-base">Post #{post.id}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <p className="line-clamp-5 whitespace-pre-wrap">{post.body}</p>
              <dl className="grid gap-1 text-xs text-muted-foreground">
                <div className="flex justify-between gap-3">
                  <dt>Author</dt>
                  <dd className="truncate">{post.author?.display_name ?? 'Unknown'}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt>Engagement</dt>
                  <dd>{post.reaction_count} reactions · {post.comment_count} comments</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt>Deleted</dt>
                  <dd>{formatDate(post.deleted_at)}</dd>
                </div>
              </dl>
              {actions(post)}
            </CardContent>
          </Card>
        ))}
      </div>
    );
  };

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Deleted content</h1>
        <p className="text-muted-foreground">Restore retained user-deleted content or permanently remove it.</p>
      </div>

      <div className="mb-6 flex flex-wrap gap-2" role="tablist" aria-label="Deleted content type">
        {TABS.map((item) => (
          <Button
            key={item.value}
            type="button"
            size="sm"
            variant={tab === item.value ? 'default' : 'outline'}
            aria-pressed={tab === item.value}
            onClick={() => setTab(item.value)}
          >
            {item.label}
          </Button>
        ))}
      </div>

      {renderContent()}

      {meta?.has_more && (
        <div className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(tab, meta.current_page + 1)}>
            {loadingMore ? 'Loading...' : 'Load more'}
          </Button>
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('admin-deleted-content');
if (mountEl) {
  createRoot(mountEl).render(<AdminDeletedContentPage />);
}
