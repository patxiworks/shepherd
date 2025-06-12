
import type { AccordionItemData, ImageData } from '@/types';
import { ImageGrid } from './image-grid';
import { Button } from '@/components/ui/button';
import { Camera } from 'lucide-react';

interface GridAccordionItemContentProps {
  item: AccordionItemData;
  onUploadClick: () => void;
  onImageClick: (image: ImageData, index: number, allImages: ImageData[], collectionId: string) => void;
}

export function GridAccordionItemContent({ item, onUploadClick, onImageClick }: GridAccordionItemContentProps) {
  return (
    <div className="px-1 py-2 md:px-2">
      <ImageGrid 
        images={item.images} 
        onImageClick={(image, index) => onImageClick(image, index, item.images, item.id)} 
      />
      <div className="mt-4 flex justify-center md:justify-end">
        <Button onClick={onUploadClick} className="bg-primary hover:bg-primary/90 text-accent-foreground rounded-full shadow-lg px-6 py-3 text-base">
          <Camera className="mr-2 h-5 w-5" />
          Upload New Photo
        </Button>
      </div>
    </div>
  );
}
