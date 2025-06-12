
"use client";

import * as React from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import type { SignInFormData } from '@/types'; // Will now be { phoneNumber: string }
import { LogIn, Loader2 } from 'lucide-react';
import { useToast } from "@/hooks/use-toast";

const signInSchema = z.object({
  // Basic E.164-like validation, adjust regex as needed for more strictness
  phoneNumber: z.string().min(10, { message: "Phone number seems too short." }).regex(/^\+?[1-9]\d{1,14}$/, "Invalid phone number format (e.g., +2348012345678)."),
});

type SignInFormValues = z.infer<typeof signInSchema>;

interface SignInFormProps {
  onSignInSuccess: (phoneNumber: string) => void; // Changed to pass phone number
  onCancel: () => void;
}

export function SignInForm({ onSignInSuccess, onCancel }: SignInFormProps) {
  const [isLoading, setIsLoading] = React.useState(false);
  const { toast } = useToast();
  const form = useForm<SignInFormValues>({
    resolver: zodResolver(signInSchema),
    defaultValues: {
      phoneNumber: '',
    },
  });

  const handleSignIn = async (values: SignInFormValues) => {
    setIsLoading(true);
    try {
      const response = await fetch('/api/auth/phone-login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phoneNumber: values.phoneNumber }),
      });
      const data = await response.json();

      if (response.ok && data.success) {
        toast({
          title: "Phone Verified",
          description: "You can now proceed to upload.",
        });
        onSignInSuccess(data.phoneNumber); // Pass the validated phone number
      } else {
        throw new Error(data.message || 'Phone number verification failed.');
      }
    } catch (error) {
      console.error('Phone sign-in error:', error);
      toast({
        title: "Verification Failed",
        description: (error as Error).message || "Could not verify phone number.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(handleSignIn)} className="space-y-6">
        <fieldset disabled={isLoading} className="space-y-6">
          <FormField
            control={form.control}
            name="phoneNumber"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Phone Number</FormLabel>
                <FormControl>
                  <Input type="tel" placeholder="+2348012345678" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </fieldset>
        <div className="flex justify-end space-x-3 pt-4">
          <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
            Cancel
          </Button>
          <Button type="submit" disabled={isLoading}>
            {isLoading ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <LogIn className="mr-2 h-4 w-4" />
            )}
            {isLoading ? 'Verifying...' : 'Verify Phone'}
          </Button>
        </div>
         <p className="text-sm text-muted-foreground text-center pt-2">
            Enter an authorized phone number to upload photos.
          </p>
      </form>
    </Form>
  );
}
