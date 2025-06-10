
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import { AuthModal } from '@/components/grid-accordion/auth-modal';
import { ImageDetailModal } from '@/components/grid-accordion/image-detail-modal';
import { AddCollectionModal } from '@/components/grid-accordion/add-collection-modal';
import type { AccordionItemData, ImageData, PhotoUploadFormData, NewCollectionFormData } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { PlusCircle } from 'lucide-react';

const initialAccordionData: AccordionItemData[] = [
  {
    id: 'item-1',
    title: 'St. Matthew Chaplaincy, Regina Pacis College - Abuja - July 1 - 18:00',
    images: [],
  },
  {
    id: 'item-2',
    title: 'St. Bernadette, Akinogun, Shagari Estate, Ipaja, Lagos - Lagos - July 1 - 18:30',
    images: [],
  },
];

export default function HomePage() {
  const [accordionItems, setAccordionItems] = React.useState<AccordionItemData[]>(initialAccordionData);
  const [isAuthModalOpen, setIsAuthModalOpen] = React.useState(false);
  const [authModalStep, setAuthModalStep] = React.useState<'signIn' | 'upload'>('signIn');
  const [activeItemIdForUpload, setActiveItemIdForUpload] = React.useState<string | null>(null);
  const [activeItemTitleForUpload, setActiveItemTitleForUpload] = React.useState<string | null>(null);
  
  const [activeSlideshowImages, setActiveSlideshowImages] = React.useState<ImageData[] | null>(null);
  const [activeSlideshowIndex, setActiveSlideshowIndex] = React.useState<number | null>(null);
  const [isImageDetailModalOpen, setIsImageDetailModalOpen] = React.useState(false);

  const [isAddCollectionModalOpen, setIsAddCollectionModalOpen] = React.useState(false);
  
  const { toast } = useToast();

  const handleUploadRequest = (itemId: string, itemTitle: string) => {
    setActiveItemIdForUpload(itemId);
    setActiveItemTitleForUpload(itemTitle);
    setAuthModalStep('signIn');
    setIsAuthModalOpen(true);
  };

  const handleSignInSuccess = () => {
    setAuthModalStep('upload');
  };

  const handlePhotoUpload = (data: PhotoUploadFormData) => {
    if (activeItemIdForUpload && data.photo && data.photo.length > 0) {
      const newImage: ImageData = {
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
      });
    } else {
       toast({
        title: "Upload Failed",
        description: "No photo was selected or item ID is missing.",
        variant: "destructive",
      });
    }
    setIsAuthModalOpen(false);
  };
  
  const openAddCollectionModal = () => {
    setIsAddCollectionModalOpen(true);
  };

  const handleCreateNewCollection = (data: NewCollectionFormData) => {
    const newItemId = `item-${accordionItems.length + 1 + Math.random().toString(36).substring(7)}`; // Ensure unique ID
    const newTitle = `${data.parishLocation} - ${data.diocese} - ${data.date} - ${data.time}`;
    const newItem: AccordionItemData = {
      id: newItemId,
      title: newTitle,
      images: [],
    };
    setAccordionItems(prevItems => [...prevItems, newItem]);
    toast({
      title: "New Collection Added!",
      description: `"${newTitle}" is ready for photos.`,
    });
    setIsAddCollectionModalOpen(false);
  };
  
  const handleAuthModalOpenChange = (open: boolean) => {
    setIsAuthModalOpen(open);
    if (!open) {
      setAuthModalStep('signIn');
      setActiveItemIdForUpload(null);
      setActiveItemTitleForUpload(null);
    }
  };

  const handleImageClick = (image: ImageData, index: number, allImages: ImageData[]) => {
    setActiveSlideshowImages(allImages);
    setActiveSlideshowIndex(index);
    setIsImageDetailModalOpen(true);
  };

  const handleImageDetailModalOpenChange = (open: boolean) => {
    setIsImageDetailModalOpen(open);
    if (!open) {
      setActiveSlideshowImages(null);
      setActiveSlideshowIndex(null);
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
        <Button onClick={openAddCollectionModal} variant="outline" className="text-primary border-primary hover:bg-primary/10">
          <PlusCircle className="mr-2 h-5 w-5" />
          Add New Collection
        </Button>
      </div>

      <GridAccordion 
        items={accordionItems} 
        onUploadRequest={handleUploadRequest}
        onImageClick={handleImageClick} 
      />

      <AuthModal
        isOpen={isAuthModalOpen}
        onOpenChange={handleAuthModalOpenChange}
        currentStep={authModalStep}
        onSignInSuccess={handleSignInSuccess}
        onUploadSubmit={handlePhotoUpload}
        itemName={activeItemTitleForUpload || undefined}
      />

      <AddCollectionModal
        isOpen={isAddCollectionModalOpen}
        onOpenChange={setIsAddCollectionModalOpen}
        onSubmit={handleCreateNewCollection}
      />

      <ImageDetailModal
        isOpen={isImageDetailModalOpen}
        onOpenChange={handleImageDetailModalOpenChange}
        images={activeSlideshowImages}
        initialIndex={activeSlideshowIndex}
      />
      
      <footer className="text-center mt-12 py-6 border-t border-border">
        <p className="text-sm text-muted-foreground">
          &copy; {new Date().getFullYear()} GridAccordion App. Built with Next.js & ShadCN UI.
        </p>
      </footer>
    </div>
  );
}
