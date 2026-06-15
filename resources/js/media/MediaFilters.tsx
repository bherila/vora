import { InterestPicker } from '@/components/interest-picker';
import { Button } from '@/components/ui/button';
import type { MediaTypeFilter } from '@/media/types';

interface MediaFiltersProps {
  type: MediaTypeFilter;
  onTypeChange: (next: MediaTypeFilter) => void;
  interestIds: number[];
  onInterestIdsChange: (next: number[]) => void;
  disabled?: boolean;
}

const TYPE_OPTIONS: ReadonlyArray<{ value: MediaTypeFilter; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'photo', label: 'Photos' },
  { value: 'video', label: 'Videos' },
];

/**
 * The shared discovery controls for any unified media listing: a photo/video
 * type toggle and an interest multi-select. Photos and videos live in one view;
 * the toggle narrows the same list rather than switching between sections.
 */
export function MediaFilters({
  type,
  onTypeChange,
  interestIds,
  onInterestIdsChange,
  disabled = false,
}: MediaFiltersProps) {
  return (
    <div className="mb-6 grid gap-4">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by type">
        {TYPE_OPTIONS.map((option) => (
          <Button
            key={option.value}
            type="button"
            size="sm"
            variant={type === option.value ? 'default' : 'outline'}
            aria-pressed={type === option.value}
            disabled={disabled}
            onClick={() => onTypeChange(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>
      <div className="grid gap-1">
        <span className="text-sm text-muted-foreground">Filter by interest</span>
        <InterestPicker value={interestIds} onChange={onInterestIdsChange} disabled={disabled} />
      </div>
    </div>
  );
}
