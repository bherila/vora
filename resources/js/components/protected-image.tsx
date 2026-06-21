import type { ImgHTMLAttributes, MouseEvent } from 'react';

import { cn } from '@/lib/utils';

type ProtectedImageProps = ImgHTMLAttributes<HTMLImageElement>;

export function ProtectedImage({ className, onContextMenu, ...props }: ProtectedImageProps) {
  const preventImageMenu = (event: MouseEvent<HTMLImageElement>): void => {
    event.preventDefault();
    onContextMenu?.(event);
  };

  return (
    <img
      {...props}
      draggable={false}
      onContextMenu={preventImageMenu}
      className={cn('select-none', className)}
    />
  );
}
