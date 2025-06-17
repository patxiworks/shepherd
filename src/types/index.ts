
export interface ImageData {
  src: string;
  alt: string;
  hint?: string; // For data-ai-hint
  uploadedBy?: string; // To store the phone number of the uploader
}

export interface AccordionItemData {
  id: string;
  parishLocation: string;
  diocese: string;
  state: string; 
  country?: string; // Added country field
  date: string; // e.g., "July 1"
  time: string; // e.g., "18:00"
  images: ImageData[];
}

export interface NewCollectionFormData {
  parishLocation: string;
  diocese: string;
  state: string; 
  country: string; // Added country field
  date: Date; // Date object from calendar
  time: string;
}


export interface PhotoUploadFormData {
  title:string;
  description?: string; // Made optional as per form
  photo?: FileList; // FileList can be undefined if no file is selected or after reset
}

// This will now be used for the phone number sign-in in AuthModal
export interface SignInFormData { 
  phoneNumber: string;
}

export interface UserCredentials {
  username: string;
  password?: string; // Password is required for login attempt, optional for user data type
}

// This is used by the global LoginModal for admin username/password login
export interface LoginFormData { 
  username: string;
  password?: string;
}

export interface SummaryItem {
  name: string;
  count: number;
}

export interface DioceseSummaryModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  summaryData: SummaryItem[];
  onApplyFilter: (filterTerm: string) => void;
}

export interface StateSummaryModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  summaryData: SummaryItem[];
  onApplyFilter: (filterTerm: string) => void;
}

// For Nigerian Map Modal
export interface MassesPerState { // Can be reused for Ghana (MassesPerRegion)
  [stateOrRegionName: string]: number;
}

export interface NigerianMapModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  massesPerState: MassesPerState;
}

export interface GhanaMapModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  massesPerRegion: MassesPerState; // Using MassesPerState type, key is region name
}

export interface MapStateDataItem { // Can be reused for Ghana regions
  name: string;
  path: string;
  massCount: number;
}

// Type for Ghana map data items (if different structure is needed, but likely same as MapStateDataItem)
export interface GhanaMapRegionDataItem {
    name: string;
    code: string; // Or any other Ghana-specific fields
    path: string;
    massCount?: number; // Optional, to be populated
}
