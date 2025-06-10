
export interface ImageData {
  src: string;
  alt: string;
  hint?: string; // For data-ai-hint
}

// This represents the data structure for a collection item as stored (e.g., in accordionItems state and collections.json)
// Date is a string here.
export interface AccordionItemData {
  id: string;
  parishLocation: string;
  diocese: string;
  state: string; // Added state field
  date: string; // e.g., "July 1"
  time: string; // e.g., "18:00"
  images: ImageData[];
}

// This represents the raw data from the Add/Edit forms *before* date formatting for storage.
// Date is a Date object here, directly from the calendar.
// This type is used internally by the form components and the onSubmit handlers in page.tsx.
export interface NewCollectionFormData {
  parishLocation: string;
  diocese: string;
  state: string; // Added state field
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
  password?: string; // Password might not be strictly needed for a prototype
}
