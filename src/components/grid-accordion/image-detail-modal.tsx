
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
      <DialogContent className="image-modal-content sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-0 max-h-[90vh] flex flex-col bg-transparent border-0 shadow-none">
        <DialogHeader className="flex-shrink-0 px-4 pt-4 z-10">
          <DialogTitle className="text-lg sm:text-xl font-headline mb-1 sm:mb-2 truncate text-primary-foreground">
            {image.alt || 'Image Detail'}
          </DialogTitle>
        </DialogHeader>
        <div className="flex-grow relative mt-2 min-h-[200px] sm:min-h-[300px] md:min-h-[400px] p-4">
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
