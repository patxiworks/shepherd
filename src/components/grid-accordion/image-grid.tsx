
import Image from 'next/image';
import type { ImageData } from '@/types';

interface ImageGridProps {
  images: ImageData[];
  onImageClick: (image: ImageData, index: number, allImages: ImageData[]) => void;
}

export function ImageGrid({ images, onImageClick }: ImageGridProps) {
  if (!images || images.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No images to display.</p>;
  }

  return (
    <div className="grid grid-cols-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 md:gap-4 pb-4">
      {images.map((image, index) => (
        <div 
          key={index} 
          className="aspect-square relative overflow-hidden rounded-sm shadow-sm group cursor-pointer"
          onClick={() => onImageClick(image, index, images)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') onImageClick(image, index, images);}}
          aria-label={`View image: ${image.alt}`}
        >
          <Image
            src={image.src}
            alt={image.alt}
            fill
            sizes="(max-width: 640px) 50vw, (max-width: 768px) 33vw, (max-width: 1024px) 25vw, 20vw"
            className="object-cover transition-transform duration-300 ease-in-out group-hover:scale-105"
            data-ai-hint={image.hint || 'photo'}
            priority={index < 5} // Prioritize loading first few images
          />
        </div>
      ))}
    </div>
  );
}
