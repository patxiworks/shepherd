
"use client";

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Button } from '@/components/ui/button';
import type { StateSummaryModalProps } from '@/types';

export function StateSummaryModal({ isOpen, onOpenChange, summaryData, onApplyFilter }: StateSummaryModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>State Summary</DialogTitle>
          <DialogDescription>
            Breakdown of collections by state. Click a count to filter.
          </DialogDescription>
        </DialogHeader>
        <ScrollArea className="h-[300px] w-full rounded-md border p-4 mt-4">
          {summaryData.length > 0 ? (
            <ul className="space-y-2">
              {summaryData.map((item) => (
                <li key={item.name} className="flex justify-between items-center text-sm">
                  <span>{item.name}</span>
                   <Button
                    variant="link"
                    className="font-semibold bg-primary/10 text-primary px-2 py-0.5 rounded-full h-auto hover:bg-primary/20"
                    onClick={() => onApplyFilter(item.name)}
                  >
                    {item.count}
                  </Button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground text-center">No state data available.</p>
          )}
        </ScrollArea>
      </DialogContent>
    </Dialog>
  );
}
