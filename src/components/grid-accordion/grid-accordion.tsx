"use client";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';
import type { AccordionItemData } from '@/types';
import { GridAccordionItemContent } from './grid-accordion-item-content';

interface GridAccordionProps {
  items: AccordionItemData[];
  onUploadRequest: (itemId: string, itemTitle: string) => void;
}

export function GridAccordion({ items, onUploadRequest }: GridAccordionProps) {
  if (!items || items.length === 0) {
    return <p className="text-center text-muted-foreground py-10">No items to display in the accordion.</p>;
  }
  
  return (
    <Accordion type="single" collapsible className="w-full max-w-4xl mx-auto space-y-2">
      {items.map((item) => (
        <AccordionItem value={item.id} key={item.id} className="border border-border rounded-lg shadow-sm bg-card overflow-hidden">
          <AccordionTrigger className="px-6 py-4 text-lg font-headline hover:bg-secondary/50 transition-colors duration-150">
            {item.title}
          </AccordionTrigger>
          <AccordionContent className="bg-background/50 border-t border-border">
            <GridAccordionItemContent 
              item={item} 
              onUploadClick={() => onUploadRequest(item.id, item.title)} 
            />
          </AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  );
}
