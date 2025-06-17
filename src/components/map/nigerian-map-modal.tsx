
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { NigerianMapModalProps, MapStateDataItem } from '@/types';
import { nigerianMap } from '@/lib/nigerian-map';
import { cn } from '@/lib/utils';

const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 };
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim();
  const [h, s, l] = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  return { h, s, l };
};


export function NigerianMapModal({ isOpen, onOpenChange, massesPerState }: NigerianMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });

  React.useEffect(() => {
    if (isOpen && typeof window !== 'undefined') {
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
    return Math.max(1, ...counts);
  }, [mapDataWithMasses]);

  const getFillColor = (massCount: number) => {
    const baseOpacity = 0.2;
    const maxOpacity = 1.0;

    let opacity;
    if (massCount === 0) {
      opacity = baseOpacity;
    } else {
      opacity = baseOpacity + (maxOpacity - baseOpacity) * (massCount / maxMasses);
    }
    opacity = Math.min(Math.max(opacity, baseOpacity), maxOpacity);

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
        <div className="mt-4 w-full aspect-[4/3] overflow-visible rounded-md border">
          <TooltipProvider delayDuration={0}>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 750 650"
              className="w-full h-full"
            >
              {mapDataWithMasses.map((state) => (
                <Tooltip key={state.name}>
                  <TooltipTrigger asChild>
                    <g
                      className="cursor-pointer group"
                      tabIndex={0}
                      style={{ pointerEvents: 'all' }} // Explicitly make <g> the event target
                    >
                      <path
                        d={state.path}
                        fill={getFillColor(state.massCount)}
                        stroke="hsl(var(--border))"
                        strokeWidth="1"
                        className="transition-opacity duration-150 group-hover:opacity-70 group-focus:opacity-70"
                      />
                    </g>
                  </TooltipTrigger>
                  <TooltipContent side="top" avoidCollisions>
                    <p className="font-semibold">{state.name}</p>
                    <p>{state.massCount} {state.massCount === 1 ? 'Mass' : 'Masses'}</p>
                  </TooltipContent>
                </Tooltip>
              ))}
            </svg>
          </TooltipProvider>
        </div>
      </DialogContent>
    </Dialog>
  );
}
