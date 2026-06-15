import { type ChangeEvent, type DragEvent, useId, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

interface FileDropzoneProps {
  accept: string;
  disabled?: boolean;
  files: File[];
  label: string;
  multiple?: boolean;
  onFilesChange: (files: File[]) => void;
  helperText?: string;
}

function formatFileList(files: File[]): string {
  if (files.length === 0) {
    return 'No files selected.';
  }

  const firstFile = files[0];
  if (files.length === 1 && firstFile) {
    return firstFile.name;
  }

  return `${files.length} files selected: ${files.map((file) => file.name).join(', ')}`;
}

function filesFromList(fileList: FileList | null, multiple: boolean): File[] {
  const files = Array.from(fileList ?? []);

  return multiple ? files : files.slice(0, 1);
}

export function FileDropzone({ accept, disabled = false, files, label, multiple = false, onFilesChange, helperText }: FileDropzoneProps) {
  const inputId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragActive, setDragActive] = useState(false);

  const openPicker = (): void => {
    if (!disabled) {
      inputRef.current?.click();
    }
  };

  const handleChange = (event: ChangeEvent<HTMLInputElement>): void => {
    onFilesChange(filesFromList(event.target.files, multiple));
    event.target.value = '';
  };

  const handleDragOver = (event: DragEvent<HTMLButtonElement>): void => {
    event.preventDefault();
    if (!disabled) {
      setDragActive(true);
    }
  };

  const handleDrop = (event: DragEvent<HTMLButtonElement>): void => {
    event.preventDefault();
    setDragActive(false);
    if (!disabled) {
      onFilesChange(filesFromList(event.dataTransfer.files, multiple));
    }
  };

  return (
    <div className="grid gap-2">
      <input
        ref={inputRef}
        id={inputId}
        type="file"
        accept={accept}
        multiple={multiple}
        disabled={disabled}
        onChange={handleChange}
        className="sr-only"
      />
      <button
        type="button"
        aria-describedby={`${inputId}-selected`}
        disabled={disabled}
        onClick={openPicker}
        onDragEnter={handleDragOver}
        onDragOver={handleDragOver}
        onDragLeave={() => setDragActive(false)}
        onDrop={handleDrop}
        className={cn(
          'rounded-lg border border-dashed border-border bg-muted/30 px-4 py-8 text-center transition hover:border-primary hover:bg-muted/50 focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:pointer-events-none disabled:opacity-50',
          dragActive && 'border-primary bg-muted/60',
        )}
      >
        <span className="block font-medium">{label}</span>
        <span className="mt-1 block text-sm text-muted-foreground">{helperText ?? 'Drag and drop here, or click to browse.'}</span>
        <span className="mt-4 inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium shadow-xs">
          Browse files
        </span>
      </button>
      <p id={`${inputId}-selected`} className="text-sm text-muted-foreground">
        {formatFileList(files)}
      </p>
    </div>
  );
}
