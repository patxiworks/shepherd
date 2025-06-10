
"use client";

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { SummaryItem } from '@/types';

interface DioceseSummaryModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  summaryData: SummaryItem[];
}

export function DioceseSummaryModal({ isOpen, onOpenChange, summaryData }: DioceseSummaryModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Diocese Summary</DialogTitle>
          <DialogDescription>
            Breakdown of collections by diocese.
          </DialogDescription>
        </DialogHeader>
        <ScrollArea className="h-[300px] w-full rounded-md border p-4 mt-4">
          {summaryData.length > 0 ? (
            <ul className="space-y-2">
              {summaryData.map((item) => (
                <li key={item.name} className="flex justify-between items-center text-sm">
                  <span>{item.name}</span>
                  <span className="font-semibold bg-primary/10 text-primary px-2 py-0.5 rounded-full">{item.count}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground text-center">No diocese data available.</p>
          )}
        </ScrollArea>
      </DialogContent>
    </Dialog>
  );
}
