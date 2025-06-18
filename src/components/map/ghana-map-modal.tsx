
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import type { GhanaMapModalProps, MapStateDataItem as MapRegionDataItem } from '@/types';
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

export function GhanaMapModal({ isOpen, onOpenChange, massesPerRegion }: GhanaMapModalProps) {
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

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl md:max-w-4xl lg:max-w-5xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Ghana</DialogTitle>
          <DialogDescription className="text-center text-muted-foreground">
            Color intensity indicates the number of scheduled Masses. Hover or focus on a region to see details.
          </DialogDescription>
        </DialogHeader>
        <div className="mt-4 flex flex-col md:flex-row gap-4">
          <div className="md:w-3/4 aspect-[4/3] overflow-hidden rounded-md border">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 800 900" // Adjusted for typical Ghana map coordinates
              className="w-full h-full"
            >
              {mapDataWithMasses.map((region) => (
                <g
                  key={region.name}
                  className="group cursor-pointer outline-none"
                  tabIndex={0}
                  aria-label={`${region.name}: ${region.massCount} ${region.massCount === 1 ? 'Mass' : 'Masses'}`}
                  onMouseEnter={() => setHoveredRegion(region)}
                  onMouseLeave={() => setHoveredRegion(null)}
                  onFocus={() => setHoveredRegion(region)}
                  onBlur={() => setHoveredRegion(null)}
                >
                  <path
                    d={region.path}
                    fill={getFillColor(region.massCount)}
                    stroke="#666666" //hsl(var(--border))"
                    strokeWidth="0.5"
                    className={cn(
                      "transition-colors duration-150",
                      "group-hover:fill-[#FCD116] group-focus:fill-[#FCD116]"
                    )}
                  />
                </g>
              ))}
            </svg>
          </div>
          <div className="md:w-1/4 p-4 border rounded-md bg-card flex flex-col justify-center items-center md:items-start">
            {hoveredRegion ? (
              <>
                <h3 className="text-lg font-semibold text-primary">{hoveredRegion.name}</h3>
                <p className="text-muted-foreground">
                  {hoveredRegion.massCount} {hoveredRegion.massCount === 1 ? 'Mass' : 'Masses'}
                </p>
              </>
            ) : (
              <p className="text-muted-foreground text-center md:text-left">Hover over or focus on a region to see details.</p>
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
