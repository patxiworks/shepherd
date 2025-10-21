
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
  masses: any;
  groupBy: 'centre' | 'activity' | 'date';
  value?: string;
  onValueChange?: (value: string) => void;
}

export function GridAccordion({ 
  items,
  masses,
  groupBy,
  value,
  onValueChange
}: GridAccordionProps) {
  if (!items || items.length === 0) {
    return <p className="text-center text-muted-foreground py-10">No items to display.</p>;
  }
  
  return (
    <Accordion 
        type="single" 
        collapsible 
        className="w-full max-w-4xl mx-auto space-y-2"
        value={value}
        onValueChange={onValueChange}
    >
      {items.map((item) => {
        const headerColor = groupBy === 'centre' ? getSectionColor(item.mainSection) : '';
        return (
          <AccordionItem 
            id={`accordion-group-${item.id}`}
            value={item.id} 
            key={item.id} 
            className="border border-border data-[state=open]:border-primary/80 rounded-lg shadow-sm overflow-hidden"
            style={{ backgroundColor: headerColor ? `${headerColor}33` : 'hsl(var(--secondary)/0.5)' }}
          >
            <AccordionPrimitive.Header className="flex items-center group">
              <AccordionPrimitive.Trigger
                className={cn(
                  "flex flex-1 items-center justify-between py-4 font-medium transition-all",
                  "px-4 hover:filter hover:brightness-95 data-[state=open]:bg-primary/80 data-[state=open]:text-primary-foreground data-[state=open]:filter-none"
                )}
              >
                <div className="flex flex-col items-start text-left flex-grow mr-2">
                  <span className="text-base font-semibold">
                    {item.title}
                  </span>
                  {groupBy === 'date' && masses[item.id] && (
                    <span className="text-xs text-muted-foreground group-data-[state=open]:text-primary-foreground/80 mt-1">
                      {masses[item.id].Mass}
                    </span>
                  )}
                </div>
                <ChevronDown className="h-4 w-4 shrink-0 transition-transform duration-200 data-[state=open]:rotate-180" />
              </AccordionPrimitive.Trigger>
            </AccordionPrimitive.Header>
            <AccordionContent className="bg-background/100 border-t border-primary">
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
