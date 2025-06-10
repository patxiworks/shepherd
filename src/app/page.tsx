
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import { AuthModal } from '@/components/grid-accordion/auth-modal';
import { ImageDetailModal } from '@/components/grid-accordion/image-detail-modal';
import { AddCollectionModal } from '@/components/grid-accordion/add-collection-modal';
import { EditCollectionModal } from '@/components/grid-accordion/edit-collection-modal';
import { DeleteConfirmModal } from '@/components/grid-accordion/delete-confirm-modal';
import { DioceseSummaryModal } from '@/components/grid-accordion/diocese-summary-modal';
import { StateSummaryModal } from '@/components/grid-accordion/state-summary-modal';
import type { AccordionItemData, ImageData, NewCollectionFormData as CollectionFormSubmitData, SummaryItem } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PlusCircle, Loader2 } from 'lucide-react';
import { format as formatDateFns } from 'date-fns';
import { nigerianDioceses } from '@/lib/nigerian-dioceses';
import { nigerianStates } from '@/lib/nigerian-states';

// Helper function to sort collections by date and then by time
const sortCollections = (a: AccordionItemData, b: AccordionItemData): number => {
  const currentYear = new Date().getFullYear();
  
  // Attempt to parse dates. Handle potential invalid date strings gracefully.
  let dateA = new Date(`${a.date} ${currentYear}`);
  let dateB = new Date(`${b.date} ${currentYear}`);

  // If parsing results in Invalid Date, treat them as very old dates to sort them consistently
  // or handle as per specific requirements (e.g., place them at the end).
  // For simplicity, if a date is invalid, it might sort unpredictably or at the start/end depending on NaN behavior.
  // A more robust solution would validate date strings upon entry or use a more reliable parsing.
  if (isNaN(dateA.getTime())) dateA = new Date(0); // Treat as epoch if invalid
  if (isNaN(dateB.getTime())) dateB = new Date(0);


  if (dateA.getTime() !== dateB.getTime()) {
    return dateA.getTime() - dateB.getTime();
  }

  const [hoursA, minutesA] = a.time.split(':').map(Number);
  const [hoursB, minutesB] = b.time.split(':').map(Number);

  if (hoursA !== hoursB) {
    return hoursA - hoursB;
  }
  return minutesA - minutesB;
};


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
  
  const [filterQuery, setFilterQuery] = React.useState('');

  const [isDioceseSummaryModalOpen, setIsDioceseSummaryModalOpen] = React.useState(false);
  const [isStateSummaryModalOpen, setIsStateSummaryModalOpen] = React.useState(false);

  const { toast } = useToast();

  React.useEffect(() => {
    const fetchCollections = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/collections');
        if (!response.ok) {
          throw new Error('Failed to fetch collections');
        }
        const data: AccordionItemData[] = await response.json();
        setAccordionItems(data.sort(sortCollections));
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

  const dioceseSummary = React.useMemo(() => {
    if (!accordionItems.length) {
      return { count: 0, total: nigerianDioceses.length, breakdown: [] as SummaryItem[] };
    }
    const diocesesInAccordion = accordionItems.map(item => item.diocese);
    const uniqueDiocesesWithItems = new Set(diocesesInAccordion);
    
    const breakdownMap = new Map<string, number>();
    diocesesInAccordion.forEach(diocese => {
      breakdownMap.set(diocese, (breakdownMap.get(diocese) || 0) + 1);
    });
    
    const breakdown = Array.from(breakdownMap.entries())
      .map(([name, count]) => ({ name, count }))
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name)); 
      
    return {
      count: uniqueDiocesesWithItems.size,
      total: nigerianDioceses.length,
      breakdown,
    };
  }, [accordionItems]);

  const stateSummary = React.useMemo(() => {
    if (!accordionItems.length) {
      return { count: 0, total: nigerianStates.length, breakdown: [] as SummaryItem[] };
    }
    const statesInAccordion = accordionItems.map(item => item.state).filter(Boolean); // Filter out empty/null states
    const uniqueStatesWithItems = new Set(statesInAccordion);
    
    const breakdownMap = new Map<string, number>();
    statesInAccordion.forEach(stateItem => {
      if (stateItem) { 
         breakdownMap.set(stateItem, (breakdownMap.get(stateItem) || 0) + 1);
      }
    });
    
    const breakdown = Array.from(breakdownMap.entries())
      .map(([name, count]) => ({ name, count }))
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
      
    return {
      count: uniqueStatesWithItems.size,
      total: nigerianStates.length,
      breakdown,
    };
  }, [accordionItems]);


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

  const handlePhotoUpload = (data: import('@/types').PhotoUploadFormData) => {
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
        ).sort(sortCollections) // Re-sort after adding an image locally, though this change isn't persisted
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
      state: formData.state,
      date: formattedDate,
      time: formData.time,
      images: [], 
    };

    // Optimistically add and sort
    setAccordionItems(prevItems => [...prevItems, newItem].sort(sortCollections));
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
      // Replace optimistic item with server response and re-sort
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === newItemId ? savedItem : item).sort(sortCollections)
      );

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
      // Revert optimistic update on error
      setAccordionItems(prevItems => prevItems.filter(item => item.id !== newItemId).sort(sortCollections));
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
    if (!originalItem) return; // Should not happen

    const formattedDate = formatDateFns(formData.date, "MMMM d");

    const itemWithUpdates: AccordionItemData = {
      ...editingItem,
      parishLocation: formData.parishLocation,
      diocese: formData.diocese,
      state: formData.state,
      date: formattedDate,
      time: formData.time,
    };
    
    // Optimistically update and sort
    setAccordionItems(prevItems =>
      prevItems.map(item =>
        item.id === editingItem.id ? itemWithUpdates : item
      ).sort(sortCollections)
    );
    setIsEditModalOpen(false);

    try {
      const payload = { 
        parishLocation: itemWithUpdates.parishLocation,
        diocese: itemWithUpdates.diocese,
        state: itemWithUpdates.state,
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
       // Replace optimistic item with server response and re-sort
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === editingItem.id ? { ...item, ...savedItem } : item).sort(sortCollections)
      );

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
      // Revert optimistic update on error
      setAccordionItems(prevItems => prevItems.map(item => item.id === editingItem.id ? originalItem : item).sort(sortCollections));
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
    const originalItems = [...accordionItems]; // Keep a copy for potential revert

    // Optimistically delete
    setAccordionItems(prevItems => prevItems.filter(item => item.id !== itemToDeleteId)); // No need to re-sort here
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
      // Revert optimistic delete on error by restoring and re-sorting original list
      setAccordionItems(originalItems.sort(sortCollections));
    } finally {
      setDeletingItem(null);
    }
  };

  const filteredAccordionItems = React.useMemo(() => {
    if (!filterQuery) {
      return accordionItems; // Already sorted
    }
    const lowercasedQuery = filterQuery.toLowerCase();
    return accordionItems.filter(item =>
      item.parishLocation.toLowerCase().includes(lowercasedQuery) ||
      item.diocese.toLowerCase().includes(lowercasedQuery) ||
      (item.state && item.state.toLowerCase().includes(lowercasedQuery)) ||
      item.date.toLowerCase().includes(lowercasedQuery) ||
      item.time.toLowerCase().includes(lowercasedQuery)
    ); // This filtered list maintains the original sort order
  }, [accordionItems, filterQuery]);

  const handleApplySummaryFilter = (filterTerm: string) => {
    setFilterQuery(filterTerm);
    setIsDioceseSummaryModalOpen(false);
    setIsStateSummaryModalOpen(false);
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
      <header className="mb-6 text-right">
        <h1 className="text-4xl md:text-5xl font-headline font-bold text-primary mb-3">
          Masses in honour of St. Josemaria, 2025
        </h1>
        <div className="text-md text-muted-foreground font-body flex justify-end items-center space-x-2">
          <Button variant="link" onClick={() => setIsDioceseSummaryModalOpen(true)} className="p-0 h-auto text-md">
            Dioceses ({dioceseSummary.count}/{dioceseSummary.total})
          </Button>
          <span>|</span>
          <Button variant="link" onClick={() => setIsStateSummaryModalOpen(true)} className="p-0 h-auto text-md">
            States ({stateSummary.count}/{stateSummary.total})
          </Button>
        </div>
      </header>

      <div className="mb-2 mt-10 flex flex-col sm:flex-row justify-between items-center gap-4">
        <Input
          type="text"
          placeholder="Filter collections..."
          value={filterQuery}
          onChange={(e) => setFilterQuery(e.target.value)}
          className="w-full sm:max-w-md h-10 text-base"
        />
        <Button onClick={openAddCollectionModal} variant="outline" className="text-primary border-primary hover:bg-primary/10 w-full sm:w-auto">
          <PlusCircle className="mr-2 h-5 w-5" />
          Add New Collection
        </Button>
      </div>
      
      <div className="text-right text-sm text-muted-foreground mb-4">
        {filterQuery
          ? `${filteredAccordionItems.length} of ${accordionItems.length} collections found`
          : `${accordionItems.length} collections`}
      </div>

      <GridAccordion 
        items={filteredAccordionItems} 
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

      <DioceseSummaryModal
        isOpen={isDioceseSummaryModalOpen}
        onOpenChange={setIsDioceseSummaryModalOpen}
        summaryData={dioceseSummary.breakdown}
        onApplyFilter={handleApplySummaryFilter}
      />

      <StateSummaryModal
        isOpen={isStateSummaryModalOpen}
        onOpenChange={setIsStateSummaryModalOpen}
        summaryData={stateSummary.breakdown}
        onApplyFilter={handleApplySummaryFilter}
      />
      
      <footer className="text-center mt-12 py-6 border-t border-border">
        <p className="text-sm text-muted-foreground">
          &copy; {new Date().getFullYear()} GridAccordion App. Built with Next.js & ShadCN UI.
        </p>
      </footer>
    </div>
  );
}
