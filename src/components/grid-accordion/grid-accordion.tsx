
"use client";

import * as AccordionPrimitive from "@radix-ui/react-accordion";
import { Accordion, AccordionContent, AccordionItem } from '@/components/ui/accordion';
import { ChevronDown } from 'lucide-react';
import type { AccordionGroupData } from '@/types';
import { GridAccordionItemContent } from './grid-accordion-item-content';
import { cn } from "@/lib/utils";
import { getSectionColor } from "@/lib/section-colors";

interface GridAccordionProps {
  items: AccordionGroupData[];
  groupBy: 'centre' | 'activity' | 'date';
  defaultValue?: string;
}

export function GridAccordion({ 
  items, 
  groupBy,
  defaultValue
}: GridAccordionProps) {
  if (!items || items.length === 0) {
    return <p className="text-center text-muted-foreground py-10">No items to display.</p>;
  }
  
  return (
    <Accordion 
        type="single" 
        collapsible 
        className="w-full max-w-4xl mx-auto space-y-2"
        defaultValue={defaultValue}
        key={defaultValue}
    >
      {items.map((item) => {
        const headerColor = groupBy === 'centre' ? getSectionColor(item.mainSection) : '';
        return (
          <AccordionItem 
            value={item.id} 
            key={item.id} 
            className="border border-border rounded-lg shadow-sm overflow-hidden"
            style={{ backgroundColor: headerColor ? `${headerColor}33` : 'hsl(var(--secondary)/0.5)' }}
          >
            <AccordionPrimitive.Header className="flex items-center group data-[state=open]:bg-primary/80 data-[state=open]:text-white">
              <AccordionPrimitive.Trigger
                className={cn(
                  "flex flex-1 items-center justify-between py-4 font-medium transition-all hover:none",
                  "px-4 group-hover:bg-primary/80 group-hover:text-white"
                )}
              >
                <div className="flex flex-col items-start text-left flex-grow mr-2">
                  <span className="text-base font-semibold group-hover:none">
                    {item.title}
                  </span>
                </div>
                <ChevronDown className="h-4 w-4 shrink-0 transition-transform duration-200 data-[state=open]:rotate-180" />
              </AccordionPrimitive.Trigger>
            </AccordionPrimitive.Header>
            <AccordionContent className="bg-background/100 border-t border-border">
              <GridAccordionItemContent 
                item={item} 
                groupBy={groupBy}
              />
            </AccordionContent>
          </AccordionItem>
        )
      })}
    </Accordion>
  );
}
