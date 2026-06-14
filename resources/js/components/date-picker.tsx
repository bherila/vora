import { CalendarIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

interface DatePickerProps {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  max?: string;
  placeholder?: string;
  disabled?: boolean;
}

function parseDateOnly(value: string): Date | undefined {
  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) {
    return undefined;
  }

  return new Date(year, month - 1, day);
}

function formatDateOnly(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

export function DatePicker({
  id,
  value,
  onChange,
  max,
  placeholder = 'Select date',
  disabled = false,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const selectedDate = value ? parseDateOnly(value) : undefined;
  const maxDate = max ? parseDateOnly(max) : undefined;
  const calendarProps: ComponentProps<typeof Calendar> = {
    mode: 'single',
    selected: selectedDate,
    onSelect: (date) => {
      if (date) {
        onChange(formatDateOnly(date));
        setOpen(false);
      }
    },
    captionLayout: 'dropdown',
    startMonth: new Date(1900, 0),
    ...(maxDate ? {
      disabled: { after: maxDate },
      endMonth: maxDate,
    } : {}),
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          disabled={disabled}
          className={cn('w-full justify-start text-left font-normal', !value && 'text-muted-foreground')}
        >
          <CalendarIcon className="size-4" />
          {value || placeholder}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          {...calendarProps}
        />
      </PopoverContent>
    </Popover>
  );
}
