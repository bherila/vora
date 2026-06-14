export interface ProfileOption {
  value: string;
  label: string;
}

export const USER_TYPE_OPTIONS: readonly ProfileOption[] = [
  { value: 'human', label: 'Human' },
  { value: 'furry', label: 'Furry' },
  { value: 'other', label: 'Other' },
];

export const GENDER_OPTIONS: readonly ProfileOption[] = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
];

export function allOptionValues(options: readonly ProfileOption[]): string[] {
  return options.map((option) => option.value);
}

export function hasProfileOptionValue(options: readonly ProfileOption[], value: string): boolean {
  return options.some((option) => option.value === value);
}

export function normalizeProfileSelections(options: readonly ProfileOption[], values: unknown): string[] {
  if (!Array.isArray(values)) {
    return [];
  }

  const allowedValues = new Set(options.map((option) => option.value));
  const normalized = values.filter((value): value is string => typeof value === 'string' && allowedValues.has(value));

  return normalized;
}
