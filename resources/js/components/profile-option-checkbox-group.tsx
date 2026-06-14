import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ProfileOption } from '@/profile-options';

interface ProfileOptionCheckboxGroupProps {
  legend: string;
  description?: string;
  name: string;
  options: readonly ProfileOption[];
  values: string[];
  onChange: (values: string[]) => void;
}

function nextValues(values: string[], value: string, checked: boolean): string[] {
  if (checked) {
    return values.includes(value) ? values : [...values, value];
  }

  return values.filter((currentValue) => currentValue !== value);
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
          const id = `${name}-${option.value}`;

          return (
            <Label
              key={option.value}
              htmlFor={id}
              className="flex cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <Checkbox
                id={id}
                checked={values.includes(option.value)}
                onCheckedChange={(checked) => onChange(nextValues(values, option.value, checked))}
              />
              {option.label}
            </Label>
          );
        })}
      </div>
    </fieldset>
  );
}
