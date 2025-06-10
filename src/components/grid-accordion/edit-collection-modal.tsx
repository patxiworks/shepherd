
"use client";

import * as React from 'react';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { format } from 'date-fns';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Calendar } from '@/components/ui/calendar';
import type { AccordionItemData } from '@/types';
import { nigerianDioceses } from '@/lib/nigerian-dioceses';
import { nigerianStates } from '@/lib/nigerian-states'; // Import states
import { CalendarIcon, Save } from 'lucide-react';
import { cn } from '@/lib/utils';

const editCollectionSchema = z.object({
  parishLocation: z.string().min(1, { message: "Parish/Catholic Church - Location is required." }),
  diocese: z.string().min(1, { message: "Diocese is required." }),
  state: z.string().min(1, { message: "State is required." }), // Add state validation
  date: z.date({
    required_error: "Date is required.",
    invalid_type_error: "That's not a valid date!",
  }),
  time: z.string().regex(/^([01]\d|2[0-3]):([0-5]\d)$/, { message: "Invalid time format. Use HH:MM." }),
});

type EditCollectionFormValues = z.infer<typeof editCollectionSchema>;

interface EditCollectionModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: EditCollectionFormValues) => void;
  initialData: AccordionItemData;
}

const hours = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0'));
const minutes = Array.from({ length: 60 }, (_, i) => i.toString().padStart(2, '0'));

const parseDateString = (dateStr: string): Date | undefined => {
  try {
    const fullDateStr = `${dateStr} ${new Date().getFullYear()}`;
    const parsedDate = new Date(fullDateStr);
    return isNaN(parsedDate.getTime()) ? undefined : parsedDate;
  } catch (error) {
    return undefined;
  }
};

export function EditCollectionModal({ isOpen, onOpenChange, onSubmit, initialData }: EditCollectionModalProps) {
  const [selectedHour, setSelectedHour] = React.useState<string>('12');
  const [selectedMinute, setSelectedMinute] = React.useState<string>('00');

  const form = useForm<EditCollectionFormValues>({
    resolver: zodResolver(editCollectionSchema),
    defaultValues: {
      parishLocation: initialData.parishLocation,
      diocese: initialData.diocese,
      state: initialData.state || '', // Initialize state
      date: parseDateString(initialData.date) || new Date(),
      time: initialData.time,
    },
  });

  React.useEffect(() => {
    if (initialData) {
      form.reset({
        parishLocation: initialData.parishLocation,
        diocese: initialData.diocese,
        state: initialData.state || '', // Reset state
        date: parseDateString(initialData.date) || new Date(),
        time: initialData.time,
      });
      const [hour, minute] = initialData.time.split(':');
      setSelectedHour(hour || '12');
      setSelectedMinute(minute || '00');
    }
  }, [initialData, form]);

  React.useEffect(() => {
    form.setValue('time', `${selectedHour}:${selectedMinute}`, { shouldValidate: true });
  }, [selectedHour, selectedMinute, form]);

  const handleFormSubmit = (values: EditCollectionFormValues) => {
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
          <form onSubmit={form.handleSubmit(handleFormSubmit)} className="space-y-4">
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
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a diocese" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {nigerianDioceses.map((dioceseName) => (
                        <SelectItem key={dioceseName} value={dioceseName}>
                          {dioceseName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="state"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>State</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a state" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {nigerianStates.map((stateName) => (
                        <SelectItem key={stateName} value={stateName}>
                          {stateName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="date"
              render={({ field }) => (
                <FormItem className="flex flex-col">
                  <FormLabel>Date</FormLabel>
                  <Popover>
                    <PopoverTrigger asChild>
                      <FormControl>
                        <Button
                          variant={"outline"}
                          className={cn(
                            "w-full pl-3 text-left font-normal",
                            !field.value && "text-muted-foreground"
                          )}
                        >
                          {field.value ? (
                            format(field.value, "PPP")
                          ) : (
                            <span>Pick a date</span>
                          )}
                          <CalendarIcon className="ml-auto h-4 w-4 opacity-50" />
                        </Button>
                      </FormControl>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        selected={field.value}
                        onSelect={field.onChange}
                        disabled={(date) =>
                          date > new Date() || date < new Date("1900-01-01")
                        }
                        initialFocus
                      />
                    </PopoverContent>
                  </Popover>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormItem>
              <FormLabel>Time</FormLabel>
              <div className="flex space-x-2">
                <Select onValueChange={setSelectedHour} value={selectedHour}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Hour" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {hours.map(hour => <SelectItem key={hour} value={hour}>{hour}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Select onValueChange={setSelectedMinute} value={selectedMinute}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Minute" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {minutes.map(minute => <SelectItem key={minute} value={minute}>{minute}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
               <Controller
                name="time"
                control={form.control}
                render={({ fieldState }) => (
                  fieldState.error ? <FormMessage>{fieldState.error.message}</FormMessage> : null
                )}
              />
            </FormItem>

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
