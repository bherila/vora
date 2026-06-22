import { Star } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

type FavoritableType = 'media' | 'story' | 'post' | 'user' | 'character';

interface FavoriteButtonProps {
  type: FavoritableType;
  id: number;
  initialFavorited: boolean;
  className?: string;
}

/**
 * Save/unsave toggle for any favoritable item. Optimistic: it flips immediately
 * and rolls back on error. The server enforces that you can only favorite what
 * you can see, so a 403 simply reverts the toggle.
 */
export function FavoriteButton({ type, id, initialFavorited, className }: FavoriteButtonProps) {
  const [favorited, setFavorited] = useState(initialFavorited);
  const [busy, setBusy] = useState(false);

  const toggle = async (): Promise<void> => {
    const next = !favorited;
    setBusy(true);
    setFavorited(next);
    try {
      if (next) {
        await fetchWrapper.post('/api/favorites', { type, id });
      } else {
        await fetchWrapper.delete('/api/favorites', { type, id });
      }
    } catch (err) {
      setFavorited(!next);
      toast.error(typeof err === 'string' ? err : 'Could not update favorite.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button
      type="button"
      variant={favorited ? 'default' : 'outline'}
      size="sm"
      disabled={busy}
      aria-pressed={favorited}
      onClick={() => void toggle()}
      className={className}
    >
      <Star className={`h-4 w-4 ${favorited ? 'fill-current' : ''}`} />
      {favorited ? 'Saved' : 'Save'}
    </Button>
  );
}
