
"use client";

import * as React from 'react';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { format, parse as parseDateFns } from 'date-fns';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Calendar } from '@/components/ui/calendar';
import type { AccordionItemData, NewCollectionFormData as EditCollectionSubmitData } from '@/types';
import { nigerianDioceses } from '@/lib/nigerian-dioceses';
import { nigerianStates } from '@/lib/nigerian-states';
import { CalendarIcon, Save } from 'lucide-react';
import { cn } from '@/lib/utils';

const editCollectionSchema = z.object({
  parishLocation: z.string().min(1, { message: "Parish name is required." }),
  diocese: z.string().min(1, { message: "Diocese is required." }),
  state: z.string().min(1, { message: "State/Region is required." }),
  country: z.string().min(1, { message: "Country is required." }),
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
  onSubmit: (data: EditCollectionFormValues) => void; // Changed type to EditCollectionFormValues
  initialData: AccordionItemData;
}

const hours = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0'));
const minutes = Array.from({ length: 60 }, (_, i) => i.toString().padStart(2, '0'));

const parseDateString = (dateStr: string): Date | undefined => {
  try {
    // Try parsing "Month d" format first
    let parsedDate = parseDateFns(dateStr, "MMMM d", new Date());
    if (isNaN(parsedDate.getTime())) {
      // Fallback to more general parsing if specific format fails
      parsedDate = new Date(dateStr + " " + new Date().getFullYear());
    }
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
      state: initialData.state || '', 
      country: initialData.country || 'Nigeria', // Default to Nigeria if not present
      date: parseDateString(initialData.date) || new Date(),
      time: initialData.time,
    },
  });
  
  const selectedCountry = form.watch("country");

  React.useEffect(() => {
    if (initialData) {
      form.reset({
        parishLocation: initialData.parishLocation,
        diocese: initialData.diocese,
        state: initialData.state || '', 
        country: initialData.country || 'Nigeria',
        date: parseDateString(initialData.date) || new Date(),
        time: initialData.time,
      });
      const [hour, minute] = initialData.time.split(':');
      setSelectedHour(hour || '12');
      setSelectedMinute(minute || '00');
    }
  }, [initialData, form, isOpen]); // Added isOpen to dependencies

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
            Edit Mass
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
                  <FormLabel>Parish name</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., St. Matthew Chaplaincy, Regina Pacis College" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
             <FormField
              control={form.control}
              name="country"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Country</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a country" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="Nigeria">Nigeria</SelectItem>
                      <SelectItem value="Ghana">Ghana</SelectItem>
                    </SelectContent>
                  </Select>
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
                  <Select onValueChange={field.onChange} value={field.value} >
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a diocese" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                       {/* TODO: Conditionally load dioceses based on selectedCountry */}
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
                  <FormLabel>{selectedCountry === "Ghana" ? "Region" : "State"}</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                         <SelectValue placeholder={selectedCountry === "Ghana" ? "Select a region" : "Select a state"} />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {/* TODO: Conditionally load states/regions based on selectedCountry */}
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
                           date <= new Date(new Date().setDate(new Date().getDate() -1)) // Allow today
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
                  fieldState.error ? <FormMessage>{fieldState.error.message}</FormMessage> : <></>
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
