
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { NigerianMapModalProps, MapStateDataItem } from '@/types';
import { nigerianMap } from '@/lib/nigerian-map'; 
import { cn } from '@/lib/utils';

const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 }; // Default for SSR
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim(); 
  const [h, s, l] = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  return { h, s, l };
};


export function NigerianMapModal({ isOpen, onOpenChange, massesPerState }: NigerianMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });

  React.useEffect(() => {
    if (isOpen) { // Only get/set HSL values when modal is open to ensure CSS is available
      setPrimaryHsl(getPrimaryHslValues());
    }
  }, [isOpen]);


  const mapDataWithMasses: MapStateDataItem[] = React.useMemo(() => {
    return nigerianMap.map(state => ({
      name: state.name,
      path: state.path,
      massCount: massesPerState[state.name] || 0,
    }));
  }, [massesPerState]);

  const maxMasses = React.useMemo(() => {
    const counts = mapDataWithMasses.map(s => s.massCount);
    return Math.max(1, ...counts); // Ensure maxMasses is at least 1 to avoid division by zero or negative opacity issues
  }, [mapDataWithMasses]);

  const getFillColor = (massCount: number) => {
    const baseOpacity = 0.2; // Base opacity for zero masses (20%)
    const maxOpacity = 1.0;   // Max opacity for maxMasses (100%)
    
    let opacity;
    if (massCount === 0) {
      opacity = baseOpacity;
    } else {
      // Scale opacity from baseOpacity to maxOpacity
      opacity = baseOpacity + (maxOpacity - baseOpacity) * (massCount / maxMasses);
    }
    opacity = Math.min(Math.max(opacity, baseOpacity), maxOpacity); // Clamp opacity

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
              viewBox="0 0 750 650" 
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
                        stroke="hsl(var(--border))" 
                        strokeWidth="0.5"
                        className="transition-opacity duration-150 hover:opacity-80 focus:outline-none focus:opacity-70"
                        aria-label={`${state.name}: ${state.massCount} ${state.massCount === 1 ? 'Mass' : 'Masses'}`}
                        tabIndex={0} 
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

