
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { NigerianMapModalProps, MapStateDataItem } from '@/types';
import { nigerianMap } from '@/lib/nigerian-map'; // Assuming this is the correct path to your map data
import { cn } from '@/lib/utils';

const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 }; // Default for SSR
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim(); // "262 52% 47%"
  const [h, s, l] = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  return { h, s, l };
};


export function NigerianMapModal({ isOpen, onOpenChange, massesPerState }: NigerianMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });

  React.useEffect(() => {
    setPrimaryHsl(getPrimaryHslValues());
  }, []);


  const mapDataWithMasses: MapStateDataItem[] = React.useMemo(() => {
    return nigerianMap.map(state => ({
      name: state.name,
      path: state.path,
      massCount: massesPerState[state.name] || 0,
    }));
  }, [massesPerState]);

  const maxMasses = React.useMemo(() => {
    return Math.max(1, ...mapDataWithMasses.map(s => s.massCount)); // Ensure maxMasses is at least 1 to avoid division by zero
  }, [mapDataWithMasses]);

  const getFillColor = (massCount: number) => {
    if (massCount === 0) {
      return 'hsl(var(--muted) / 0.3)'; // Muted color for states with no masses
    }
    // Calculate opacity: start at 0.2, go up to 1.0 for maxMasses
    const opacity = 0.2 + 0.8 * (massCount / maxMasses);
    return `hsla(${primaryHsl.h}, ${primaryHsl.s}%, ${primaryHsl.l}%, ${opacity})`;
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Nigeria</DialogTitle>
          <DialogDescription className="text-center text-muted-foreground">
            Hover over a state to see details. Color intensity indicates the number of scheduled Masses.
          </DialogDescription>
        </DialogHeader>
        <div className="mt-4 w-full aspect-[4/3] overflow-hidden rounded-md border">
          <TooltipProvider delayDuration={100}>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 750 650" // Adjust viewBox if needed based on your map data coordinates
              className="w-full h-full"
              aria-label="Map of Nigeria showing mass distribution"
            >
              <g>
                {mapDataWithMasses.map((state) => (
                  <Tooltip key={state.name}>
                    <TooltipTrigger asChild>
                      <path
                        d={state.path}
                        fill={getFillColor(state.massCount)}
                        stroke="hsl(var(--border))" // Or a contrasting color like 'hsl(var(--foreground) / 0.5)'
                        strokeWidth="0.5"
                        className="transition-opacity duration-150 hover:opacity-80 focus:outline-none focus:opacity-70"
                        aria-label={state.name}
                        tabIndex={0} // Make it focusable
                      />
                    </TooltipTrigger>
                    <TooltipContent>
                      <p className="font-semibold">{state.name}</p>
                      <p>{state.massCount} {state.massCount === 1 ? 'Mass' : 'Masses'} scheduled</p>
                    </TooltipContent>
                  </Tooltip>
                ))}
              </g>
            </svg>
          </TooltipProvider>
        </div>
      </DialogContent>
    </Dialog>
  );
}
