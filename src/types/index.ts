
export interface ImageData {
  src: string;
  alt: string;
  hint?: string; // For data-ai-hint
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
  description: string;
  photo?: FileList;
}

export interface SignInFormData {
  email: string;
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
