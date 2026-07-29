import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FavoriteButton } from '@/components/favorite-button';
import { Markdown } from '@/components/Markdown';
import { READING_PAGE_WIDTH } from '@/components/page-width';
import { ReportButton } from '@/components/report-button';
import { readInitialData } from '@/initialData';

import { CyoaPlayer } from './CyoaPlayer';
import type { StoryReader } from './types';

function getInitialStory(): StoryReader | null {
  return readInitialData<{ storyReader?: StoryReader }>().storyReader ?? null;
}

function StoryReaderPage() {
  const [story] = useState<StoryReader | null>(getInitialStory);
  const error = 'This story is unavailable.';

  if (story === null) return <div className={`${READING_PAGE_WIDTH} px-4 py-10 text-sm text-destructive`}>{error}</div>;

  const authorNames = story.authors.map((a) => a.display_name).join(', ');

  return (
    <article className={`${READING_PAGE_WIDTH} space-y-6 px-4 py-10`}>
      <header className="space-y-2">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <h1 className="text-3xl font-bold">{story.title}</h1>
          <div className="flex items-center gap-3">
            {typeof story.favorite_count === 'number' && story.favorite_count > 0 && (
              <span className="text-sm text-muted-foreground">{story.favorite_count} {story.favorite_count === 1 ? 'save' : 'saves'}</span>
            )}
            {story.favorited !== undefined && <FavoriteButton type="story" id={story.id} initialFavorited={story.favorited} />}
            {story.can_report && <ReportButton type="story" id={story.id} />}
          </div>
        </div>
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
      <Toaster position="top-right" richColors closeButton />
    </article>
  );
}

const mount = document.getElementById('story-reader');
if (mount) createRoot(mount).render(<StoryReaderPage />);
