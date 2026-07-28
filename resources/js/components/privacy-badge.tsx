import { Globe, Lock, Users } from 'lucide-react';

import { HelpHint } from '@/components/help-hint';
import type { Audience } from '@/lib/audience';

interface PrivacyBadgeProps {
  audience: Audience;
  discoverable?: boolean;
  className?: string;
}

const COPY: Record<Audience, { label: string; detail: string }> = {
  everyone: { label: 'Public', detail: 'Anyone signed in can see this.' },
  followers: { label: 'Followers only', detail: 'Only people who follow you can see this.' },
  mutuals: { label: 'Mutuals only', detail: 'Only people you follow who also follow you can see this.' },
  specific: { label: 'Specific people', detail: 'Only the specific people you chose can see this.' },
};

/**
 * An owner-facing privacy indicator: a lock/globe icon that opens a popover
 * explaining exactly who can see the item it sits on. Shown only to the owner,
 * so it never leaks an item's audience to other viewers. A specialisation of
 * {@link HelpHint} styled to sit on top of media thumbnails.
 */
export function PrivacyBadge({ audience, discoverable = false, className }: PrivacyBadgeProps) {
  const copy = COPY[audience];
  const Icon = audience === 'everyone' ? Globe : audience === 'specific' ? Lock : Users;

  return (
    <HelpHint
      label={copy.label}
      icon={Icon}
      className={`bg-background/80 p-1.5 shadow-sm backdrop-blur ${className ?? ''}`}
    >
      <p>{copy.detail}</p>
      {audience === 'everyone' && (
        <p className="mt-2 text-xs">
          {discoverable ? 'Also shown in Explore.' : 'Not shown in Explore — reachable by direct link or your profile.'}
        </p>
      )}
    </HelpHint>
  );
}
