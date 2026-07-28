import { VenetianMask } from 'lucide-react';
import type { ReactNode } from 'react';

import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/** A discoverable persona card (Explore's Personas tab, the People directory).
 *  Deliberately carries nothing about the human behind the persona. */
export interface PersonaDiscoveryItem {
  id: number;
  ulid: string;
  display_name: string;
  description: string | null;
  avatar_url: string | null;
  user_type: string | null;
  gender: string | null;
  href: string;
  favorited?: boolean;
}

interface PersonaGridProps {
  items: PersonaDiscoveryItem[];
  renderActions?: (persona: PersonaDiscoveryItem) => ReactNode;
}

export function PersonaGrid({ items, renderActions }: PersonaGridProps) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((persona) => (
        <Card key={persona.id}>
          <CardHeader className="flex flex-row items-start justify-between gap-2">
            <div className="flex min-w-0 items-center gap-3">
              <Avatar name={persona.display_name} src={persona.avatar_url} sizeClassName="h-10 w-10" />
              <div className="min-w-0">
                <CardTitle className="truncate text-base">{persona.display_name}</CardTitle>
                <div className="mt-1 flex flex-wrap gap-1">
                  {persona.user_type && <Badge variant="outline">{persona.user_type}</Badge>}
                  {persona.gender && <Badge variant="outline">{persona.gender}</Badge>}
                </div>
              </div>
            </div>
            <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
              <VenetianMask className="h-3 w-3" aria-hidden="true" /> Persona
            </span>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm">
            {persona.description && <p className="line-clamp-2 text-xs text-muted-foreground">{persona.description}</p>}
            <a className="text-sm underline underline-offset-4" href={persona.href}>Visit</a>
            {renderActions?.(persona)}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
