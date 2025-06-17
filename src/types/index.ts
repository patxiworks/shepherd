
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
  date: string; // e.g., "July 1"
  time: string; // e.g., "18:00"
  images: ImageData[];
}

export interface NewCollectionFormData {
  parishLocation: string;
  diocese: string;
  state: string; 
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
export interface MassesPerState {
  [stateName: string]: number;
}

export interface NigerianMapModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  massesPerState: MassesPerState;
}

export interface MapStateDataItem {
  name: string;
  path: string;
  massCount: number;
}
