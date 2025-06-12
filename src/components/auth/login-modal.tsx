
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { LoginForm } from './login-form';
import type { LoginFormData } from '@/types';

interface LoginModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onLoginSubmit: (data: LoginFormData) => Promise<void>; // Make it async to handle loading state
}

export function LoginModal({ isOpen, onOpenChange, onLoginSubmit }: LoginModalProps) {
  const [isLoading, setIsLoading] = React.useState(false);

  const handleFormSubmit = async (data: LoginFormData) => {
    setIsLoading(true);
    try {
      await onLoginSubmit(data);
      // Success is handled by parent (closing modal, setting user)
    } catch (error) {
      // Error is handled by parent (showing toast)
      console.error("Login failed from modal perspective:", error);
    } finally {
      setIsLoading(false);
    }
  };
  
  // Reset loading state if modal is closed externally
  React.useEffect(() => {
    if (!isOpen) {
      setIsLoading(false);
    }
  }, [isOpen]);


  return (
    <Dialog open={isOpen} onOpenChange={(open) => {
      if (!isLoading) { // Prevent closing while loading
        onOpenChange(open);
      }
    }}>
      <DialogContent className="sm:max-w-[480px] p-6 rounded-lg shadow-xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-headline text-center mb-2">
            Admin Login
          </DialogTitle>
          <DialogDescription className="text-center text-muted-foreground mb-6">
            Enter your credentials to access administrative features.
          </DialogDescription>
        </DialogHeader>
        <LoginForm 
          onLoginSubmit={handleFormSubmit} 
          onCancel={() => { if (!isLoading) onOpenChange(false); }}
          isLoading={isLoading}
        />
      </DialogContent>
    </Dialog>
  );
}
