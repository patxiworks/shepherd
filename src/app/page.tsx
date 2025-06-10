
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import { AuthModal } from '@/components/grid-accordion/auth-modal';
import { ImageDetailModal } from '@/components/grid-accordion/image-detail-modal';
import { AddCollectionModal } from '@/components/grid-accordion/add-collection-modal';
import { EditCollectionModal } from '@/components/grid-accordion/edit-collection-modal';
import { DeleteConfirmModal } from '@/components/grid-accordion/delete-confirm-modal';
import type { AccordionItemData, ImageData, NewCollectionFormData as CollectionFormSubmitData } from '@/types'; // Updated type name
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { PlusCircle, Loader2 } from 'lucide-react';
import { format as formatDateFns } from 'date-fns';

export default function HomePage() {
  const [accordionItems, setAccordionItems] = React.useState<AccordionItemData[]>([]);
  const [isLoading, setIsLoading] = React.useState(true);
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

  React.useEffect(() => {
    const fetchCollections = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/collections');
        if (!response.ok) {
          throw new Error('Failed to fetch collections');
        }
        const data = await response.json();
        setAccordionItems(data);
      } catch (error) {
        console.error("Error fetching collections:", error);
        toast({
          title: "Error",
          description: "Could not load collections data.",
          variant: "destructive",
        });
      } finally {
        setIsLoading(false);
      }
    };
    fetchCollections();
  }, [toast]);

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

  const handlePhotoUpload = (data: import('@/types').PhotoUploadFormData) => { // Use PhotoUploadFormData from types
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
        title: "Photo Added Locally!",
        description: `"${data.title}" has been added to ${activeItemTitleForUpload || 'the gallery'} for this session. It will not be saved permanently.`,
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

  const handleCreateNewCollection = async (formData: CollectionFormSubmitData) => {
    const newItemId = `item-${Date.now()}-${Math.random().toString(36).substring(7)}`;
    
    const formattedDate = formatDateFns(formData.date, "MMMM d");

    const newItem: AccordionItemData = {
      id: newItemId,
      parishLocation: formData.parishLocation,
      diocese: formData.diocese,
      state: formData.state, // Add state
      date: formattedDate,
      time: formData.time,
      images: [], 
    };

    setAccordionItems(prevItems => [...prevItems, newItem]);
    setIsAddCollectionModalOpen(false); 

    try {
      const response = await fetch('/api/collections', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newItem),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to save new collection');
      }
      
      const savedItem = await response.json();
      setAccordionItems(prevItems => prevItems.map(item => item.id === newItemId ? savedItem : item));

      const displayTitle = `${formData.parishLocation}${formData.diocese ? ` - ${formData.diocese}` : ''}`;
      toast({
        title: "New Collection Added!",
        description: `"${displayTitle}" has been saved.`,
      });

    } catch (error) {
      console.error("Error creating collection:", error);
      toast({
        title: "Save Error",
        description: (error as Error).message || "Could not save new collection to the server.",
        variant: "destructive",
      });
      setAccordionItems(prevItems => prevItems.filter(item => item.id !== newItemId));
    }
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

  const handleUpdateCollection = async (formData: CollectionFormSubmitData) => {
    if (!editingItem) return;

    const originalItem = accordionItems.find(item => item.id === editingItem.id);
    if (!originalItem) return;

    const formattedDate = formatDateFns(formData.date, "MMMM d");

    const itemWithUpdates: AccordionItemData = {
      ...editingItem,
      parishLocation: formData.parishLocation,
      diocese: formData.diocese,
      state: formData.state, // Add state
      date: formattedDate,
      time: formData.time,
    };
    
    setAccordionItems(prevItems =>
      prevItems.map(item =>
        item.id === editingItem.id ? itemWithUpdates : item
      )
    );
    setIsEditModalOpen(false);

    try {
      const payload = { 
        parishLocation: itemWithUpdates.parishLocation,
        diocese: itemWithUpdates.diocese,
        state: itemWithUpdates.state, // Add state to payload
        date: itemWithUpdates.date,
        time: itemWithUpdates.time,
        images: itemWithUpdates.images.filter(img => !img.src.startsWith('blob:'))
      };

      const response = await fetch(`/api/collections/${editingItem.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to update collection');
      }
      
      const savedItem = await response.json();
      setAccordionItems(prevItems => prevItems.map(item => item.id === editingItem.id ? { ...item, ...savedItem } : item));

      const displayTitle = `${formData.parishLocation}${formData.diocese ? ` - ${formData.diocese}` : ''}`;
      toast({
        title: "Collection Updated!",
        description: `"${displayTitle}" has been saved.`,
      });
    } catch (error) {
      console.error("Error updating collection:", error);
      toast({
        title: "Update Error",
        description: (error as Error).message || "Could not save updates to the server.",
        variant: "destructive",
      });
      setAccordionItems(prevItems => prevItems.map(item => item.id === editingItem.id ? originalItem : item));
    } finally {
      setEditingItem(null);
    }
  };

  const handleDeleteRequest = (item: AccordionItemData) => {
    setDeletingItem(item);
    setIsDeleteConfirmModalOpen(true);
  };

  const handleConfirmDelete = async () => {
    if (!deletingItem) return;

    const itemToDeleteId = deletingItem.id;
    const originalItems = [...accordionItems];

    setAccordionItems(prevItems => prevItems.filter(item => item.id !== itemToDeleteId));
    setIsDeleteConfirmModalOpen(false);
    
    try {
      const response = await fetch(`/api/collections/${itemToDeleteId}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to delete collection');
      }
      const displayTitle = `${deletingItem.parishLocation}${deletingItem.diocese ? ` - ${deletingItem.diocese}` : ''}`;
      toast({
        title: "Collection Deleted",
        description: `"${displayTitle}" has been removed from the server.`,
      });
    } catch (error) {
      console.error("Error deleting collection:", error);
      toast({
        title: "Delete Error",
        description: (error as Error).message || "Could not delete collection from the server.",
        variant: "destructive",
      });
      setAccordionItems(originalItems);
    } finally {
      setDeletingItem(null);
    }
  };

  if (isLoading) {
    return (
      <div className="container mx-auto px-4 py-8 min-h-screen flex flex-col items-center justify-center">
        <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
        <p className="text-xl text-muted-foreground">Loading collections...</p>
      </div>
    );
  }

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
