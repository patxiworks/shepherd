
"use client";

import Image from 'next/image';
import type { DialogProps } from '@radix-ui/react-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { ImageData } from '@/types';

interface ImageDetailModalProps extends DialogProps {
  image: ImageData | null;
  onOpenChange: (open: boolean) => void;
}

export function ImageDetailModal({ isOpen, onOpenChange, image }: ImageDetailModalProps) {
  if (!image) {
    return null;
  }

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-2 sm:p-4 max-h-[90vh] flex flex-col bg-card rounded-lg shadow-xl">
        <DialogHeader className="flex-shrink-0 px-2 pt-2 sm:px-4 sm:pt-4">
          <DialogTitle className="text-lg sm:text-xl font-headline mb-1 sm:mb-2 truncate">
            {image.alt || 'Image Detail'}
          </DialogTitle>
        </DialogHeader>
        <div className="flex-grow relative mt-2 min-h-[200px] sm:min-h-[300px] md:min-h-[400px]">
          <Image
            src={image.src}
            alt={image.alt}
            fill
            sizes="90vw"
            className="object-contain"
            priority
            data-ai-hint={image.hint || 'photo detail'}
          />
        </div>
      </DialogContent>
    </Dialog>
  );
}
