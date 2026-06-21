import { ProtectedImage } from '@/components/protected-image';
import { cn } from '@/lib/utils';

interface AvatarProps {
  /** Display name — used for the alt text and the initials fallback. */
  name: string;
  /** Signed avatar URL, or null/undefined when the user has no profile picture. */
  src?: string | null | undefined;
  /** Tailwind size classes for the circle (height + width). Defaults to h-8 w-8. */
  sizeClassName?: string;
  className?: string;
}

/** Up to two initials derived from a display name, e.g. "Ada Lovelace" -> "AL". */
function initialsFor(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  if (first === '') return '?';
  if (parts.length === 1) return first.slice(0, 2).toUpperCase();
  const last = parts[parts.length - 1] ?? '';
  return ((first[0] ?? '') + (last[0] ?? '')).toUpperCase() || '?';
}

/**
 * A user/character avatar: shows the profile picture when present, otherwise a
 * deterministic initials circle so every named user reads as a person. Image
 * loads through ProtectedImage (no drag/context-menu) to match the rest of the
 * media surfaces.
 */
export function Avatar({ name, src, sizeClassName = 'h-8 w-8', className }: AvatarProps) {
  if (src) {
    return (
      <ProtectedImage
        src={src}
        alt={name}
        className={cn(sizeClassName, 'shrink-0 rounded-full object-cover', className)}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      className={cn(
        sizeClassName,
        'inline-flex shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground',
        className,
      )}
    >
      {initialsFor(name)}
    </span>
  );
}
