import { ChevronDown, Eye } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type ViewAsMode = 'public' | 'follower';

export interface ProfileViewAs {
  mode: ViewAsMode;
  audience: string;
}

interface ViewAsBannerProps {
  viewAs: ProfileViewAs;
}

function previewHref(mode: ViewAsMode): string {
  return `/me?view_as=${mode}`;
}

export function ViewAsControl() {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="outline">
          <Eye className="h-4 w-4" aria-hidden="true" />
          View as
          <ChevronDown className="h-3 w-3" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem asChild>
          <a href={previewHref('public')}>Public</a>
        </DropdownMenuItem>
        <DropdownMenuItem asChild>
          <a href={previewHref('follower')}>Follower</a>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function ViewAsBanner({ viewAs }: ViewAsBannerProps) {
  return (
    <Alert>
      <Eye className="h-4 w-4" aria-hidden="true" />
      <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
        <span>
          Viewing your profile as <strong>{viewAs.audience}</strong>. This is exactly what they see.
        </span>
        <span className="flex items-center gap-2">
          <ViewAsControl />
          <Button size="sm" variant="ghost" asChild>
            <a href="/me">Exit preview</a>
          </Button>
        </span>
      </AlertDescription>
    </Alert>
  );
}
