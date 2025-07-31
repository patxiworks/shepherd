
"use client";

import * as React from 'react';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import type { AccordionGroupData, ApiActivity, GroupItem } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Loader2, XIcon } from 'lucide-react';
import { parseISO, format as formatDate } from 'date-fns';
import { format as formatDateFnsTz, toZonedTime } from 'date-fns-tz';
import { getSectionColor, getLaborColor } from '@/lib/section-colors';

const sortAccordionGroups = (a: AccordionGroupData, b: AccordionGroupData, groupBy: 'centre' | 'activity' | 'date'): number => {
    if (groupBy === 'date') {
        // Assuming id is an ISO date string for sorting
        return new Date(a.id).getTime() - new Date(b.id).getTime();
    }
    if (!a.id || !b.id) return 0;
    return a.id.localeCompare(b.id);
};

export default function HomePage() {
  const [allActivities, setAllActivities] = React.useState<ApiActivity[]>([]);
  const [accordionItems, setAccordionItems] = React.useState<AccordionGroupData[]>([]);
  const [isLoading, setIsLoading] = React.useState(true);
  const [filterQuery, setFilterQuery] = React.useState('');
  const [selectedPriest, setSelectedPriest] = React.useState('All Priests');
  const [selectedSection, setSelectedSection] = React.useState('All Sections');
  const [selectedLabor, setSelectedLabor] = React.useState('All Labor');
  const [groupBy, setGroupBy] = React.useState<'date' | 'centre' | 'activity'>('date');
  const [defaultValue, setDefaultValue] = React.useState<string | undefined>(undefined);
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
        setAllActivities(data);
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

  React.useEffect(() => {
    if (isLoading || !allActivities) return;

    let groupsMap = new Map<string, AccordionGroupData>();

    const createGroupItem = (activity: ApiActivity): GroupItem => {
        const timeZone = 'UTC'; // Assuming times from sheet are UTC or should be treated as such
  
        const fromTime = activity.from && activity.from.includes('T')
          ? formatDateFnsTz(toZonedTime(parseISO(activity.from), timeZone), "h:mm a", { timeZone })
          : activity.from || 'N/A';
        
        const toTime = activity.to && activity.to.includes('T')
          ? formatDateFnsTz(toZonedTime(parseISO(activity.to), timeZone), "h:mm a", { timeZone })
          : activity.to || 'N/A';
        
        return {
          title: activity.activity,
          centre: activity.centre,
          date: activity.date ? formatDateFnsTz(toZonedTime(parseISO(activity.date), 'UTC'), "EEE, MMM d") : "N/A",
          priest: activity.priest,
          time: `${fromTime} - ${toTime}`,
          section: activity.section || 'default',
          labor: activity.labor || 'default',
        };
      };

    let filteredActivities = allActivities;

    if (selectedPriest && selectedPriest !== 'All Priests') {
        filteredActivities = filteredActivities.filter(activity => activity.priest === selectedPriest);
    }
    if (selectedSection && selectedSection !== 'All Sections') {
        filteredActivities = filteredActivities.filter(activity => activity.section === selectedSection);
    }
    if (selectedLabor && selectedLabor !== 'All Labor') {
        filteredActivities = filteredActivities.filter(activity => activity.labor === selectedLabor);
    }


    if (groupBy === 'centre') {
        filteredActivities.forEach(activity => {
            const centreName = activity.centre;
            if (centreName) {
                if (!groupsMap.has(centreName)) {
                    groupsMap.set(centreName, {
                        id: centreName,
                        title: centreName,
                        items: [],
                        mainSection: ''
                    });
                }
                groupsMap.get(centreName)!.items.push(createGroupItem(activity));
            }
        });
    } else if (groupBy === 'activity') {
        filteredActivities.forEach(activity => {
            const activityName = activity.activity;
            if (activityName) {
                if (!groupsMap.has(activityName)) {
                    groupsMap.set(activityName, {
                        id: activityName,
                        title: activityName,
                        items: [],
                        mainSection: ''
                    });
                }
                groupsMap.get(activityName)!.items.push(createGroupItem(activity));
            }
        });
    } else { // Group by date
        const todayKey = formatDate(new Date(), "yyyy-MM-dd");
        filteredActivities.forEach(activity => {
            if (activity.date) {
                const activityDate = toZonedTime(parseISO(activity.date), 'UTC');
                const dateKey = formatDate(activityDate, "yyyy-MM-dd"); 
                const formattedDate = formatDate(activityDate, "EEEE, MMMM d, yyyy");

                if (!groupsMap.has(dateKey)) {
                    groupsMap.set(dateKey, {
                        id: dateKey,
                        title: formattedDate,
                        items: [],
                        mainSection: ''
                    });
                }
                groupsMap.get(dateKey)!.items.push(createGroupItem(activity));

                if (dateKey === todayKey && !defaultValue) {
                    setDefaultValue(dateKey);
                }
            }
        });
    }

    // Determine the main section for each group (for coloring accordion header)
    groupsMap.forEach((group) => {
        if (group.items.length > 0) {
            const sectionCounts = group.items.reduce((acc, item) => {
                acc[item.section] = (acc[item.section] || 0) + 1;
                return acc;
            }, {} as Record<string, number>);
            
            const mainSection = Object.keys(sectionCounts).reduce((a, b) => sectionCounts[a] > sectionCounts[b] ? a : b);
            group.mainSection = mainSection;
        }
    });

    
    const sortedGroups = Array.from(groupsMap.values()).sort((a, b) => sortAccordionGroups(a, b, groupBy));
    setAccordionItems(sortedGroups);

  }, [allActivities, groupBy, isLoading, selectedPriest, selectedSection, selectedLabor, defaultValue]);
  
  const priests = React.useMemo(() => {
    if (!allActivities) return ["All Priests"];
    const priestSet = new Set<string>();
    allActivities.forEach(activity => {
        if (activity.priest) {
            priestSet.add(activity.priest);
        }
    });
    return ["All Priests", ...Array.from(priestSet).sort()];
  }, [allActivities]);

  const sections = React.useMemo(() => {
    if (!allActivities) return ["All Sections"];
    const sectionSet = new Set<string>();
    allActivities.forEach(activity => {
      if (activity.section) {
        sectionSet.add(activity.section);
      }
    });
    return ["All Sections", ...Array.from(sectionSet).sort()];
  }, [allActivities]);

  const labors = React.useMemo(() => {
    if (!allActivities) return ["All Labor"];
    const laborSet = new Set<string>();
    allActivities.forEach(activity => {
      if (activity.labor) {
        laborSet.add(activity.labor);
      }
    });
    return ["All Labor", ...Array.from(laborSet).sort()];
  }, [allActivities]);

  const filteredAccordionItems = React.useMemo(() => {
    if (!filterQuery) {
        return accordionItems;
    }
    
    const lowercasedQuery = filterQuery.toLowerCase();
    return accordionItems
        .map(group => {
            const matchingItems = group.items.filter(item => 
                (item.title && item.title.toLowerCase().includes(lowercasedQuery)) ||
                (item.centre && item.centre.toLowerCase().includes(lowercasedQuery)) ||
                (item.date && item.date.toLowerCase().includes(lowercasedQuery)) ||
                (item.priest && item.priest.toLowerCase().includes(lowercasedQuery))
            );

            // If the group title matches OR any item within the group matches, include it
            if (group.title && group.title.toLowerCase().includes(lowercasedQuery)) {
                return group; // Keep all original items if group title matches
            }
            if (matchingItems.length > 0) {
                return { ...group, items: matchingItems }; // Only return items that match the query
            }
            return null;
        })
        .filter((group): group is AccordionGroupData => group !== null);

  }, [accordionItems, filterQuery]);


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
  
  const getGroupByName = () => {
    switch(groupBy) {
        case 'centre': return 'Centres';
        case 'activity': return 'Activities';
        case 'date': return 'Days';
        default: return 'Items';
    }
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
                  placeholder="Filter by centre, activity, date, or priest..."
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
            
          </div>
        </div>

        <div className="flex sm:flex-row flex-col gap-2 my-2 container mx-auto sm:px-4 sm:border-b-1 sm:shadow-sm">
          <div className="flex flex-row flex-grow gap-2 px-4 sm:px-0">
              <div className="flex flex-grow">
                  <Select value={groupBy} onValueChange={(value) => setGroupBy(value as 'date' | 'centre' | 'activity')}>
                      <SelectTrigger className="w-full h-10 rounded-none border-x-0 border-t-0 sm:border-0 sm:shadow-none">
                          <SelectValue placeholder="Group by..." />
                      </SelectTrigger>
                      <SelectContent>
                          <SelectItem value="date">Group by Date</SelectItem>
                          <SelectItem value="centre">Group by Centre</SelectItem>
                          <SelectItem value="activity">Group by Activity</SelectItem>
                      </SelectContent>
                  </Select>
              </div>
              <div className="flex flex-grow">
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
          <div className="flex flex-row flex-grow gap-2 px-4 sm:px-0">
            <div className="flex flex-grow">
              <Select value={selectedSection} onValueChange={setSelectedSection}>
                  <SelectTrigger className="w-full h-10 rounded-none border-x-0 border-t-0 sm:border-0 sm:shadow-none">
                      <SelectValue placeholder="Filter by section..." />
                  </SelectTrigger>
                  <SelectContent>
                      {sections.map(section => (
                          <SelectItem key={section} value={section}>
                              <div className="flex items-center gap-2">
                              {section !== 'All Sections' && (
                                  <div 
                                  className="h-4 w-4 rounded-full"
                                  style={{ backgroundColor: getSectionColor(section) }}
                                  />
                              )}
                              <span>{section}</span>
                              </div>
                          </SelectItem>
                      ))}
                  </SelectContent>
              </Select>
            </div>
            <div className="flex flex-grow">
              <Select value={selectedLabor} onValueChange={setSelectedLabor}>
                  <SelectTrigger className="w-full h-10 rounded-none border-x-0 border-t-0 sm:border-0 sm:shadow-none">
                      <SelectValue placeholder="Filter by labor..." />
                  </SelectTrigger>
                  <SelectContent>
                      {labors.map(labor => (
                          <SelectItem key={labor} value={labor}>
                              <div className="flex items-center gap-2">
                              {labor !== 'All Labor' && (
                                  <div 
                                  className="h-4 w-4 rounded-full"
                                  style={{ backgroundColor: getLaborColor(labor) }}
                                  />
                              )}
                              <span>{labor}</span>
                              </div>
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
          
          <div className="mass-count text-right text-sm text-muted-foreground mb-4 my-2 ml-auto">
              {filterQuery || selectedPriest !== 'All Priests' || selectedSection !== 'All Sections'
                ? `${filteredAccordionItems.length} of ${accordionItems.length} ${getGroupByName()} found`
                : `${accordionItems.length} ${getGroupByName()}`}
          </div>

          <GridAccordion 
            items={filteredAccordionItems} 
            groupBy={groupBy}
            defaultValue={defaultValue}
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

    