
"use client";

import * as AccordionPrimitive from "@radix-ui/react-accordion";
import { Accordion, AccordionContent, AccordionItem } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Pencil, Trash2, ChevronDown } from 'lucide-react';
import type { AccordionItemData, ImageData } from '@/types';
import { GridAccordionItemContent } from './grid-accordion-item-content';
import { cn } from "@/lib/utils";

interface GridAccordionProps {
  items: AccordionItemData[];
  onUploadRequest: (item: AccordionItemData) => void;
  onImageClick: (image: ImageData, index: number, allImages: ImageData[], collectionId: string) => void;
  onEditRequest?: (item: AccordionItemData) => void; 
  onDeleteRequest?: (item: AccordionItemData) => void;
  isUserLoggedIn: boolean;
}

export function GridAccordion({ 
  items, 
  onUploadRequest, 
  onImageClick, 
  onEditRequest, 
  onDeleteRequest,
  isUserLoggedIn
}: GridAccordionProps) {
  if (!items || items.length === 0) {
    return <p className="text-center text-muted-foreground py-10">No items to display in the accordion.</p>;
  }
  
  return (
    <Accordion type="single" collapsible className="w-full max-w-4xl mx-auto space-y-2">
      {items.map((item) => (
        <AccordionItem value={item.id} key={item.id} className="border border-border border-secondary rounded-lg bg-secondary/50 shadow-sm overflow-hidden">
          <AccordionPrimitive.Header className="flex items-center group">
            <AccordionPrimitive.Trigger
              className={cn(
                "flex flex-1 items-center justify-between py-4 font-medium transition-all hover:none",
                "px-4 group-hover:bg-secondary/50"
              )}
            >
              <div className="flex flex-col items-start text-left flex-grow mr-2">
                <span className="text-base font-semibold group-hover:none">
                  {item.parishLocation}
                </span>
                <span className="text-xs text-muted-foreground mt-1">
                  {item.date} - {item.time}
                </span>
              </div>
              <ChevronDown className="h-4 w-4 shrink-0 transition-transform duration-200 data-[state=open]:rotate-180" />
            </AccordionPrimitive.Trigger>
            
            {isUserLoggedIn && onEditRequest && onDeleteRequest && (
              <div className="flex items-center space-x-1 pr-4 pl-2" onClick={(e) => e.stopPropagation()}>
                <Button 
                  variant="ghost" 
                  size="icon" 
                  className="h-8 w-8 hover:bg-muted/60" 
                  onClick={() => onEditRequest(item)}
                  title="Edit Collection"
                  aria-label="Edit collection details"
                >
                  <Pencil className="h-4 w-4" />
                </Button>
                <Button 
                  variant="ghost" 
                  size="icon" 
                  className="h-8 w-8 text-destructive hover:bg-destructive/10 hover:text-destructive" 
                  onClick={() => onDeleteRequest(item)}
                  title="Delete Collection"
                  aria-label="Delete collection"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            )}
          </AccordionPrimitive.Header>
          <AccordionContent className="bg-background/50 border-t border-border">
            <GridAccordionItemContent 
              item={item} 
              onUploadClick={() => onUploadRequest(item)} 
              onImageClick={(image, index, images) => onImageClick(image, index, images, item.id)}
            />
          </AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  );
}
