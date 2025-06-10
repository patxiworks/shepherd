"use client";

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { SignInForm } from './sign-in-form';
import { PhotoUploadForm } from './photo-upload-form';
import type { PhotoUploadFormData } from '@/types';

interface AuthModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  currentStep: 'signIn' | 'upload';
  onSignInSuccess: () => void;
  onUploadSubmit: (data: PhotoUploadFormData) => void;
  itemName?: string;
}

export function AuthModal({
  isOpen,
  onOpenChange,
  currentStep,
  onSignInSuccess,
  onUploadSubmit,
  itemName
}: AuthModalProps) {
  
  const handleModalClose = (open: boolean) => {
    if (!open) {
       // Reset to signIn step if modal is closed by user action (e.g. Escape key or overlay click)
      onOpenChange(false);
    } else {
      onOpenChange(true);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={handleModalClose}>
      <DialogContent className="sm:max-w-[480px] p-6 rounded-lg shadow-xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-headline text-center mb-2">
            {currentStep === 'signIn' ? 'Sign In to Upload' : `Upload to ${itemName || 'Gallery'}`}
          </DialogTitle>
          <DialogDescription className="text-center text-muted-foreground mb-6">
            {currentStep === 'signIn'
              ? 'Please sign in to continue uploading your photos.'
              : `Add a new photo to the "${itemName || 'selected'}" collection.`}
          </DialogDescription>
        </DialogHeader>
        {currentStep === 'signIn' ? (
          <SignInForm onSignInSuccess={onSignInSuccess} onCancel={() => onOpenChange(false)} />
        ) : (
          <PhotoUploadForm onSubmit={onUploadSubmit} onCancel={() => onOpenChange(false)} />
        )}
      </DialogContent>
    </Dialog>
  );
}
