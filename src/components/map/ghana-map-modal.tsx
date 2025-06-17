
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import type { GhanaMapModalProps, MapStateDataItem as MapRegionDataItem, GhanaMapRegionDataItem } from '@/types'; // Reusing MapStateDataItem as MapRegionDataItem
import { ghanaMap } from '@/lib/ghana-map'; // Using Ghana-specific map data
import { cn } from '@/lib/utils';

// Helper function to get HSL values for primary color from CSS variables
const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 }; // Default primary (Deep Purple)
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim();
  if (!primaryColor) return { h: 262, s: 52, l: 47 }; // Fallback
  const parts = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  if (parts.length === 3 && !parts.some(isNaN)) {
    return { h: parts[0], s: parts[1], l: parts[2] };
  }
  return { h: 262, s: 52, l: 47 }; // Fallback if parsing fails
};

// Helper function to approximate center of an SVG path for text placement
const getApproximateTextCoords = (path: string): { x: number; y: number } => {
  let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
  let currentX = 0, currentY = 0;
  let lastMoveX = 0, lastMoveY = 0;

  const commands = path.match(/[a-df-z][^a-df-z]*/ig) || [];

  commands.forEach(commandStr => {
    const command = commandStr[0];
    const values = commandStr.substring(1).trim().split(/[ ,]+/).map(parseFloat).filter(v => !isNaN(v));

    if ((command === 'M' || command === 'm') && values.length >= 2) {
        if (command === 'M') { 
            currentX = values[0];
            currentY = values[1];
        } else { 
            currentX += values[0];
            currentY += values[1];
        }
        lastMoveX = currentX; 
        lastMoveY = currentY;
        minX = Math.min(minX, currentX);
        maxX = Math.max(maxX, currentX);
        minY = Math.min(minY, currentY);
        maxY = Math.max(maxY, currentY);
        
        for (let i = 2; i < values.length; i += 2) {
            if (command === 'M') {
                currentX = values[i]; currentY = values[i+1];
            } else {
                currentX += values[i]; currentY += values[i+1];
            }
            minX = Math.min(minX, currentX); maxX = Math.max(maxX, currentX);
            minY = Math.min(minY, currentY); maxY = Math.max(maxY, currentY);
        }
    } else if ((command === 'L' || command === 'l' || command === 'H' || command === 'h' || command === 'V' || command === 'v') && values.length > 0) {
        for (let i = 0; i < values.length; ) {
            let val1: number | undefined, val2: number | undefined;
            if (command === 'L') { val1 = values[i++]; val2 = values[i++]; }
            else if (command === 'l') { val1 = currentX + values[i++]; val2 = currentY + values[i++]; }
            else if (command === 'H') { val1 = values[i++]; val2 = currentY; }
            else if (command === 'h') { val1 = currentX + values[i++]; val2 = currentY; }
            else if (command === 'V') { val1 = currentX; val2 = values[i++]; }
            else if (command === 'v') { val1 = currentX; val2 = currentY + values[i++]; }
            else { i++; continue; }

            if (val1 !== undefined) currentX = val1;
            if (val2 !== undefined) currentY = val2;

            if (currentX !== undefined) {
              minX = Math.min(minX, currentX); maxX = Math.max(maxX, currentX);
            }
            if (currentY !== undefined) {
              minY = Math.min(minY, currentY); maxY = Math.max(maxY, currentY);
            }
        }
    }
    else if ( (command === 'C' || command === 'c' || command === 'S' || command === 's' || command === 'Q' || command === 'q' || command === 'T' || command === 't' || command === 'A' || command === 'a') && values.length >=2) {
        const lastValIndex = values.length -1;
        if (command === command.toLowerCase()) { 
            currentX += values[lastValIndex-1];
            currentY += values[lastValIndex];
        } else { 
            currentX = values[lastValIndex-1];
            currentY = values[lastValIndex];
        }
        minX = Math.min(minX, currentX); maxX = Math.max(maxX, currentX);
        minY = Math.min(minY, currentY); maxY = Math.max(maxY, currentY);
    }
  });

  if (minX !== Infinity && maxX !== -Infinity && minY !== Infinity && maxY !== -Infinity) {
    return { x: minX + (maxX - minX) / 2, y: minY + (maxY - minY) / 2 };
  }
  return { x: lastMoveX || 0, y: lastMoveY || 0 }; // Fallback to last explicit move
};


