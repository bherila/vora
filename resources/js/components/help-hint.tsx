import { HelpCircle, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

interface HelpHintProps {
  /** Bold first line of the popover; also the trigger's accessible name. */
  label: string;
  /** Explanatory copy under the label. */
  children: ReactNode;
  /** Trigger icon; defaults to a question mark. */
  icon?: LucideIcon;
  /** Extra classes for the trigger button (placement, overlay styling). */
  className?: string;
}

/**
 * The house style for inline help: a small icon that opens a popover with a
 * bold label and a plain explanation. Click/tap to open — never a bare tooltip,
 * which has no hover target on touch. Callers decide who sees it; owner-only
 * hints must simply not be rendered for other viewers.
 */
export function HelpHint({ label, children, icon: Icon = HelpCircle, className }: HelpHintProps) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            'inline-flex items-center justify-center rounded-full p-1 text-muted-foreground hover:text-foreground',
            className,
          )}
          aria-label={label}
        >
          <Icon className="h-4 w-4" aria-hidden="true" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-64 text-sm">
        <p className="font-medium">{label}</p>
        <div className="mt-1 text-muted-foreground">{children}</div>
      </PopoverContent>
    </Popover>
  );
}
