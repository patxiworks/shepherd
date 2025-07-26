
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import type { CentreData, ApiActivity } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Loader2, XIcon } from 'lucide-react';
import { format as formatDateFns } from 'date-fns';

const sortCentres = (a: CentreData, b: CentreData): number => {
  if (!a.id || !b.id) return 0;
  return a.id.localeCompare(b.id);
};

export default function HomePage() {
  const [accordionItems, setAccordionItems] = React.useState<CentreData[]>([]);
  const [isLoading, setIsLoading] = React.useState(true);
  const [filterQuery, setFilterQuery] = React.useState('');
  const [selectedPriest, setSelectedPriest] = React.useState('All Priests');
  const { toast } = useToast();

  React.useEffect(() => {
    const fetchActivities = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/collections');
        if (!response.ok) {
          throw new Error('Failed to fetch activities');
        }
        const data: ApiActivity[] = await response.json();
        
        const centresMap = new Map<string, CentreData>();
        
        data.forEach(activity => {
          const centreName = activity.centre;
          if (centreName && !centresMap.has(centreName)) {
            centresMap.set(centreName, {
              id: centreName,
              centre: centreName,
              activities: []
            });
          }
          
          if(centreName) {
            const fromTime = activity.from ? formatDateFns(new Date(activity.from), "h:mm a") : "N/A";
            const toTime = activity.to ? formatDateFns(new Date(activity.to), "h:mm a") : "N/A";

            centresMap.get(centreName)!.activities.push({
              activity: activity.activity,
              day: activity.day,
              priest: activity.priest,
              time: `${fromTime} - ${toTime}`
            });
          }
        });
        
        const sortedCentres = Array.from(centresMap.values()).sort(sortCentres);
        setAccordionItems(sortedCentres);

      } catch (error) {
        console.error("Error fetching activities:", error);
        toast({
          title: "Error",
          description: "Could not load activities data.",
          variant: "destructive",
        });
      } finally {
        setIsLoading(false);
      }
    };
    fetchActivities();
  }, [toast]);
  
  const priests = React.useMemo(() => {
    const priestSet = new Set<string>();
    accordionItems.forEach(centre => {
        centre.activities.forEach(activity => {
            if (activity.priest) {
                priestSet.add(activity.priest);
            }
        });
    });
    return ["All Priests", ...Array.from(priestSet).sort()];
  }, [accordionItems]);


  const filteredAccordionItems = React.useMemo(() => {
    let filteredItems = accordionItems;

    // Filter by priest first
    if (selectedPriest && selectedPriest !== 'All Priests') {
      filteredItems = filteredItems
        .map(centre => {
          const priestActivities = centre.activities.filter(
            activity => activity.priest === selectedPriest
          );
          return { ...centre, activities: priestActivities };
        })
        .filter(centre => centre.activities.length > 0);
    }
    
    // Then filter by text query
    if (filterQuery) {
      const lowercasedQuery = filterQuery.toLowerCase();
      return filteredItems.filter(item =>
        (item.centre && item.centre.toLowerCase().includes(lowercasedQuery)) ||
        item.activities.some(activity => 
          (activity.activity && activity.activity.toLowerCase().includes(lowercasedQuery)) ||
          (activity.day && activity.day.toLowerCase().includes(lowercasedQuery)) ||
          (activity.priest && activity.priest.toLowerCase().includes(lowercasedQuery))
        )
      );
    }
    
    return filteredItems;

  }, [accordionItems, filterQuery, selectedPriest]);


  if (isLoading) {
    return (
      <div className="container mx-auto px-4 py-8 min-h-screen flex flex-col items-center justify-center">
        <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
        <p className="text-xl text-muted-foreground">
          Loading Activities...
        </p>
      </div>
    );
  }

  return (
    <>
      <div className="sticky top-0 z-50 bg-background shadow-sm">
        <div className="main-header border-b border-t-2 border-black">
          <header className="relative mx-auto container pt-20 pb-2 px-4 text-left">
            <div className="w-full text-xs text-muted-foreground">Activity Schedule</div>
            <h1 className="w-[220px] sm:w-full leading-none text-lg sm:text-xl md:text-2xl font-headline font-bold mb-3">
              Centre Activities
            </h1>
          </header>
        </div>
        <div className="container mx-auto sm:px-4 sm:py-3 sm:border-b-1 sm:shadow-sm">
          <div className="flex flex-col sm:flex-row gap-2">
            <div className="relative flex-grow flex justify-center items-center">
                <Input
                  type="text"
                  placeholder="Filter by centre, activity, day, or priest..."
                  value={filterQuery}
                  onChange={(e) => setFilterQuery(e.target.value)}
                  className="w-full h-10 px-4 py-4 text-base rounded-none border-x-0 border-t-0 shadow-sm sm:border-0 sm:shadow-none pr-10 placeholder:text-[#aaa]"
                />
                {filterQuery && (
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setFilterQuery('')}
                    className="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 text-muted-foreground hover:text-foreground"
                    aria-label="Clear filter"
                  >
                    <XIcon className="h-4 w-4" />
                  </Button>
                )}
            </div>
            <div className="flex-shrink-0 sm:w-52 px-4 sm:px-0">
                <Select value={selectedPriest} onValueChange={setSelectedPriest}>
                    <SelectTrigger className="w-full h-10 rounded-none border-x-0 border-t-0 sm:border-0 sm:shadow-none">
                        <SelectValue placeholder="Filter by priest..." />
                    </SelectTrigger>
                    <SelectContent>
                        {priests.map(priest => (
                            <SelectItem key={priest} value={priest}>
                                {priest}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
          </div>
        </div>
      </div>

      <div className="container mx-auto px-0 py-0 min-h-screen">
        <div className="mt-4 px-2 sm:px-4">
          <div className="max-w-4xl mx-auto flex flex-col sm:flex-row sm:justify-end sm:items-center sm:mb-4">
            <div className="mass-count text-right text-sm text-muted-foreground mb-4 sm:mb-0 ml-auto">
              {filterQuery
                ? `${filteredAccordionItems.length} of ${accordionItems.length} Centres found`
                : `${accordionItems.length} Centres`}
            </div>
          </div>

          <GridAccordion 
            items={filteredAccordionItems} 
          />
        </div>
        
        <footer className="text-center mt-12 py-6 border-t border-border">
          <div className="flex flex-col sm:flex-row justify-center items-center space-y-2 sm:space-y-0 sm:space-x-4">
            <p className="text-xs text-muted-foreground">
              &copy; {new Date().getFullYear()} Activity Scheduler <br className="sm:hidden"/> <a href="mailto:patxiworks@gmail.com" className="text-[10px]">by Telluris</a>.
            </p>
          </div>
        </footer>
      </div>
    </>
  );
}

    