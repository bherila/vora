import { Label } from '@/components/ui/label';
import { type Audience,AUDIENCE_WITH_SPECIFIC_OPTIONS } from '@/lib/audience';

import { UserPicker } from './UserPicker';

interface AudienceFieldProps {
  audience: Audience;
  onAudienceChange: (audience: Audience) => void;
  selectedUserIds: number[];
  onSelectedUserIdsChange: (ids: number[]) => void;
  disabled?: boolean;
  label?: string;
}

export function AudienceField({
  audience,
  onAudienceChange,
  selectedUserIds,
  onSelectedUserIdsChange,
  disabled = false,
  label = 'Who can see this?',
}: AudienceFieldProps) {
  return (
    <div className="grid gap-2">
      <Label htmlFor="audience-selector">{label}</Label>
      <select
        id="audience-selector"
        className="h-9 rounded-md border border-input bg-background px-2 text-sm"
        value={audience}
        onChange={(event) => onAudienceChange(event.target.value as Audience)}
        disabled={disabled}
      >
        {AUDIENCE_WITH_SPECIFIC_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
      {audience === 'specific' && (
        <UserPicker selectedIds={selectedUserIds} onChange={onSelectedUserIdsChange} disabled={disabled} />
      )}
    </div>
  );
}
