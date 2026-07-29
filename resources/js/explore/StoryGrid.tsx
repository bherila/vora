import { BookOpen, GitBranch } from 'lucide-react';
import type { ReactNode } from 'react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { safeInternalUrl } from '@/security/dom-url';
import type { StoryDiscoveryItem } from '@/stories/types';

interface StoryGridProps {
  items: StoryDiscoveryItem[];
  getHref?: (story: StoryDiscoveryItem) => string | null;
  renderActions?: (story: StoryDiscoveryItem) => ReactNode;
}

export function StoryGrid({
  items,
  getHref = (story) => `/s/${story.ulid}`,
  renderActions,
}: StoryGridProps) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((story) => {
        const href = safeInternalUrl(getHref(story));

        return (
          <Card key={story.id}>
            <CardHeader className="flex flex-row items-start justify-between gap-2">
              <CardTitle className="text-base">{story.title}</CardTitle>
              <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                {story.type === 'cyoa' ? <GitBranch className="h-3 w-3" /> : <BookOpen className="h-3 w-3" />}
                {story.type === 'cyoa' ? 'Adventure' : 'Story'}
              </span>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm">
              <p className="text-xs text-muted-foreground">
                by {[story.owner?.display_name, ...story.authors.filter((author) => !author.is_owner).map((author) => author.display_name)].filter(Boolean).join(', ') || 'Unknown author'}
              </p>
              {story.type === 'cyoa' && (
                <p className="text-xs text-muted-foreground">{story.node_count ?? 0} passages</p>
              )}
              {story.interests.length > 0 && (
                <p className="truncate text-xs text-muted-foreground">{story.interests.map((interest) => interest.name).join(', ')}</p>
              )}
              {href && (
                <a className="text-sm underline underline-offset-4" href={href}>
                  Read
                </a>
              )}
              {renderActions?.(story)}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
