
export interface ImageData {
  src: string;
  alt: string;
  hint?: string; // For data-ai-hint
}

export interface AccordionItemData {
  id: string;
  title: string;
  images: ImageData[];
}

export interface PhotoUploadFormData {
  title: string;
  description: string;
  photo?: FileList;
}

export interface SignInFormData {
  email: string;
  password?: string; // Password might not be strictly needed for a prototype
}
