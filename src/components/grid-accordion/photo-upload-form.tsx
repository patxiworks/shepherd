
"use client";

import type * as React from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
// import { Label } from '@/components/ui/label'; // Label is part of FormLabel
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import type { PhotoUploadFormData } from '@/types';
import { UploadCloud } from 'lucide-react';

const photoFieldSchema = typeof window !== 'undefined'
  ? z.instanceof(FileList)
    .refine(files => files?.length > 0, "A photo is required.")
    .refine(files => files?.[0]?.size <= 5 * 1024 * 1024, "Max file size is 5MB.") 
    .refine(
      files => files?.[0] && ["image/jpeg", "image/png", "image/webp", "image/gif"].includes(files[0].type),
      "Only .jpg, .png, .webp, .gif formats are supported."
    )
  : z.any(); 

const photoUploadSchema = z.object({
  title: z.string().min(3, { message: "Title must be at least 3 characters." }).max(100),
  description: z.string().max(500).optional(),
  photo: photoFieldSchema,
});

interface PhotoUploadFormProps {
  onSubmit: (data: PhotoUploadFormData) => void;
  onCancel: () => void;
}

export function PhotoUploadForm({ onSubmit, onCancel }: PhotoUploadFormProps) {
  const form = useForm<z.infer<typeof photoUploadSchema>>({
    resolver: zodResolver(photoUploadSchema),
    defaultValues: {
      title: '',
      description: '',
      photo: undefined,
    },
  });

  const handleSubmit = (values: z.infer<typeof photoUploadSchema>) => {
    // The actual FileList is in values.photo
    onSubmit(values as PhotoUploadFormData); 
    form.reset(); // Reset form after submission
  };

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-6">
        <FormField
          control={form.control}
          name="title"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Photo Title</FormLabel>
              <FormControl>
                <Input placeholder="e.g., Main Altar" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="description"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Description (Optional)</FormLabel>
              <FormControl>
                <Textarea placeholder="A brief description of the photo..." {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="photo"
          render={({ field }) => ( // `field` contains onChange, onBlur, value, name, ref
            <FormItem>
              <FormLabel>Upload Photo</FormLabel>
              <FormControl>
                <Input 
                  type="file" 
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  onChange={(e) => field.onChange(e.target.files)} // Pass FileList to RHF
                  // Do not spread `value` for file inputs, it's handled by onChange
                  name={field.name}
                  ref={field.ref}
                  onBlur={field.onBlur}
                  className="file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <div className="flex justify-end space-x-3 pt-4">
          <Button type="button" variant="outline" onClick={() => { onCancel(); form.reset(); }}>
            Cancel
          </Button>
          <Button type="submit" className="bg-accent hover:bg-accent/90 text-accent-foreground">
            <UploadCloud className="mr-2 h-4 w-4" />
            Upload Photo
          </Button>
        </div>
      </form>
    </Form>
  );
}