export function GhanaMapModal({ isOpen, onOpenChange, massesPerRegion }: GhanaMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });

  React.useEffect(() => {
    if (isOpen && typeof window !== 'undefined') {
      setPrimaryHsl(getPrimaryHslValues());
    }
  }, [isOpen]);

  const mapDataWithMasses: MapRegionDataItem[] = React.useMemo(() => {
    return ghanaMap.map(region => ({
      name: region.name,
      path: region.path,
      code: region.code, // Keep ghana specific code if needed
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
      // Scale opacity from baseOpacity up to maxOpacity
      opacity = baseOpacity + (maxOpacity - baseOpacity) * (massCount / maxMasses);
    }
    opacity = Math.min(Math.max(opacity, baseOpacity), maxOpacity); 

    return `hsla(${primaryHsl.h}, ${primaryHsl.s}%, ${primaryHsl.l}%, ${opacity})`;
  };
  
  const baseTextColor = primaryHsl.l > 60 ? 'hsl(var(--foreground))' : 'hsl(var(--primary-foreground))';

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Ghana</DialogTitle>
          <DialogDescription className="text-center text-muted-foreground">
            Color intensity indicates the number of scheduled Masses. Hover or focus on a region to enlarge its text.
          </DialogDescription>
        </DialogHeader>
        <div className="mt-4 w-full aspect-[4/3] overflow-hidden rounded-md border">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 800 900" // Adjusted for typical Ghana map coordinates
              className="w-full h-full"
            >
              {mapDataWithMasses.map((region) => {
                const { x, y } = getApproximateTextCoords(region.path);
                const regionNameParts = region.name.split(' ');
                const displayName = regionNameParts.length > 1 && region.name.length > 10 && !region.name.includes("-") ? regionNameParts[0] : region.name;
                
                const pathLength = region.path.length; 
                let baseFontSizeClass = 'text-[6px]'; // Adjusted for potentially larger Ghana map
                let hoverFontSizeClass = 'group-hover:text-[10px] group-focus:text-[10px]';

                if (pathLength < 500) { 
                    baseFontSizeClass = 'text-[4px]';
                    hoverFontSizeClass = 'group-hover:text-[7px] group-focus:text-[7px]';
                } else if (pathLength < 1000) { 
                    baseFontSizeClass = 'text-[5px]';
                    hoverFontSizeClass = 'group-hover:text-[8px] group-focus:text-[8px]';
                }


                return (
                  <g 
                    key={region.name} 
                    className="group cursor-pointer outline-none" 
                    tabIndex={0} 
                    aria-label={`${region.name}: ${region.massCount} ${region.massCount === 1 ? 'Mass' : 'Masses'}`}
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
                    <text
                      x={x}
                      y={y}
                      textAnchor="middle"
                      dominantBaseline="middle"
                      className={cn(
                        "font-medium transition-all duration-150 ease-in-out group-hover:fill-black group-focus:fill-black",
                        baseFontSizeClass,
                        hoverFontSizeClass
                      )}
                      style={{ '--base-text-color': baseTextColor, pointerEvents: 'none', userSelect: 'none' } as React.CSSProperties}
                    >
                       <tspan x={x} dy="-0.2em">{displayName}</tspan>
                       <tspan x={x} dy="1em">({region.massCount})</tspan>
                    </text>
                  </g>
                );
              })}
            </svg>
        </div>
      </DialogContent>
    </Dialog>
  );
}
