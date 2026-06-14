import { useEffect, useMemo, useState } from 'react';

import { Input } from '@/components/ui/input';
import { fetchWrapper } from '@/fetchWrapper';

interface InterestOption {
  id: number;
  name: string;
  parent_interest_id: number | null;
}

interface InterestPickerProps {
  value: number[];
  onChange: (next: number[]) => void;
  disabled?: boolean;
}

/**
 * Reusable multi-select of profile interests, sourced from /api/interests.
 * Used by the media uploader to tag uploads; the controlled `value` is a list
 * of selected interest ids.
 */
export function InterestPicker({ value, onChange, disabled = false }: InterestPickerProps) {
  const [interests, setInterests] = useState<InterestOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('');

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const response = (await fetchWrapper.get('/api/interests')) as { data: InterestOption[] };
        if (active) {
          setInterests(response.data ?? []);
        }
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    })();
    return () => {
      active = false;
    };
  }, []);

  const selected = useMemo(() => new Set(value), [value]);

  const visible = useMemo(() => {
    const needle = filter.trim().toLowerCase();
    const sorted = [...interests].sort((a, b) => a.name.localeCompare(b.name));
    if (!needle) {
      return sorted;
    }
    return sorted.filter((interest) => interest.name.toLowerCase().includes(needle));
  }, [interests, filter]);

  const toggle = (id: number): void => {
    const next = new Set(selected);
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
    }
    onChange([...next]);
  };

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading interests…</p>;
  }

  if (interests.length === 0) {
    return <p className="text-sm text-muted-foreground">No interests are available yet.</p>;
  }

  return (
    <div className="grid gap-2">
      <Input
        value={filter}
        onChange={(event) => setFilter(event.target.value)}
        placeholder="Filter interests…"
        disabled={disabled}
      />
      <div className="max-h-48 overflow-y-auto rounded-md border border-input p-2">
        {visible.map((interest) => (
          <label key={interest.id} className="flex items-center gap-2 py-1 text-sm">
            <input
              type="checkbox"
              checked={selected.has(interest.id)}
              onChange={() => toggle(interest.id)}
              disabled={disabled}
            />
            <span>{interest.name}</span>
          </label>
        ))}
        {visible.length === 0 && <p className="py-1 text-sm text-muted-foreground">No matches.</p>}
      </div>
      {selected.size > 0 && (
        <p className="text-xs text-muted-foreground">{selected.size} selected</p>
      )}
    </div>
  );
}
