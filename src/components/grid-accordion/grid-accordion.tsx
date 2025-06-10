
"use client";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Pencil, Trash2 } from 'lucide-react';
import type { AccordionItemData, ImageData } from '@/types';
import { GridAccordionItemContent } from './grid-accordion-item-content';

interface GridAccordionProps {
  items: AccordionItemData[];
  onUploadRequest: (item: AccordionItemData) => void;
  onImageClick: (image: ImageData, index: number, allImages: ImageData[]) => void;
  onEditRequest: (item: AccordionItemData) => void;
  onDeleteRequest: (item: AccordionItemData) => void;
}

export function GridAccordion({ items, onUploadRequest, onImageClick, onEditRequest, onDeleteRequest }: GridAccordionProps) {
  if (!items || items.length === 0) {
    return <p className="text-center text-muted-foreground py-10">No items to display in the accordion.</p>;
  }
  
  return (
    <Accordion type="single" collapsible className="w-full max-w-4xl mx-auto space-y-2">
      {items.map((item) => (
        <AccordionItem value={item.id} key={item.id} className="border border-border rounded-lg shadow-sm bg-card overflow-hidden">
          <AccordionTrigger className="px-6 py-4 hover:bg-secondary/50 transition-colors duration-150 group">
            <div className="flex flex-col items-start text-left flex-grow mr-2">
              <span className="text-base font-semibold group-hover:underline">
                {item.parishLocation}{item.diocese ? ` - ${item.diocese}` : ''}
              </span>
              <span className="text-xs text-muted-foreground mt-1">
                {item.date} - {item.time}
              </span>
            </div>
            <div className="flex items-center space-x-1 ml-auto" onClick={(e) => e.stopPropagation()}>
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
          </AccordionTrigger>
          <AccordionContent className="bg-background/50 border-t border-border">
            <GridAccordionItemContent 
              item={item} 
              onUploadClick={() => onUploadRequest(item)} 
              onImageClick={(image, index) => onImageClick(image, index, item.images)}
            />
          </AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  );
}
