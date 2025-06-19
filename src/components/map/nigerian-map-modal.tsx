
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
  if (!primaryColor) return { h: 262, s: 52, l: 47 }; // Fallback
  const parts = primaryColor.split(' ').map(v => parseFloat(v.replace('%', '')));
  if (parts.length === 3 && !parts.some(isNaN)) {
    return { h: parts[0], s: parts[1], l: parts[2] };
  }
  return { h: 262, s: 52, l: 47 }; // Fallback if parsing fails
};

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
  return { x: lastMoveX || 0, y: lastMoveY || 0 };
};


export function NigerianMapModal({ isOpen, onOpenChange, massesPerState }: NigerianMapModalProps) {
  const [primaryHsl, setPrimaryHsl] = React.useState({ h: 262, s: 52, l: 47 });
  const [hoveredState, setHoveredState] = React.useState<MapStateDataItem | null>(null);

  React.useEffect(() => {
    if (isOpen && typeof window !== 'undefined') {
      setPrimaryHsl(getPrimaryHslValues());
    }
    if (!isOpen) {
      setHoveredState(null); // Reset hovered state when modal closes
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
  
  const baseTextColor = primaryHsl.l > 60 ? 'hsl(var(--foreground))' : 'hsl(var(--primary-foreground))';


  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl lg:max-w-4xl p-4 md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl md:text-2xl font-headline text-center">Map of Masses in Nigeria</DialogTitle>
          <DialogDescription className="text-xs text-center text-muted-foreground">
            Color intensity indicates the number of scheduled Masses
          </DialogDescription>
        </DialogHeader>
        <div className="mt-0 w-full aspect-[4/3] overflow-hidden rounded-md border">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 750 650" 
              className="w-full h-full"
            >
              {mapDataWithMasses.map((state) => {
                const { x, y } = getApproximateTextCoords(state.path);
                const stateNameParts = state.name.split(' ');
                const displayName = stateNameParts.length > 1 && state.name !== "FCT - Abuja" && state.name.length > 10 ? stateNameParts[0] : state.name;
                
                const pathLength = state.path.length; 
                let baseFontSizeClass = 'text-[7px]';
                let hoverFontSizeClass = 'group-hover:text-[7px] group-focus:text-[7px]';

                if (pathLength < 500 || state.name === "Lagos" || state.name === "FCT - Abuja" ) { // For very small states
                    baseFontSizeClass = 'text-[5px]';
                    hoverFontSizeClass = 'group-hover:text-[10px] group-focus:text-[10px]';
                } else if (pathLength < 1000) { // For small to medium states
                    baseFontSizeClass = 'text-[6px]';
                    hoverFontSizeClass = 'group-hover:text-[10px] group-focus:text-[10px]';
                }


                return (
                  <g 
                    key={state.name} 
                    className="svg-state group cursor-pointer outline-none" 
                    tabIndex={0} 
                    aria-label={`${state.name}: ${state.massCount} ${state.massCount === 1 ? 'Mass' : 'Masses'}`}
                    //onMouseEnter={() => setHoveredState(state)}
                    //onMouseLeave={() => setHoveredState(null)}
                    onFocus={() => setHoveredState(state)}
                    //onBlur={() => setHoveredState(null)}
                    onBlur={(event) => {
                      // Use a small delay to allow the next element's onFocus to fire
                      setTimeout(() => {
                        // Check if the newly focused element is NOT one of your SVG regions
                        const nextFocusedElement = document.activeElement;
                        const isNextElementSvgState = nextFocusedElement && nextFocusedElement.classList && nextFocusedElement.classList.contains('svg-state'); // Logic to check if nextFocusedElement is an SVG region
                    
                        if (!isNextElementSvgState) {
                          setHoveredState(null);
                        }
                      }, 50); // Adjust delay as needed
                    }}
                  >
                    <path
                      d={state.path}
                      fill={getFillColor(state.massCount)}
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
                        "font-medium transition-all duration-150 ease-in-out fill-[--base-text-color] group-hover:fill-black group-focus:fill-black",
                        baseFontSizeClass,
                        hoverFontSizeClass
                      )}
                      style={{ '--base-text-color': baseTextColor, pointerEvents: 'none', userSelect: 'none' } as React.CSSProperties}
                    >
                       <tspan x={x} dy="-0.1em">{displayName}</tspan>
                       <tspan x={x} dy="1.1em">({state.massCount} Masses)</tspan>
                    </text>
                  </g>
                );
              })}
            </svg>
        </div>
        <div className="md:w-1/4 p-4 border rounded-md bg-card flex flex-col justify-center items-center md:items-start">
          {hoveredState ? (
            <>
              <h3 className="text-lg font-semibold text-primary">{hoveredState.name}</h3>
              <p className="text-muted-foreground">
                {hoveredState.massCount} {hoveredState.massCount === 1 ? 'Mass' : 'Masses'}
              </p>
            </>
          ) : (
            <p className="text-muted-foreground text-center md:text-left">Select a region to see details.</p>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

