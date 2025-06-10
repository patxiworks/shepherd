
"use client";

import * as React from 'react';
import Image from 'next/image';
import type { DialogProps } from '@radix-ui/react-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogClose } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ImageData } from '@/types';

interface ImageDetailModalProps extends DialogProps {
  images: ImageData[] | null;
  initialIndex: number | null;
  onOpenChange: (open: boolean) => void;
}

export function ImageDetailModal({ isOpen, onOpenChange, images, initialIndex }: ImageDetailModalProps) {
  const [currentIndex, setCurrentIndex] = React.useState<number | null>(initialIndex);

  React.useEffect(() => {
    setCurrentIndex(initialIndex);
  }, [initialIndex, images]);

  const handlePrevious = () => {
    if (images && currentIndex !== null && currentIndex > 0) {
      setCurrentIndex(currentIndex - 1);
    }
  };

  const handleNext = () => {
    if (images && currentIndex !== null && currentIndex < images.length - 1) {
      setCurrentIndex(currentIndex + 1);
    }
  };

  // Keyboard navigation - Moved before early return to respect Rules of Hooks
  React.useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (!isOpen || !images || currentIndex === null) return; // Guard clause inside the effect
      if (event.key === 'ArrowLeft') {
        handlePrevious();
      } else if (event.key === 'ArrowRight') {
        handleNext();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, currentIndex, images]); // Dependencies remain the same

  if (!images || images.length === 0 || currentIndex === null || currentIndex < 0 || currentIndex >= images.length) {
    return null;
  }

  const currentImage = images[currentIndex];

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="image-modal-content sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-0 max-h-[90vh] flex flex-col bg-transparent border-0 shadow-none">
        <DialogHeader className="flex-shrink-0 px-4 pt-4 z-10 flex flex-row justify-between items-center">
          <DialogTitle className="text-lg sm:text-xl font-headline mb-1 sm:mb-2 truncate text-primary-foreground">
            {currentImage.alt || 'Image Detail'}
          </DialogTitle>
          {/* DialogClose is automatically added by DialogContent but we can style its wrapper if needed */}
        </DialogHeader>
        <div className="flex-grow relative mt-2 min-h-[200px] sm:min-h-[300px] md:min-h-[400px] p-4 flex items-center justify-center">
          <Image
            src={currentImage.src}
            alt={currentImage.alt}
            fill
            sizes="90vw"
            className="object-contain"
            priority
            data-ai-hint={currentImage.hint || 'photo detail'}
          />
          {images.length > 1 && (
            <>
              <Button
                variant="ghost"
                size="icon"
                onClick={handlePrevious}
                disabled={currentIndex === 0}
                className="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 z-20 text-primary-foreground bg-black/30 hover:bg-black/50 disabled:opacity-30"
                aria-label="Previous image"
              >
                <ChevronLeft className="h-8 w-8" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                onClick={handleNext}
                disabled={currentIndex === images.length - 1}
                className="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 z-20 text-primary-foreground bg-black/30 hover:bg-black/50 disabled:opacity-30"
                aria-label="Next image"
              >
                <ChevronRight className="h-8 w-8" />
              </Button>
            </>
          )}
        </div>
         {images.length > 1 && (
          <div className="text-center py-2 text-sm text-primary-foreground/80 z-10">
            {currentIndex + 1} / {images.length}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
