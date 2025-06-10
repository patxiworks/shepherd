
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import { AuthModal } from '@/components/grid-accordion/auth-modal';
import { ImageDetailModal } from '@/components/grid-accordion/image-detail-modal';
import { AddCollectionModal } from '@/components/grid-accordion/add-collection-modal';
import { EditCollectionModal } from '@/components/grid-accordion/edit-collection-modal';
import { DeleteConfirmModal } from '@/components/grid-accordion/delete-confirm-modal';
import type { AccordionItemData, ImageData, PhotoUploadFormData, NewCollectionFormData } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { PlusCircle } from 'lucide-react';

const initialAccordionData: AccordionItemData[] = [
  {
    id: 'item-1',
    parishLocation: 'St. Matthew Chaplaincy, Regina Pacis College',
    diocese: 'Abuja',
    date: 'July 1',
    time: '18:00',
    images: [],
  },
  {
    id: 'item-2',
    parishLocation: 'St. Bernadette, Akinogun, Shagari Estate, Ipaja, Lagos',
    diocese: 'Lagos',
    date: 'July 1',
    time: '18:30',
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
  const [isEditModalOpen, setIsEditModalOpen] = React.useState(false);
  const [editingItem, setEditingItem] = React.useState<AccordionItemData | null>(null);
  const [isDeleteConfirmModalOpen, setIsDeleteConfirmModalOpen] = React.useState(false);
  const [deletingItem, setDeletingItem] = React.useState<AccordionItemData | null>(null);
  
  const { toast } = useToast();

  const handleUploadRequest = (item: AccordionItemData) => {
    setActiveItemIdForUpload(item.id);
    const displayTitle = `${item.parishLocation}${item.diocese ? ` - ${item.diocese}` : ''}`;
    setActiveItemTitleForUpload(displayTitle);
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
    const newItemId = `item-${Date.now()}-${Math.random().toString(36).substring(7)}`;
    const newItem: AccordionItemData = {
      id: newItemId,
      ...data,
      images: [],
    };
    setAccordionItems(prevItems => [...prevItems, newItem]);
    const displayTitle = `${data.parishLocation}${data.diocese ? ` - ${data.diocese}` : ''}`;
    toast({
      title: "New Collection Added!",
      description: `"${displayTitle}" is ready for photos.`,
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

  const handleEditRequest = (item: AccordionItemData) => {
    setEditingItem(item);
    setIsEditModalOpen(true);
  };

  const handleUpdateCollection = (updatedData: NewCollectionFormData) => {
    if (!editingItem) return;
    setAccordionItems(prevItems =>
      prevItems.map(item =>
        item.id === editingItem.id ? { ...item, ...updatedData } : item
      )
    );
    const displayTitle = `${updatedData.parishLocation}${updatedData.diocese ? ` - ${updatedData.diocese}` : ''}`;
    toast({
      title: "Collection Updated!",
      description: `"${displayTitle}" has been updated.`,
    });
    setIsEditModalOpen(false);
    setEditingItem(null);
  };

  const handleDeleteRequest = (item: AccordionItemData) => {
    setDeletingItem(item);
    setIsDeleteConfirmModalOpen(true);
  };

  const handleConfirmDelete = () => {
    if (!deletingItem) return;
    setAccordionItems(prevItems => prevItems.filter(item => item.id !== deletingItem.id));
    const displayTitle = `${deletingItem.parishLocation}${deletingItem.diocese ? ` - ${deletingItem.diocese}` : ''}`;
    toast({
      title: "Collection Deleted",
      description: `"${displayTitle}" has been removed.`,
      variant: "destructive" 
    });
    setIsDeleteConfirmModalOpen(false);
    setDeletingItem(null);
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
        onEditRequest={handleEditRequest}
        onDeleteRequest={handleDeleteRequest}
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

      {editingItem && (
        <EditCollectionModal
          isOpen={isEditModalOpen}
          onOpenChange={(open) => {
            setIsEditModalOpen(open);
            if (!open) setEditingItem(null);
          }}
          onSubmit={handleUpdateCollection}
          initialData={editingItem}
        />
      )}

      {deletingItem && (
        <DeleteConfirmModal
          isOpen={isDeleteConfirmModalOpen}
          onOpenChange={(open) => {
            setIsDeleteConfirmModalOpen(open);
            if (!open) setDeletingItem(null);
          }}
          onConfirmDelete={handleConfirmDelete}
          itemName={`${deletingItem.parishLocation}${deletingItem.diocese ? ` - ${deletingItem.diocese}` : ''}`}
        />
      )}
      
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
