import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { ProfileOption } from '@/profile-options';

interface ProfileOptionButtonGroupProps {
  legend: string;
  description?: string;
  name: string;
  options: readonly ProfileOption[];
  value: string;
  onChange: (value: string) => void;
}

interface ProfileOptionCheckboxGroupProps {
  legend: string;
  description?: string;
  name: string;
  options: readonly ProfileOption[];
  values: string[];
  onChange: (values: string[]) => void;
}

function optionId(name: string, value: string): string {
  return `${name}-${value}`;
}

function nextValues(values: string[], value: string, checked: boolean): string[] {
  if (checked) {
    return values.includes(value) ? values : [...values, value];
  }

  return values.filter((currentValue) => currentValue !== value);
}

export function ProfileOptionButtonGroup({
  legend,
  description,
  name,
  options,
  value,
  onChange,
}: ProfileOptionButtonGroupProps) {
  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">{legend}</legend>
      {description && <p className="text-xs text-muted-foreground">{description}</p>}
      <div className="grid gap-2 sm:grid-cols-3">
        {options.map((option) => {
          const isSelected = value === option.value;

          return (
            <button
              key={option.value}
              type="button"
              id={optionId(name, option.value)}
              aria-pressed={isSelected}
              onClick={() => onChange(option.value)}
              className={cn(
                'min-h-10 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] focus-visible:outline-none',
                isSelected && 'border-primary bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground',
              )}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </fieldset>
  );
}

export function ProfileOptionCheckboxGroup({
  legend,
  description,
  name,
  options,
  values,
  onChange,
}: ProfileOptionCheckboxGroupProps) {
  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">{legend}</legend>
      {description && <p className="text-xs text-muted-foreground">{description}</p>}
      <div className="grid gap-2 sm:grid-cols-3">
        {options.map((option) => {
          const id = optionId(name, option.value);
          const isSelected = values.includes(option.value);

          return (
            <Label
              key={option.value}
              htmlFor={id}
              className={cn(
                'flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground',
                isSelected && 'border-primary bg-primary/10 text-primary',
              )}
            >
              <Checkbox
                id={id}
                checked={isSelected}
                onCheckedChange={(checked) => onChange(nextValues(values, option.value, checked === true))}
              />
              {option.label}
            </Label>
          );
        })}
      </div>
    </fieldset>
  );
}
