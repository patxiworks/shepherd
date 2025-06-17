
"use client";

import * as React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import type { NigerianMapModalProps, MapStateDataItem } from '@/types';
import { nigerianMap } from '@/lib/nigerian-map';
import { cn } from '@/lib/utils';

const getPrimaryHslValues = () => {
  if (typeof window === 'undefined') return { h: 262, s: 52, l: 47 }; // Default primary
  const style = getComputedStyle(document.documentElement);
  const primaryColor = style.getPropertyValue('--primary').trim();
  const [h, s, l] = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  return { h, s, l };
};

// Helper function to get approximate center for text.
// This is a simplified bounding box calculation and might not be perfect for all complex paths.
const getApproximateTextCoords = (path: string): { x: number; y: number } => {
  let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
  let currentX = 0, currentY = 0;
  let lastMoveX = 0, lastMoveY = 0; // Store the coords of the last M/m command

  // Simplified parsing of path commands and their coordinates
  const commands = path.match(/[a-df-z][^a-df-z]*/ig) || [];

  commands.forEach(commandStr => {
    const command = commandStr[0];
    const values = commandStr.substring(1).trim().split(/[ ,]+/).map(parseFloat).filter(v => !isNaN(v));

    let tempX = currentX;
    let tempY = currentY;

    if ((command === 'M' || command === 'm') && values.length >= 2) {
        if (command === 'M') { // Absolute moveto
            currentX = values[0];
            currentY = values[1];
        } else { // Relative moveto
            currentX += values[0];
            currentY += values[1];
        }
        lastMoveX = currentX; // Store the first M/m as a fallback
        lastMoveY = currentY;
        minX = Math.min(minX, currentX);
        maxX = Math.max(maxX, currentX);
        minY = Math.min(minY, currentY);
        maxY = Math.max(maxY, currentY);
        // Process remaining pairs in M/m (implicit L/l)
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
            if (command === 'L') { currentX = values[i++]; currentY = values[i++]; }
            else if (command === 'l') { currentX += values[i++]; currentY += values[i++]; }
            else if (command === 'H') { currentX = values[i++]; }
            else if (command === 'h') { currentX += values[i++]; }
            else if (command === 'V') { currentY = values[i++]; }
            else if (command === 'v') { currentY += values[i++]; }
            else { i++; continue; } // Should not happen with current regex but good for safety

            if (currentX !== undefined) {
              minX = Math.min(minX, currentX); maxX = Math.max(maxX, currentX);
            }
            if (currentY !== undefined) {
              minY = Math.min(minY, currentY); maxY = Math.max(maxY, currentY);
            }
        }
    }
    // Basic handling for curves by just taking their end points for bounding box
    // This is a simplification; true bounding box of curves is more complex.
    else if ( (command === 'C' || command === 'c' || command === 'S' || command === 's' || command === 'Q' || command === 'q' || command === 'T' || command === 't' || command === 'A' || command === 'a') && values.length >=2) {
        const lastValIndex = values.length -1;
        if (command === command.toLowerCase()) { // relative
            currentX += values[lastValIndex-1];
            currentY += values[lastValIndex];
        } else { // absolute
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
  // Fallback to the first explicit move-to coordinates if bounding box is degenerate
  return { x: lastMoveX || 0, y: lastMoveY || 0 };
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
    return Math.max(1, ...counts); // Ensure maxMasses is at least 1 to avoid division by zero
  }, [mapDataWithMasses]);

  const getFillColor = (massCount: number) => {
    const baseOpacity = 0.2; // Base opacity for states with 0 masses
    const maxOpacity = 1.0;  // Max opacity for state with most masses

    let opacity;
    if (massCount === 0) {
      opacity = baseOpacity;
    } else {
      // Scale opacity from baseOpacity up to maxOpacity
      opacity = baseOpacity + (maxOpacity - baseOpacity) * (massCount / maxMasses);
    }
    opacity = Math.min(Math.max(opacity, baseOpacity), maxOpacity); // Clamp opacity

    return `hsla(${primaryHsl.h}, ${primaryHsl.s}%, ${primaryHsl.l}%, ${opacity})`;
  };
  
  // Determine text color based on primary color lightness for contrast
  const textColor = primaryHsl.l > 50 ? 'hsl(var(--foreground))' : 'hsl(var(--primary-foreground))';


  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Nigeria</DialogTitle>
          <DialogDescription className="text-center text-muted-foreground">
            Color intensity indicates the number of scheduled Masses.
          </DialogDescription>
        </DialogHeader>
        <div className="mt-4 w-full aspect-[4/3] overflow-hidden rounded-md border">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 750 650" // Adjusted viewBox if necessary based on path coordinates
              className="w-full h-full"
            >
              {mapDataWithMasses.map((state) => {
                const { x, y } = getApproximateTextCoords(state.path);
                const stateNameParts = state.name.split(' ');
                const displayName = stateNameParts.length > 1 && state.name !== "FCT - Abuja" ? stateNameParts[0] : state.name;
                
                // Heuristic to scale font size, very basic
                const pathLength = state.path.length;
                let fontSize = "7px";
                if (pathLength < 1000) fontSize = "6px"; // Smaller states get smaller font
                if (pathLength < 500) fontSize = "5px"; 
                if (state.name === "Lagos" || state.name === "FCT - Abuja") fontSize = "6px";


                return (
                  <g key={state.name} className="group" tabIndex={0} aria-label={`${state.name}: ${state.massCount} ${state.massCount === 1 ? 'Mass' : 'Masses'}`}>
                    <path
                      d={state.path}
                      fill={getFillColor(state.massCount)}
                      stroke="hsl(var(--border))"
                      strokeWidth="0.5" // Thinner stroke for better text visibility
                      className="transition-opacity duration-150 group-hover:opacity-70 group-focus:opacity-70"
                    />
                    <text
                      x={x}
                      y={y}
                      fontSize={fontSize}
                      fill={textColor}
                      textAnchor="middle"
                      dominantBaseline="middle"
                      style={{ pointerEvents: 'none', userSelect: 'none' }}
                      className="font-medium"
                    >
                      {`${displayName} (${state.massCount})`}
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

