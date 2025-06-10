
"use client";

import * as React from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import type { NewCollectionFormData, AccordionItemData } from '@/types';
import { Save } from 'lucide-react';

const editCollectionSchema = z.object({
  parishLocation: z.string().min(1, { message: "Parish/Catholic Church - Location is required." }),
  diocese: z.string().min(1, { message: "Diocese is required." }),
  date: z.string().min(1, { message: "Date is required." }),
  time: z.string().regex(/^([01]\d|2[0-3]):([0-5]\d)$/, { message: "Invalid time format. Use HH:MM." }),
});

interface EditCollectionModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: NewCollectionFormData) => void;
  initialData: AccordionItemData;
}

export function EditCollectionModal({ isOpen, onOpenChange, onSubmit, initialData }: EditCollectionModalProps) {
  const form = useForm<z.infer<typeof editCollectionSchema>>({
    resolver: zodResolver(editCollectionSchema),
    defaultValues: {
      parishLocation: initialData.parishLocation,
      diocese: initialData.diocese,
      date: initialData.date,
      time: initialData.time,
    },
  });

  React.useEffect(() => {
    if (initialData) {
      form.reset({
        parishLocation: initialData.parishLocation,
        diocese: initialData.diocese,
        date: initialData.date,
        time: initialData.time,
      });
    }
  }, [initialData, form]);

  const handleSubmit = (values: z.infer<typeof editCollectionSchema>) => {
    onSubmit(values);
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[520px] p-6 rounded-lg shadow-xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-headline text-center mb-2">
            Edit Collection
          </DialogTitle>
          <DialogDescription className="text-center text-muted-foreground mb-6">
            Update the details for this photo collection.
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
            <DialogFooter className="pt-4">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit">
                <Save className="mr-2 h-4 w-4" />
                Save Changes
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
