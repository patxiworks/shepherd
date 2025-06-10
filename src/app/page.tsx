"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import { AuthModal } from '@/components/grid-accordion/auth-modal';
import type { AccordionItemData, ImageData, PhotoUploadFormData } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { PlusCircle } from 'lucide-react';

const initialAccordionData: AccordionItemData[] = [
  {
    id: 'item-1',
    title: 'Summer Vacation Snaps',
    images: [
      { src: 'https://placehold.co/600x400.png', alt: 'Beach view', hint: 'beach sunset' },
      { src: 'https://placehold.co/600x600.png', alt: 'Mountain hike', hint: 'mountain trail' },
      { src: 'https://placehold.co/400x600.png', alt: 'City skyline', hint: 'city night' },
      { src: 'https://placehold.co/600x400.png', alt: 'Forest path', hint: 'forest trees' },
      { src: 'https://placehold.co/600x400.png', alt: 'Desert landscape', hint: 'desert dunes' },
    ],
  },
  {
    id: 'item-2',
    title: 'Winter Wonderland',
    images: [
      { src: 'https://placehold.co/600x400.png', alt: 'Snowy cabin', hint: 'snow cabin' },
      { src: 'https://placehold.co/400x400.png', alt: 'Frozen lake', hint: 'frozen lake' },
      { src: 'https://placehold.co/600x400.png', alt: 'Ski slope', hint: 'ski slope' },
    ],
  },
  {
    id: 'item-3',
    title: 'Urban Exploration',
    images: [
      { src: 'https://placehold.co/600x600.png', alt: 'Street art', hint: 'street graffiti' },
      { src: 'https://placehold.co/600x400.png', alt: 'Old building', hint: 'historic architecture' },
      { src: 'https://placehold.co/400x600.png', alt: 'Busy market', hint: 'market people' },
      { src: 'https://placehold.co/600x400.png', alt: 'Modern skyscraper', hint: 'modern building' },
    ],
  },
];

export default function HomePage() {
  const [accordionItems, setAccordionItems] = React.useState<AccordionItemData[]>(initialAccordionData);
  const [isModalOpen, setIsModalOpen] = React.useState(false);
  const [modalStep, setModalStep] = React.useState<'signIn' | 'upload'>('signIn');
  const [activeItemIdForUpload, setActiveItemIdForUpload] = React.useState<string | null>(null);
  const [activeItemTitleForUpload, setActiveItemTitleForUpload] = React.useState<string | null>(null);
  const { toast } = useToast();

  const handleUploadRequest = (itemId: string, itemTitle: string) => {
    setActiveItemIdForUpload(itemId);
    setActiveItemTitleForUpload(itemTitle);
    setModalStep('signIn'); // Always start with sign-in
    setIsModalOpen(true);
  };

  const handleSignInSuccess = () => {
    setModalStep('upload');
    // Modal remains open
  };

  const handlePhotoUpload = (data: PhotoUploadFormData) => {
    console.log('Photo upload data:', data);
    if (activeItemIdForUpload && data.photo && data.photo.length > 0) {
      const newImage: ImageData = {
        // For prototype, using placeholder. In real app, this would be uploaded URL
        src: URL.createObjectURL(data.photo[0]), 
        alt: data.title,
        hint: data.title.toLowerCase().split(' ').slice(0,2).join(' '),
      };

      setAccordionItems(prevItems =>
        prevItems.map(item =>
          item.id === activeItemIdForUpload
            ? { ...item, images: [...item.images, newImage] }
            : item
        )
      );
      toast({
        title: "Photo Uploaded!",
        description: `"${data.title}" has been added to ${activeItemTitleForUpload || 'the gallery'}.`,
        variant: "default",
      });
    } else {
       toast({
        title: "Upload Failed",
        description: "No photo was selected or item ID is missing.",
        variant: "destructive",
      });
    }
    setIsModalOpen(false);
    // Optionally reset activeItemIdForUpload and activeItemTitleForUpload here if needed
    // setActiveItemIdForUpload(null);
    // setActiveItemTitleForUpload(null);
  };
  
  const handleAddNewAccordionItem = () => {
    const newItemId = `item-${accordionItems.length + 1}`;
    const newItem: AccordionItemData = {
      id: newItemId,
      title: `New Collection ${accordionItems.length + 1}`,
      images: [],
    };
    setAccordionItems(prevItems => [...prevItems, newItem]);
    toast({
      title: "New Collection Added!",
      description: `"${newItem.title}" is ready for photos.`,
    });
  };
  
  const handleModalOpenChange = (open: boolean) => {
    setIsModalOpen(open);
    if (!open) {
      // Reset to signIn step when modal is closed externally
      // This ensures that if the user closes the modal (e.g. by pressing Esc or clicking outside)
      // while on the 'upload' step, it will revert to 'signIn' for the next opening.
      setModalStep('signIn');
      setActiveItemIdForUpload(null);
      setActiveItemTitleForUpload(null);
    }
  };


  return (
    <div className="container mx-auto px-4 py-8 min-h-screen">
      <header className="mb-10 text-center">
        <h1 className="text-5xl font-headline font-bold text-primary mb-3">
          GridAccordion
        </h1>
        <p className="text-xl text-muted-foreground font-body">
          Your Photo Collections, Beautifully Organized
        </p>
      </header>

      <div className="mb-8 flex justify-end">
        <Button onClick={handleAddNewAccordionItem} variant="outline" className="text-primary border-primary hover:bg-primary/10">
          <PlusCircle className="mr-2 h-5 w-5" />
          Add New Collection
        </Button>
      </div>

      <GridAccordion items={accordionItems} onUploadRequest={handleUploadRequest} />

      <AuthModal
        isOpen={isModalOpen}
        onOpenChange={handleModalOpenChange}
        currentStep={modalStep}
        onSignInSuccess={handleSignInSuccess}
        onUploadSubmit={handlePhotoUpload}
        itemName={activeItemTitleForUpload || undefined}
      />
      
      <footer className="text-center mt-12 py-6 border-t border-border">
        <p className="text-sm text-muted-foreground">
          &copy; {new Date().getFullYear()} GridAccordion App. Built with Next.js & ShadCN UI.
        </p>
      </footer>
    </div>
  );
}
