
"use client";

import * as React from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import type { NewCollectionFormData } from '@/types';
import { PlusCircle } from 'lucide-react';

const newCollectionSchema = z.object({
  parishLocation: z.string().min(1, { message: "Parish/Catholic Church - Location is required." }),
  diocese: z.string().min(1, { message: "Diocese is required." }),
  date: z.string().min(1, { message: "Date is required." }), // Basic validation, can be enhanced
  time: z.string().regex(/^([01]\d|2[0-3]):([0-5]\d)$/, { message: "Invalid time format. Use HH:MM." }),
});

interface AddCollectionModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: NewCollectionFormData) => void;
}

export function AddCollectionModal({ isOpen, onOpenChange, onSubmit }: AddCollectionModalProps) {
  const form = useForm<z.infer<typeof newCollectionSchema>>({
    resolver: zodResolver(newCollectionSchema),
    defaultValues: {
      parishLocation: '',
      diocese: '',
      date: '',
      time: '',
    },
  });

  const handleSubmit = (values: z.infer<typeof newCollectionSchema>) => {
    onSubmit(values);
    form.reset(); // Reset form after submission
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[520px] p-6 rounded-lg shadow-xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-headline text-center mb-2">
            Add New Collection
          </DialogTitle>
          <DialogDescription className="text-center text-muted-foreground mb-6">
            Enter the details for the new photo collection.
          </DialogDescription>
        </DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="parishLocation"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Parish/Catholic Church - Location</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., St. Matthew Chaplaincy, Regina Pacis College" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="diocese"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Diocese</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., Abuja" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="date"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Date</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., July 1" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="time"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Time</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., 18:00" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="flex justify-end space-x-3 pt-4">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit">
                <PlusCircle className="mr-2 h-4 w-4" />
                Add Collection
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
