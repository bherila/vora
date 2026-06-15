import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Markdown } from '@/components/Markdown';

import { storiesApi } from './api';
import { CyoaPlayer } from './CyoaPlayer';
import type { StoryReader } from './types';

function readUlid(): string {
  const script = document.getElementById('story-reader-data');
  try {
    const data = JSON.parse(script?.textContent?.trim() ?? '{}') as { ulid?: string };
    return typeof data.ulid === 'string' ? data.ulid : '';
  } catch {
    return '';
  }
}

function StoryReaderPage() {
  const [story, setStory] = useState<StoryReader | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const ulid = readUlid();
    if (ulid === '') {
      setError('Story not found.');
      setLoading(false);
      return;
    }
    storiesApi
      .reader(ulid)
      .then(setStory)
      .catch((e) => setError(typeof e === 'string' ? e : 'This story is unavailable.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="mx-auto max-w-3xl px-4 py-10 text-sm text-muted-foreground">Loading…</div>;
  if (story === null) return <div className="mx-auto max-w-3xl px-4 py-10 text-sm text-destructive">{error}</div>;

  const authorNames = story.authors.map((a) => a.display_name).join(', ');

  return (
    <article className="mx-auto max-w-3xl space-y-6 px-4 py-10">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold">{story.title}</h1>
        <p className="text-sm text-muted-foreground">
          {story.type === 'cyoa' ? 'Choose your own adventure' : 'Story'}
          {authorNames && <> · by {authorNames}</>}
        </p>
        {(story.involves.length > 0 || story.interests.length > 0) && (
          <div className="flex flex-wrap gap-2 pt-1">
            {story.involves.map((v) => (
              <span key={`v-${v.type}-${v.id}`} className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                {v.name}
              </span>
            ))}
            {story.interests.map((i) => (
              <span key={`i-${i.id}`} className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                #{i.name}
              </span>
            ))}
          </div>
        )}
      </header>

      {story.type === 'cyoa' ? <CyoaPlayer story={story} /> : <Markdown source={story.body} />}
    </article>
  );
}

const mount = document.getElementById('story-reader');
if (mount) createRoot(mount).render(<StoryReaderPage />);
