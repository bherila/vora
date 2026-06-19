import { useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

import type { UserOption } from './types';

interface UsersResponse {
  success: boolean;
  data: UserOption[];
}

interface UserPickerProps {
  selectedIds: number[];
  onChange: (ids: number[]) => void;
  disabled?: boolean;
}

export function UserPicker({ selectedIds, onChange, disabled = false }: UserPickerProps) {
  const [users, setUsers] = useState<UserOption[]>([]);
  const [query, setQuery] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    fetchWrapper.get('/api/users')
      .then((response) => {
        if (active) setUsers((response as UsersResponse).data);
      })
      .catch(() => {
        if (active) setError('Could not load people.');
      });

    return () => {
      active = false;
    };
  }, []);

  const selected = new Set(selectedIds);
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle === ''
      ? users
      : users.filter((user) => user.display_name.toLowerCase().includes(needle));
  }, [query, users]);

  const toggle = (id: number, checked: boolean): void => {
    if (checked) {
      onChange([...selectedIds, id]);
      return;
    }
    onChange(selectedIds.filter((selectedId) => selectedId !== id));
  };

  return (
    <div className="space-y-2 rounded-md border border-border p-3">
      <Label htmlFor="specific-people-search">Specific people</Label>
      <Input
        id="specific-people-search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="Search people"
        disabled={disabled}
      />
      {error && <p className="text-sm text-destructive">{error}</p>}
      {selectedIds.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {users.filter((user) => selected.has(user.id)).map((user) => (
            <Badge key={user.id} variant="secondary">{user.display_name}</Badge>
          ))}
        </div>
      )}
      <div className="max-h-48 space-y-2 overflow-auto pr-1">
        {filtered.map((user) => (
          <label key={user.id} className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={selected.has(user.id)}
              onCheckedChange={(checked) => toggle(user.id, checked === true)}
              disabled={disabled}
            />
            <span>{user.display_name}</span>
            {user.restricted && <Badge variant="outline">Private</Badge>}
          </label>
        ))}
        {filtered.length === 0 && <p className="text-sm text-muted-foreground">No people found.</p>}
      </div>
    </div>
  );
}
