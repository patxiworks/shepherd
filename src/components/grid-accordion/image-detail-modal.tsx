
"use client";

import * as React from 'react';
import Image from 'next/image';
import type { DialogProps } from '@radix-ui/react-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight, Trash2, XIcon } from 'lucide-react';
import type { ImageData } from '@/types';
import { cn } from '@/lib/utils';
import { DeleteImageConfirmModal } from './delete-image-confirm-modal'; // Import the new modal

interface ImageDetailModalProps extends DialogProps {
  images: ImageData[] | null;
  initialIndex: number | null;
  onOpenChange: (open: boolean) => void;
  onDeleteImage?: (imageSrc: string) => void;
  isUserLoggedIn?: boolean;
}

export function ImageDetailModal({
  isOpen,
  onOpenChange,
  images,
  initialIndex,
  onDeleteImage,
  isUserLoggedIn
}: ImageDetailModalProps) {
  const [currentIndex, setCurrentIndex] = React.useState<number | null>(initialIndex);
  const [isDeleteImageConfirmOpen, setIsDeleteImageConfirmOpen] = React.useState(false);
  const [imageSrcToDelete, setImageSrcToDelete] = React.useState<string | null>(null);

  React.useEffect(() => {
    setCurrentIndex(initialIndex);
  }, [initialIndex, images]);

  React.useEffect(() => {
    if (!isOpen) {
      setIsDeleteImageConfirmOpen(false); // Ensure confirm modal is closed when main modal closes
      setImageSrcToDelete(null);
      return;
    }

    if (!images || images.length === 0) {
      onOpenChange(false);
      return;
    }
    if (currentIndex !== null && currentIndex >= images.length) {
      setCurrentIndex(images.length > 0 ? images.length - 1 : 0);
    } else if (currentIndex === null && images.length > 0) {
      setCurrentIndex(0);
    }
  }, [images, currentIndex, isOpen, onOpenChange]);


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

  const handleDeleteRequest = () => {
    if (images && currentIndex !== null) {
      setImageSrcToDelete(images[currentIndex].src);
      setIsDeleteImageConfirmOpen(true);
    }
  };

  const handleConfirmDeleteImage = () => {
    if (onDeleteImage && imageSrcToDelete) {
      onDeleteImage(imageSrcToDelete);
    }
    // The DeleteImageConfirmModal will call its onOpenChange to close itself.
    // ImageDetailModal might close if all images are deleted (handled in HomePage)
  };

  React.useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (!isOpen || !images || currentIndex === null || isDeleteImageConfirmOpen) return; // Ignore if confirm modal is open
      if (event.key === 'ArrowLeft') {
        handlePrevious();
      } else if (event.key === 'ArrowRight') {
        handleNext();
      } else if (event.key === 'Escape') {
        onOpenChange(false);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, currentIndex, images, onOpenChange, isDeleteImageConfirmOpen]);

  if (!isOpen || !images || images.length === 0 || currentIndex === null || currentIndex < 0 || currentIndex >= images.length) {
    return null;
  }

  const currentImage = images[currentIndex];

  return (
    <>
      <Dialog open={isOpen} onOpenChange={onOpenChange}>
        <DialogContent
          className="image-modal-content sm:max-w-3xl md:max-w-4xl lg:max-w-5xl xl:max-w-6xl p-0 max-h-[95vh] flex flex-col bg-transparent border-0 shadow-none"
          onPointerDownOutside={(e) => { if (!isDeleteImageConfirmOpen) e.preventDefault(); }}
        >
          <DialogHeader className="flex-shrink-0 px-4 pt-4 z-20 flex flex-row justify-center items-center relative">
            <DialogTitle className="text-lg sm:text-xl font-headline mb-1 sm:mb-2 truncate text-primary-foreground text-center mx-auto">
              {currentImage.alt || 'Image Detail'}
            </DialogTitle>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => onOpenChange(false)}
              className="absolute right-2 top-2 text-primary-foreground bg-black/30 hover:bg-black/50 h-8 w-8"
              aria-label="Close"
            >
              <XIcon className="h-5 w-5" />
            </Button>
          </DialogHeader>

          <div className="flex-grow relative mt-2 min-h-[300px] sm:min-h-[400px] md:min-h-[500px] lg:min-h-[600px] flex items-center justify-center p-1 sm:p-2">
            <Image
              key={currentImage.src}
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
                  className="absolute left-1 sm:left-2 top-1/2 -translate-y-1/2 z-30 text-primary-foreground bg-black/30 hover:bg-black/50 disabled:opacity-30 p-2 rounded-full h-10 w-10 sm:h-12 sm:w-12"
                  aria-label="Previous image"
                >
                  <ChevronLeft className="h-6 w-6 sm:h-8 sm:w-8" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={handleNext}
                  disabled={currentIndex === images.length - 1}
                  className="absolute right-1 sm:right-2 top-1/2 -translate-y-1/2 z-30 text-primary-foreground bg-black/30 hover:bg-black/50 disabled:opacity-30 p-2 rounded-full h-10 w-10 sm:h-12 sm:w-12"
                  aria-label="Next image"
                >
                  <ChevronRight className="h-6 w-6 sm:h-8 sm:w-8" />
                </Button>
              </>
            )}
          </div>

          <div className="flex-shrink-0 flex justify-between items-center p-2 sm:p-4 z-20">
            {isUserLoggedIn && onDeleteImage && (
              <Button
                variant="destructive"
                size="sm"
                onClick={handleDeleteRequest} // Changed to open confirm modal
                className="bg-destructive/80 hover:bg-destructive text-destructive-foreground"
                aria-label="Delete image"
              >
                <Trash2 className="h-4 w-4 mr-2" />
                Delete
              </Button>
            )}
            <div className={cn(
              "text-center text-sm text-primary-foreground/80",
              isUserLoggedIn && onDeleteImage ? "ml-auto" : "mx-auto"
            )}>
              {images.length > 1 ? `${currentIndex + 1} / ${images.length}` : ""}
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {isUserLoggedIn && onDeleteImage && (
        <DeleteImageConfirmModal
          isOpen={isDeleteImageConfirmOpen}
          onOpenChange={setIsDeleteImageConfirmOpen}
          onConfirmDelete={handleConfirmDeleteImage}
          imageAlt={currentImage?.alt}
        />
      )}
    </>
  );
}
