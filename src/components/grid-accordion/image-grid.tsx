import Image from 'next/image';
import type { ImageData } from '@/types';

interface ImageGridProps {
  images: ImageData[];
}

export function ImageGrid({ images }: ImageGridProps) {
  if (!images || images.length === 0) {
    return <p className="text-muted-foreground py-4">No images to display.</p>;
  }

  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 md:gap-4 py-4">
      {images.map((image, index) => (
        <div key={index} className="aspect-square relative overflow-hidden rounded-lg shadow-md">
          <Image
            src={image.src}
            alt={image.alt}
            fill
            sizes="(max-width: 640px) 50vw, (max-width: 768px) 33vw, (max-width: 1024px) 25vw, 20vw"
            className="object-cover transition-transform duration-300 ease-in-out hover:scale-105"
            data-ai-hint={image.hint || 'photo'}
            priority={index < 5} // Prioritize loading first few images
          />
        </div>
      ))}
    </div>
  );
}
