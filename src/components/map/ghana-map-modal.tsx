
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import type { GhanaMapModalProps, MapStateDataItem as MapRegionDataItem, AccordionItemData } from '@/types';
import { ghanaMap } from '@/lib/ghana-map';
import { cn } from '@/lib/utils';

const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 }; // Default primary
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim();
  if (!primaryColor) return { h: 262, s: 52, l: 47 }; // Fallback
  const parts = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  if (parts.length === 3 && !parts.some(isNaN)) {
    return { h: parts[0], s: parts[1], l: parts[2] };
  }
  return { h: 262, s: 52, l: 47 }; // Fallback if parsing fails
};

export function GhanaMapModal({ isOpen, onOpenChange, massesPerRegion, accordionItems }: GhanaMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });
  const [hoveredRegion, setHoveredRegion] = React.useState<MapRegionDataItem | null>(null);

  React.useEffect(() => {
    if (isOpen && typeof window !== 'undefined') {
      setPrimaryHsl(getPrimaryHslValues());
    }
    if (!isOpen) {
      setHoveredRegion(null); // Reset hovered region when modal closes
    }
  }, [isOpen]);

  const mapDataWithMasses: MapRegionDataItem[] = React.useMemo(() => {
    return ghanaMap.map(region => ({
      name: region.name,
      path: region.path,
      massCount: massesPerRegion[region.name] || 0,
    }));
  }, [massesPerRegion]);

  const maxMasses = React.useMemo(() => {
    const counts = mapDataWithMasses.map(r => r.massCount);
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

  const diocesesInHoveredRegion = React.useMemo(() => {
    if (!hoveredRegion || !accordionItems) return [];
    const itemsInRegion = accordionItems.filter(item => item.country === "Ghana" && item.state === hoveredRegion.name);
    const dioceseSet = new Set<string>();
    itemsInRegion.forEach(item => dioceseSet.add(item.diocese));
    return Array.from(dioceseSet).sort();
  }, [hoveredRegion, accordionItems]);


  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl md:max-w-4xl lg:max-w-5xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Ghana</DialogTitle>
          <DialogDescription className="text-xs text-center text-muted-foreground">
            Color intensity indicates the number of scheduled Masses. Hover or select a region.
          </DialogDescription>
        </DialogHeader>
        <div className="mt-0 flex flex-col md:flex-row gap-4 items-stretch">
          <div className="md:w-3/4 aspect-[4/3] overflow-hidden rounded-md border">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 800 900" 
              className="w-full h-full"
            >
              {mapDataWithMasses.map((region) => (
                <g
                  key={region.name}
                  className="group cursor-pointer outline-none"
                  tabIndex={0}
                  aria-label={`${region.name}: ${region.massCount} ${region.massCount === 1 ? 'Mass' : 'Masses'}`}
                  //onMouseEnter={() => setHoveredRegion(region)}
                  //onMouseLeave={() => setHoveredRegion(null)}
                  onFocus={() => setHoveredRegion(region)}
                  onBlur={() => setTimeout(() => {
                      const nextFocusedElement = document.activeElement;
                      const isNextElementSvgRegion = nextFocusedElement && nextFocusedElement.classList && nextFocusedElement.classList.contains('group') && nextFocusedElement.closest('svg');
                      if (!isNextElementSvgRegion) {
                          setHoveredRegion(null);
                      }
                   }, 0)}
                >
                  <path
                    d={region.path}
                    fill={getFillColor(region.massCount)}
                    stroke="hsl(var(--border))"
                    strokeWidth="0.5"
                    className={cn(
                      "transition-colors duration-150",
                      "group-hover:fill-green-500 group-focus:fill-green-500"
                    )}
                  />
                </g>
              ))}
            </svg>
          </div>
          <div className="md:w-1/4 p-4 border rounded-md bg-card flex flex-col">
            {hoveredRegion ? (
              <div className="flex sm:flex-col h-full">
                <div className="flex-grow">
                  <h3 className="text-base font-semibold text-primary mb-1">{hoveredRegion.name}</h3>
                  <p className="text-xs text-muted-foreground mb-3">
                    {hoveredRegion.massCount} {hoveredRegion.massCount === 1 ? 'Mass' : 'Masses'}
                  </p>
                </div>
                {diocesesInHoveredRegion.length > 0 && (
                  <div className="pl-3 border-l sm:pl-0 sm:pt-2 sm:border-l-0 sm:border-t border-border">
                    <h4 className="text-xs font-medium text-foreground mb-1">Dioceses:</h4>
                    <ul className="text-xs text-muted-foreground list-disc list-inside space-y-0.5 max-h-[200px] overflow-y-auto">
                      {diocesesInHoveredRegion.map(diocese => (
                        <li key={diocese}>{diocese}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            ) : (
              <p className="text-muted-foreground text-sm m-auto text-center">Hover over or select a region to see details.</p>
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

