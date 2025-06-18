
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
import { LoginModal } from '@/components/auth/login-modal';
import { NigerianMapModal } from '@/components/map/nigerian-map-modal';
import { GhanaMapModal } from '@/components/map/ghana-map-modal';
import type { AccordionItemData, ImageData, NewCollectionFormData as CollectionFormSubmitData, PhotoUploadFormData, SummaryItem, LoginFormData as AdminLoginFormData, MassesPerState } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { PlusCircle, Loader2, LogIn, LogOut, XIcon, MapIcon, ChevronDown } from 'lucide-react';
import { format as formatDateFns } from 'date-fns';
import { nigerianDioceses } from '@/lib/nigerian-dioceses';
import { nigerianStates } from '@/lib/nigerian-states';
import { nigerianMap } from '@/lib/nigerian-map';
import { ghanaMap } from '@/lib/ghana-map';

const LOCAL_STORAGE_CURRENT_USER_KEY = 'currentUser';

const sortCollections = (a: AccordionItemData, b: AccordionItemData): number => {
  const currentYear = new Date().getFullYear();
  let dateA = new Date(`${a.date} ${currentYear}`);
  let dateB = new Date(`${b.date} ${currentYear}`);
  if (isNaN(dateA.getTime())) dateA = new Date(0);
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
  const [isAuthenticating, setIsAuthenticating] = React.useState(true);
  
  const [isAuthModalOpen, setIsAuthModalOpen] = React.useState(false);
  const [authModalStep, setAuthModalStep] = React.useState<'signIn' | 'upload'>('signIn');
  const [activeItemIdForUpload, setActiveItemIdForUpload] = React.useState<string | null>(null);
  const [activeItemTitleForUpload, setActiveItemTitleForUpload] = React.useState<string | null>(null);
  const [activeUserPhoneNumber, setActiveUserPhoneNumber] = React.useState<string | null>(null);
  
  const [activeSlideshowImages, setActiveSlideshowImages] = React.useState<ImageData[] | null>(null);
  const [activeSlideshowIndex, setActiveSlideshowIndex] = React.useState<number | null>(null);
  const [isImageDetailModalOpen, setIsImageDetailModalOpen] = React.useState(false);
  const [activeCollectionIdForModal, setActiveCollectionIdForModal] = React.useState<string | null>(null);

  const [isAddCollectionModalOpen, setIsAddCollectionModalOpen] = React.useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = React.useState(false);
  const [editingItem, setEditingItem] = React.useState<AccordionItemData | null>(null);
  const [isDeleteConfirmModalOpen, setIsDeleteConfirmModalOpen] = React.useState(false);
  const [deletingItem, setDeletingItem] = React.useState<AccordionItemData | null>(null);
  
  const [filterQuery, setFilterQuery] = React.useState('');

  const [isDioceseSummaryModalOpen, setIsDioceseSummaryModalOpen] = React.useState(false);
  const [isStateSummaryModalOpen, setIsStateSummaryModalOpen] = React.useState(false);
  const [isMapModalOpen, setIsMapModalOpen] = React.useState(false);
  const [isGhanaMapModalOpen, setIsGhanaMapModalOpen] = React.useState(false);

  const [currentUser, setCurrentUser] = React.useState<string | null>(null);
  const [isLoginModalOpen, setIsLoginModalOpen] = React.useState(false);

  const [defaultOpenAccordionItem, setDefaultOpenAccordionItem] = React.useState<string | undefined>(undefined);

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
        setAccordionItems(data.map(item => ({...item, country: item.country || "Nigeria"})).sort(sortCollections));
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

  React.useEffect(() => {
    const storedUser = localStorage.getItem(LOCAL_STORAGE_CURRENT_USER_KEY);
    if (storedUser) {
      setCurrentUser(storedUser);
    }
    setIsAuthenticating(false);
  }, []);

  React.useEffect(() => {
    if (typeof window !== 'undefined' && !isLoading && accordionItems.length > 0 && window.location.hash) {
      const hashId = window.location.hash.substring(1);
      const itemExists = accordionItems.some(item => item.id === hashId);
      
      if (itemExists) {
        setDefaultOpenAccordionItem(hashId);

        setTimeout(() => {
          const element = document.querySelector(`[data-radix-accordion-item="${hashId}"]`);
          const stickyHeader = document.querySelector('.sticky.top-0.z-50') as HTMLElement;
          
          if (element && stickyHeader) {
            const headerHeight = stickyHeader.offsetHeight;
            const elementRect = element.getBoundingClientRect();
            
            const currentScrollY = window.pageYOffset || document.documentElement.scrollTop;
            const targetScrollPosition = currentScrollY + elementRect.top - headerHeight - 10; 

            window.scrollTo({
              top: targetScrollPosition,
              behavior: 'smooth',
            });
          }
        }, 300); 
      }
    }
  }, [isLoading, accordionItems]);


  const handleLogin = async (formData: AdminLoginFormData) => {
    try {
      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      });
      const data = await response.json();
      if (response.ok) {
        setCurrentUser(data.username);
        localStorage.setItem(LOCAL_STORAGE_CURRENT_USER_KEY, data.username);
        setIsLoginModalOpen(false);
        toast({
          title: "Login Successful",
          description: `Welcome, ${data.username}!`,
        });
      } else {
        throw new Error(data.message || 'Login failed');
      }
    } catch (error) {
      console.error("Login error:", error);
      toast({
        title: "Login Failed",
        description: (error as Error).message || "An unexpected error occurred.",
        variant: "destructive",
      });
      throw error; 
    }
  };

  const handleLogout = () => {
    setCurrentUser(null);
    localStorage.removeItem(LOCAL_STORAGE_CURRENT_USER_KEY);
    toast({
      title: "Logged Out",
      description: "You have been successfully logged out.",
    });
  };

  const nigerianAccordionItems = React.useMemo(() => 
    accordionItems.filter(item => item.country === "Nigeria" || !item.country)
  , [accordionItems]);

  const dioceseSummary = React.useMemo(() => {
    if (!nigerianAccordionItems.length) {
      return { count: 0, total: nigerianDioceses.length, breakdown: [] as SummaryItem[] };
    }
    const diocesesInAccordion = nigerianAccordionItems.map(item => item.diocese);
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
  }, [nigerianAccordionItems]);

  const stateSummary = React.useMemo(() => {
    if (!nigerianAccordionItems.length) {
      return { count: 0, total: nigerianStates.length, breakdown: [] as SummaryItem[] };
    }
    const statesInAccordion = nigerianAccordionItems.map(item => item.state).filter(Boolean);
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
  }, [nigerianAccordionItems]);

  const massesPerStateForMap = React.useMemo(() => {
    const counts: MassesPerState = {};
    nigerianMap.forEach(mapState => counts[mapState.name] = 0); 

    nigerianAccordionItems.forEach(item => {
      if (item.state && counts.hasOwnProperty(item.state)) {
        counts[item.state]++;
      }
    });
    return counts;
  }, [nigerianAccordionItems]);

  const massesPerGhanaRegion = React.useMemo(() => {
    const counts: MassesPerState = {}; 
    ghanaMap.forEach(mapRegion => counts[mapRegion.name] = 0);

    accordionItems.filter(item => item.country === "Ghana").forEach(item => {
      if (item.state && counts.hasOwnProperty(item.state)) {
        counts[item.state]++;
      }
    });
    return counts;
  }, [accordionItems]);


  const handleUploadRequest = (item: AccordionItemData) => {
    setActiveItemIdForUpload(item.id);
    const displayTitle = `${item.parishLocation}${item.diocese ? ` - ${item.diocese}` : ''}`;
    setActiveItemTitleForUpload(displayTitle);
    setAuthModalStep('signIn'); 
    setIsAuthModalOpen(true);
  };

  const handleSignInSuccessForUpload = (phoneNumber: string) => { 
    setActiveUserPhoneNumber(phoneNumber);
    setAuthModalStep('upload');
  };

  const handlePhotoUpload = async (data: PhotoUploadFormData): Promise<void> => {
    if (!activeItemIdForUpload || !data.photo || data.photo.length === 0) {
      toast({
        title: "Upload Failed",
        description: "No photo was selected or item ID is missing.",
        variant: "destructive",
      });
      throw new Error("No photo or item ID.");
    }
    
    const file = data.photo[0];
    const uploadFormData = new FormData();
    uploadFormData.append('photo', file);
    uploadFormData.append('title', data.title);
    if (data.description) {
      uploadFormData.append('description', data.description);
    }

    const originalCollection = accordionItems.find(item => item.id === activeItemIdForUpload);
    let persistPayload; 

    try {
      const uploadResponse = await fetch('/api/image-upload', {
        method: 'POST',
        body: uploadFormData,
      });

      if (!uploadResponse.ok) {
        const errorData = await uploadResponse.json().catch(() => ({ message: 'Image upload failed with non-JSON response' }));
        throw new Error(errorData.message || 'Image upload failed');
      }

      const uploadedImageResult: { imageUrl: string; altText: string; hint: string } = await uploadResponse.json();
      const newImage: ImageData = {
        src: uploadedImageResult.imageUrl,
        alt: uploadedImageResult.altText,
        hint: uploadedImageResult.hint,
      };
      if (activeUserPhoneNumber) {
        newImage.uploadedBy = activeUserPhoneNumber;
      }


      let collectionToUpdate = accordionItems.find(item => item.id === activeItemIdForUpload);
      if (!collectionToUpdate) {
          throw new Error("Could not find the collection to add the image to.");
      }

      const updatedImages = [...collectionToUpdate.images, newImage];
      const updatedCollectionWithNewImage: AccordionItemData = {
        ...collectionToUpdate,
        images: updatedImages,
      };
      
      setAccordionItems(prevItems =>
        prevItems.map(item =>
          item.id === activeItemIdForUpload ? updatedCollectionWithNewImage : item
        ).sort(sortCollections)
      );

      persistPayload = { ...updatedCollectionWithNewImage }; 
      const persistResponse = await fetch(`/api/collections/${activeItemIdForUpload}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(persistPayload),
      });

      if (!persistResponse.ok) {
        const errorData = await persistResponse.json().catch(() => ({ message: 'Failed to save updated collection with non-JSON response' }));
        if (originalCollection) {
          setAccordionItems(prevItems =>
            prevItems.map(item =>
              item.id === activeItemIdForUpload ? originalCollection : item
            ).sort(sortCollections)
          );
        }
        throw new Error(errorData.message || 'Failed to save updated collection with new image');
      }
      
      const savedItem = await persistResponse.json();
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === activeItemIdForUpload ? { ...item, ...savedItem } : item).sort(sortCollections)
      );

      toast({
        title: "Photo Uploaded & Saved!",
        description: `"${newImage.alt}" has been added to ${activeItemTitleForUpload || 'the gallery'} and persisted.`,
      });

    } catch (error) {
      console.error("Error uploading photo or saving collection:", error);
      toast({
        title: "Upload or Save Error",
        description: (error as Error).message || "Could not complete photo upload process.",
        variant: "destructive",
      });
      if (originalCollection && persistPayload) { 
         setAccordionItems(prevItems =>
            prevItems.map(item =>
              item.id === activeItemIdForUpload ? originalCollection : item
            ).sort(sortCollections)
          );
      }
      throw error; 
    }
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
      country: formData.country,
      date: formattedDate,
      time: formData.time,
      images: [], 
    };

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
        throw new Error(errorData.message || 'Failed to save new Mass info');
      }
      
      const savedItem = await response.json();
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === newItemId ? savedItem : item).sort(sortCollections)
      );

      const displayTitle = `${formData.parishLocation}${formData.diocese ? ` (${formData.diocese})` : ''}`;
      toast({
        title: "New Mass Added!",
        description: `"${displayTitle}" has been saved.`,
      });

    } catch (error) {
      console.error("Error creating Mass info:", error);
      toast({
        title: "Save Error",
        description: (error as Error).message || "Could not save new Mass info to the server.",
        variant: "destructive",
      });
      setAccordionItems(prevItems => prevItems.filter(item => item.id !== newItemId).sort(sortCollections));
    }
  };
  
  const handleAuthModalOpenChange = (open: boolean) => {
    setIsAuthModalOpen(open);
    if (!open) {
      setAuthModalStep('signIn'); 
      setActiveItemIdForUpload(null);
      setActiveItemTitleForUpload(null);
      setActiveUserPhoneNumber(null); 
    }
  };

  const handleImageClick = (image: ImageData, index: number, allImages: ImageData[], collectionId: string) => {
    setActiveSlideshowImages(allImages);
    setActiveSlideshowIndex(index);
    setActiveCollectionIdForModal(collectionId);
    setIsImageDetailModalOpen(true);
  };

  const handleImageDetailModalOpenChange = (open: boolean) => {
    setIsImageDetailModalOpen(open);
    if (!open) {
      setActiveSlideshowImages(null);
      setActiveSlideshowIndex(null);
      setActiveCollectionIdForModal(null);
    }
  };

  const handleEditRequest = (item: AccordionItemData) => {
    if (!currentUser) return; 
    setEditingItem(item);
    setIsEditModalOpen(true);
  };

  const handleUpdateCollection = async (formData: CollectionFormSubmitData) => {
    if (!editingItem || !currentUser) return;

    const originalItem = accordionItems.find(item => item.id === editingItem.id);
    if (!originalItem) return; 

    const formattedDate = formatDateFns(formData.date, "MMMM d");
    const itemWithUpdates: AccordionItemData = {
      ...editingItem,
      parishLocation: formData.parishLocation,
      diocese: formData.diocese,
      state: formData.state, 
      country: formData.country,
      date: formattedDate,
      time: formData.time,
    };
    
    setAccordionItems(prevItems =>
      prevItems.map(item =>
        item.id === editingItem.id ? itemWithUpdates : item
      ).sort(sortCollections)
    );
    setIsEditModalOpen(false);

    try {
      const payloadToPersist = {
        ...itemWithUpdates,
        images: itemWithUpdates.images.filter(img => !img.src.startsWith('blob:'))
      };

      const response = await fetch(`/api/collections/${editingItem.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payloadToPersist),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to update Mass details');
      }
      
      const savedItem = await response.json();
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === editingItem.id ? { ...item, ...savedItem } : item).sort(sortCollections)
      );

      const displayTitle = `${formData.parishLocation}${formData.diocese ? ` - ${formData.diocese}` : ''}`;
      toast({
        title: "Mass details updated!",
        description: `"${displayTitle}" has been saved.`,
      });
    } catch (error) {
      console.error("Error updating Mass details:", error);
      toast({
        title: "Update Error",
        description: (error as Error).message || "Could not save updates to the server.",
        variant: "destructive",
      });
      if (originalItem) {
        setAccordionItems(prevItems => prevItems.map(item => item.id === editingItem.id ? originalItem : item).sort(sortCollections));
      }
    } finally {
      setEditingItem(null);
    }
  };

  const handleDeleteRequest = (item: AccordionItemData) => {
    if (!currentUser) return; 
    setDeletingItem(item);
    setIsDeleteConfirmModalOpen(true);
  };

  const handleConfirmDelete = async () => {
    if (!deletingItem || !currentUser) return;

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
      setAccordionItems(originalItems.sort(sortCollections));
    } finally {
      setDeletingItem(null);
    }
  };

  const handleDeleteImageFromSlideshow = async (imageSrc: string) => {
    if (!currentUser || !activeCollectionIdForModal || !activeSlideshowImages) return;

    const collectionToUpdate = accordionItems.find(item => item.id === activeCollectionIdForModal);
    if (!collectionToUpdate) {
      toast({ title: "Error", description: "Could not find the collection for this image.", variant: "destructive" });
      return;
    }

    const originalImages = [...collectionToUpdate.images];
    const newCollectionImages = collectionToUpdate.images.filter(img => img.src !== imageSrc);

    const updatedCollectionData: AccordionItemData = {
      ...collectionToUpdate,
      images: newCollectionImages,
    };

    setAccordionItems(prevItems =>
      prevItems.map(item => (item.id === activeCollectionIdForModal ? updatedCollectionData : item)).sort(sortCollections)
    );
    
    try {
      const response = await fetch(`/api/collections/${activeCollectionIdForModal}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(updatedCollectionData),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to save collection after image deletion."}));
        throw new Error(errorData.message);
      }

      const savedItem = await response.json();
      setAccordionItems(prevItems => 
        prevItems.map(item => item.id === activeCollectionIdForModal ? { ...item, ...savedItem } : item).sort(sortCollections)
      );

      setActiveSlideshowImages(newCollectionImages);
      if (newCollectionImages.length === 0) {
        handleImageDetailModalOpenChange(false); 
      } else {
        const currentImageStillExists = newCollectionImages.some(img => img.src === (activeSlideshowImages.find((_,idx) => idx === activeSlideshowIndex)?.src));
        if (!currentImageStillExists && activeSlideshowIndex !== null) {
           setActiveSlideshowIndex(Math.max(0, activeSlideshowIndex -1));
        } else if (activeSlideshowIndex !== null && activeSlideshowIndex >= newCollectionImages.length) {
           setActiveSlideshowIndex(newCollectionImages.length - 1);
        }
      }

      toast({
        title: "Image Deleted",
        description: "The image has been removed from the collection.",
      });

    } catch (error) {
      console.error("Error deleting image:", error);
      toast({
        title: "Delete Image Error",
        description: (error as Error).message || "Could not delete image from the collection.",
        variant: "destructive",
      });
      setAccordionItems(prevItems =>
        prevItems.map(item => (item.id === activeCollectionIdForModal ? { ...item, images: originalImages } : item)).sort(sortCollections)
      );
    }
  };


  const filteredAccordionItems = React.useMemo(() => {
    if (!filterQuery) {
      return accordionItems; 
    }
    const lowercasedQuery = filterQuery.toLowerCase();
    return accordionItems.filter(item =>
      item.parishLocation.toLowerCase().includes(lowercasedQuery) ||
      item.diocese.toLowerCase().includes(lowercasedQuery) ||
      (item.state && item.state.toLowerCase().includes(lowercasedQuery)) ||
      (item.country && item.country.toLowerCase().includes(lowercasedQuery)) ||
      item.date.toLowerCase().includes(lowercasedQuery) ||
      item.time.toLowerCase().includes(lowercasedQuery)
    ); 
  }, [accordionItems, filterQuery]);

  const handleApplySummaryFilter = (filterTerm: string) => {
    setFilterQuery(filterTerm);
    setIsDioceseSummaryModalOpen(false);
    setIsStateSummaryModalOpen(false);
  };

  if (isLoading || isAuthenticating) {
    return (
      <div className="container mx-auto px-4 py-8 min-h-screen flex flex-col items-center justify-center">
        <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
        <p className="text-xl text-muted-foreground">
          {isAuthenticating ? 'Checking authentication...' : 'Loading Masses...'}
        </p>
      </div>
    );
  }

  return (
    <>
      <div className="sticky top-0 z-50 bg-background shadow-sm">
        <div className="main-header border-b border-t-2 border-black">
          <header className="relative mx-auto container pt-20 pb-2 px-4 text-left">
            <div className="absolute top-0 left-0 w-full pl-4">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="link" className="text-sm p-0 h-auto outline-none hover:no-underline hover:text-primary/70 focus-visible:ring-0">
                    {/* <MapIcon className="mr-1 h-3 w-3" /> */}
                    Maps
                    <ChevronDown className="ml-1 h-3 w-3" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => setIsMapModalOpen(true)}>
                    Nigeria
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setIsGhanaMapModalOpen(true)}>
                    Ghana
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
            <div className="w-full text-xs text-muted-foreground">Masses on the</div>
            <h1 className="w-[220px] sm:w-full leading-none text-lg sm:text-xl md:text-2xl font-headline font-bold mb-3">
              50th anniversary of <br/>St. Josemaría in heaven!
            </h1>
            <div className="text-xs sm:text-lg text-muted-foreground font-body flex justify-start items-center space-x-2">
              <Button variant="link" onClick={() => setIsDioceseSummaryModalOpen(true)} className="p-0 h-auto text-md hover:no-underline hover:text-primary/70">
                Dioceses ({dioceseSummary.count}/{dioceseSummary.total})
              </Button>
              <span>|</span>
              <Button variant="link" onClick={() => setIsStateSummaryModalOpen(true)} className="p-0 h-auto text-md hover:no-underline hover:text-primary/70">
                States ({stateSummary.count}/{stateSummary.total})
              </Button>
            </div>
          </header>
        </div>
        <div className="container mx-auto sm:px-4 sm:py-3 sm:border-b-1 sm:shadow-sm">
          <div className="relative flex justify-center items-center">
            <Input
              type="text"
              placeholder="Filter by parish, diocese, state, country or date..."
              value={filterQuery}
              onChange={(e) => setFilterQuery(e.target.value)}
              className="w-full h-10 px-4 py-4 text-base rounded-none border-x-0 border-t-0 shadow-sm sm:border-0 sm:shadow-none pr-10 placeholder:text-[#aaa]"
            />
            {filterQuery && (
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setFilterQuery('')}
                className="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 text-muted-foreground hover:text-foreground"
                aria-label="Clear filter"
              >
                <XIcon className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>
      </div>

      <div className="container mx-auto px-0 py-0 min-h-screen">
        <div className="mt-4 px-2 sm:px-4">
          <div className="max-w-4xl mx-auto flex flex-col sm:flex-row sm:justify-between sm:items-center sm:mb-4">
          {currentUser && (
            <Button 
              onClick={openAddCollectionModal} 
              variant="outline" 
              className="text-primary border-primary text-white bg-primary hover:bg-primary/10 w-full sm:w-auto mb-2 sm:mb-0"
            >
              <PlusCircle className="mr-2 h-5 w-5" />
              Add New Mass
            </Button>
          )}
          <div className="mass-count text-right text-sm text-muted-foreground mb-4 sm:mb-0 ml-auto">
            {filterQuery
              ? `${filteredAccordionItems.length} of ${accordionItems.length} Masses found`
              : `${accordionItems.length} Masses`}
          </div>
          </div>

          <GridAccordion 
            items={filteredAccordionItems} 
            onUploadRequest={handleUploadRequest}
            onImageClick={handleImageClick} 
            onEditRequest={currentUser ? handleEditRequest : undefined}
            onDeleteRequest={currentUser ? handleDeleteRequest : undefined}
            isUserLoggedIn={!!currentUser}
            defaultValue={defaultOpenAccordionItem}
          />
        </div>
        <AuthModal
          isOpen={isAuthModalOpen}
          onOpenChange={handleAuthModalOpenChange}
          currentStep={authModalStep}
          onSignInSuccess={handleSignInSuccessForUpload}
          onUploadSubmit={handlePhotoUpload}
          itemName={activeItemTitleForUpload || undefined}
        />

        {currentUser && (
          <AddCollectionModal
            isOpen={isAddCollectionModalOpen}
            onOpenChange={setIsAddCollectionModalOpen}
            onSubmit={handleCreateNewCollection}
          />
        )}

        {currentUser && editingItem && (
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

        {currentUser && deletingItem && (
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
          isUserLoggedIn={!!currentUser}
          onDeleteImage={handleDeleteImageFromSlideshow}
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

        <LoginModal
          isOpen={isLoginModalOpen}
          onOpenChange={setIsLoginModalOpen}
          onLoginSubmit={handleLogin}
        />

        <NigerianMapModal
          isOpen={isMapModalOpen}
          onOpenChange={setIsMapModalOpen}
          massesPerState={massesPerStateForMap}
        />
        
        <GhanaMapModal
            isOpen={isGhanaMapModalOpen}
            onOpenChange={setIsGhanaMapModalOpen}
            massesPerRegion={massesPerGhanaRegion}
        />
        
        <footer className="text-center mt-12 py-6 border-t border-border">
          <div className="flex flex-col sm:flex-row justify-center items-center space-y-2 sm:space-y-0 sm:space-x-4">
            <p className="text-xs text-muted-foreground">
              &copy; {new Date().getFullYear()} Masses of St Josemaria <br className="sm:hidden"/> <a href="mailto:patxiworks@gmail.com" className="text-[10px]">by Telluris</a>.
            </p>
            <div className="flex items-center space-x-3">
              {currentUser ? (
                <Button variant="link" onClick={handleLogout} className="text-xs p-0 h-auto">
                  <LogOut className="mr-1 h-3 w-3" /> Logout ({currentUser})
                </Button>
              ) : (
                <Button variant="link" onClick={() => setIsLoginModalOpen(true)} className="text-xs p-0 h-auto">
                  <LogIn className="mr-1 h-3 w-3" /> Admin Login
                </Button>
              )}
            </div>
          </div>
        </footer>
      </div>
    </>
  );
}
    

    


    