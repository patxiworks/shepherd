
"use client";

import * as React from 'react';
import { useRouter } from 'next/navigation';
import { GridAccordion } from '@/components/grid-accordion/grid-accordion';
import type { AccordionGroupData, ApiActivity, GroupItem, ZoneUser } from '@/types';
import { useToast } from "@/hooks/use-toast";
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogTrigger } from '@/components/ui/dialog';
import { Calendar } from '@/components/ui/calendar';
import { Loader2, XIcon, CalendarIcon, RefreshCw, WifiOff } from 'lucide-react';
import { parse, parseISO, format as formatDate } from 'date-fns';
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
  const [massesData, setMassesData] = React.useState<Record<string, any>>({});
  const [accordionItems, setAccordionItems] = React.useState<AccordionGroupData[]>([]);
  const [filterQuery, setFilterQuery] = React.useState('');
  const [selectedPriest, setSelectedPriest] = React.useState('All Priests');
  const [selectedCentre, setSelectedCentre] = React.useState('All Centres');
  const [selectedSection, setSelectedSection] = React.useState('All Sections');
  const [selectedLabor, setSelectedLabor] = React.useState('All Labor');
  const [groupBy, setGroupBy] = React.useState<'date' | 'centre' | 'activity'>('date');
  const [openAccordionValue, setOpenAccordionValue] = React.useState<string | undefined>(undefined);
  const [visibleDateId, setVisibleDateId] = React.useState<string | null>(null);
  const [isLoading, setIsLoading] = React.useState(true);
  const [userRole, setUserRole] = React.useState<string | undefined>(undefined);
  const [isCalendarOpen, setIsCalendarOpen] = React.useState(false);
  const [showAllDates, setShowAllDates] = React.useState(false);
  const [calendarMonth, setCalendarMonth] = React.useState<Date | undefined>(undefined);
  const [isCheckingForUpdate, setIsCheckingForUpdate] = React.useState(false);
  const [isOnline, setIsOnline] = React.useState(true);
  const { toast, dismiss } = useToast();
  const router = useRouter();
  const initialLoadHandled = React.useRef(false);

  const selectedCalendarDate = React.useMemo(() => {
    return visibleDateId ? parse(visibleDateId, 'yyyy-MM-dd', new Date()) : new Date();
  }, [visibleDateId]);

  React.useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    if (typeof window !== 'undefined' && typeof window.navigator.onLine !== 'undefined') {
        setIsOnline(window.navigator.onLine);
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const fetchFreshData = React.useCallback(async (user: ZoneUser, showLoading: boolean = true) => {
    if (showLoading) setIsLoading(true);
    try {
        const urlParams = new URLSearchParams();
        if (user?.zone) urlParams.append('zone', user.zone);
        if (user?.centre) urlParams.append('centre', user.centre);
        if (user?.section && (user.section === 'sf' || user.section === 'sv')) {
            urlParams.append('section', user.section);
        }
        
        const apiUrl = `/api/collections?${urlParams.toString()}`;
        const response = await fetch(apiUrl);
        if (!response.ok) throw new Error('Failed to fetch activities');
        const data = await response.json();
        
        localStorage.setItem('pastoresData', JSON.stringify(data));
        
        const updateResponse = await fetch(`/api/collections?zone=${user.zone}&action=lastupdate`);
        const updateData = await updateResponse.json();
        if (user && updateData.last_update) {
            const updatedUser = { ...user, last_update: updateData.last_update };
            localStorage.setItem('zoneUser', JSON.stringify(updatedUser));
            setUserRole(updatedUser.role);
        }

        setAllActivities(data.activities || []);
        setMassesData(data.masses || {});
        
        if (showLoading) { // Only show toast if it was a manual update
          toast({ title: "Schedule Updated", description: "You have the latest data." });
        }
        dismiss(); // Dismiss any pending update toasts

    } catch (error) {
        console.error("Error fetching fresh data:", error);
        toast({
            title: "Update Failed",
            description: "Could not fetch new data. Please try again later.",
            variant: "destructive",
        });
    } finally {
        if (showLoading) setIsLoading(false);
    }
  }, [toast, dismiss]);

  const handleCheckForUpdates = React.useCallback(async (manualTrigger = false) => {
    // Direct check to prevent race condition on load
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      if (manualTrigger) {
        toast({ title: "Offline", description: "You are offline. Cannot check for updates.", variant: "destructive" });
      }
      return;
    }
    if (manualTrigger) setIsCheckingForUpdate(true);
    try {
        const userData = localStorage.getItem('zoneUser');
        if (!userData) return;
        const user: ZoneUser = JSON.parse(userData);

        if (!user.zone) return;

        const lastUpdateUrl = `/api/collections?zone=${user.zone}&action=lastupdate`;
        const updateResponse = await fetch(lastUpdateUrl);
        if (!updateResponse.ok) throw new Error('Could not check for updates.');
        
        const updateData = await updateResponse.json();
        const remoteLastUpdate = updateData.last_update ? new Date(updateData.last_update).getTime() : 0;
        const localLastUpdate = user.last_update ? new Date(user.last_update).getTime() : 0;

        if (remoteLastUpdate > localLastUpdate) {
            toast({
                title: "Update Available",
                description: "New schedule information is available.",
                duration: Infinity,
                action: (
                    <Button onClick={() => fetchFreshData(user, true)} disabled={!isOnline}>
                        <RefreshCw className="mr-2 h-4 w-4" />
                        Update Now
                    </Button>
                ),
            });
        } else if (manualTrigger) {
          toast({
              title: "No Updates",
              description: "The schedule is already up to date.",
          });
        }
    } catch (error) {
        console.error("Error checking for updates:", error);
        if (manualTrigger) {
            toast({
                title: "Error",
                description: "Could not check for updates.",
                variant: "destructive",
            });
        }
    } finally {
        if (manualTrigger) setIsCheckingForUpdate(false);
    }
  }, [isOnline, toast, fetchFreshData]);

  React.useEffect(() => {
    if (initialLoadHandled.current) return;
    initialLoadHandled.current = true;

    const userData = localStorage.getItem('zoneUser');
    if (!userData) {
      router.push('/login');
      return;
    }
    
    const user: ZoneUser = JSON.parse(userData);
    setUserRole(user?.role);

    const cachedData = localStorage.getItem('pastoresData');
    if (cachedData) {
      console.log("Using cached data for initial render.");
      const parsedData = JSON.parse(cachedData);
      setAllActivities(parsedData.activities || []);
      setMassesData(parsedData.masses || {});
      setIsLoading(false); 
      handleCheckForUpdates(); // Check for updates in the background
    } else {
      console.log("No cached data found, fetching from server.");
      fetchFreshData(user, true); // Fetch fresh data and show loader
    }

  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  React.useEffect(() => {
    if (isLoading || !Array.isArray(allActivities)) return;

    let groupsMap = new Map<string, AccordionGroupData>();

    const createGroupItem = (activity: ApiActivity): GroupItem => {
      const timeZone = 'UTC'; 

      const fromTime = activity.from && activity.from.includes('T')
        ? formatDateFnsTz(toZonedTime(parseISO(activity.from), timeZone), "h:mm a", { timeZone })
        : activity.from;
      
      const toTime = activity.to && activity.to.includes('T')
        ? formatDateFnsTz(toZonedTime(parseISO(activity.to), timeZone), "h:mm a", { timeZone })
        : activity.to;
      
      let formattedActivityDate = "N/A";
      if (activity.date && typeof activity.date === 'string' && activity.date.trim()) {
        try {
            formattedActivityDate = formatDate(parse(activity.date, 'yyyy-MM-dd', new Date()), 'EEE, MMM d');
        } catch (e) {
            console.warn(`Could not parse date "${activity.date}" for activity:`, activity);
        }
      }

      return {
        title: activity.activity,
        description: activity.description,
        centre: activity.centre,
        date: formattedActivityDate, 
        priest: activity.priest,
        time: fromTime && toTime ? `${fromTime}` : fromTime ? `${fromTime}` : '',
        section: activity.section || 'default',
        labor: activity.labor || 'default',
        sortableDate: activity.date || '',
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
            if (activity.date && typeof activity.date === 'string' && activity.date.trim()) {
              try {
                const activityDate = parse(activity.date, 'yyyy-MM-dd', new Date());
                const dateKey = formatDate(activityDate, 'yyyy-MM-dd'); 
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

                if (dateKey === todayKey && !visibleDateId) {
                    setVisibleDateId(dateKey);
                    setOpenAccordionValue(dateKey); // Also set the controlled value
                }
              } catch(e) {
                console.warn(`Could not process activity due to invalid date "${activity.date}":`, activity);
              }
            }
        });
    }

    groupsMap.forEach((group) => {
      group.items.sort((a, b) => {
        if (!a.sortableDate || !b.sortableDate) return 0;
        return a.sortableDate.localeCompare(b.sortableDate);
      });
    });

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

  }, [allActivities, massesData, groupBy, selectedPriest, selectedSection, selectedLabor, isLoading, visibleDateId]);
  
  const scrollToAccordion = (accordionId: string) => {
    const accordionElement = document.getElementById(`accordion-group-${accordionId}`);
    const headerElement = document.getElementById('filter-header');
    
    if (accordionElement && headerElement) {
        const headerHeight = headerElement.offsetHeight;
        const elementPosition = accordionElement.getBoundingClientRect().top;
        const offsetPosition = elementPosition + window.pageYOffset - headerHeight - 40; // 50px padding to accommodate the calendar icon
      
        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });
    }
  };

  const handleAccordionValueChange = (value: string | undefined) => {
    setOpenAccordionValue(value);
    if (value) {
      setTimeout(() => {
        scrollToAccordion(value);
      }, 500);
    }
  };

  const handleScrollToToday = () => {
    const todayKey = formatDate(new Date(), "yyyy-MM-dd");
    if (accordionItems.some(item => item.id === todayKey)) {
      setShowAllDates(false);
      setVisibleDateId(todayKey);
      setOpenAccordionValue(todayKey);
      setTimeout(() => {
          scrollToAccordion(todayKey);
      }, 50); 
    }
  };

  const handleGoToCentre = (centreName: string) => {
    setSelectedCentre(centreName);
    if (!centreName || centreName === 'All Centres') {
      setFilterQuery('');
      // Optionally reset accordion
      setOpenAccordionValue(undefined);
      return;
    }
    setGroupBy('centre');
    setFilterQuery(centreName);
    setOpenAccordionValue(centreName);
    setTimeout(() => {
      scrollToAccordion(centreName);
    }, 100); 
  };

  const handleCalendarSelect = (date: Date | undefined) => {
    if (!date) return;
    
    const dateKey = formatDate(date, 'yyyy-MM-dd');
    const groupExists = accordionItems.some(item => item.id === dateKey);

    if (groupExists) {
        setGroupBy('date'); // Ensure we are grouping by date
        setShowAllDates(false); // Go back to single date view
        setVisibleDateId(dateKey);
        setOpenAccordionValue(dateKey);
        setTimeout(() => {
            scrollToAccordion(dateKey);
        }, 50);
    } else {
        toast({
            title: "No Activities",
            description: `There are no scheduled activities for ${formatDate(date, 'PPP')}.`,
            variant: "default",
        });
    }
    setIsCalendarOpen(false); // Close the dialog
  };

  React.useEffect(() => {
    const todayKey = formatDate(new Date(), "yyyy-MM-dd");
    if (visibleDateId && !isLoading && accordionItems.length > 0) {
        const hasTodayBeenScrolled = sessionStorage.getItem('scrolledToToday');
        if (!hasTodayBeenScrolled && visibleDateId === todayKey) {
            handleScrollToToday();
            sessionStorage.setItem('scrolledToToday', 'true');
        }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visibleDateId, isLoading, accordionItems]);


  const priests = React.useMemo(() => {
    if (!Array.isArray(allActivities)) return ["All Priests"];
    const priestSet = new Set<string>();
    allActivities.forEach(activity => {
        if (activity.priest) {
            priestSet.add(activity.priest);
        }
    });
    return ["All Priests", ...Array.from(priestSet).sort()];
  }, [allActivities]);

  const centres = React.useMemo(() => {
    if (!Array.isArray(allActivities)) return ["All Centres"];
    
    const relevantActivities = selectedSection === 'All Sections' 
        ? allActivities 
        : allActivities.filter(activity => activity.section === selectedSection);

    const centreSet = new Set<string>();
    relevantActivities.forEach(activity => {
        if (activity.centre) {
            centreSet.add(activity.centre);
        }
    });
    return ["All Centres", ...Array.from(centreSet).sort()];
  }, [allActivities, selectedSection]);


  const sections = React.useMemo(() => {
    if (!Array.isArray(allActivities)) return ["All Sections"];
    const sectionSet = new Set<string>();
    allActivities.forEach(activity => {
      if (activity.section) {
        sectionSet.add(activity.section);
      }
    });
    return ["All Sections", ...Array.from(sectionSet).sort()];
  }, [allActivities]);

  const labors = React.useMemo(() => {
    if (!Array.isArray(allActivities)) return ["All Labor"];
    const laborSet = new Set<string>();
    allActivities.forEach(activity => {
      if (activity.labor) {
        laborSet.add(activity.labor);
      }
    });
    return ["All Labor", ...Array.from(laborSet).sort()];
  }, [allActivities]);

  const filteredAccordionItems = React.useMemo(() => {
    let itemsToDisplay = accordionItems;

    // If we are in single-day view, and not searching, show only that day
    if (groupBy === 'date' && visibleDateId && !showAllDates && !filterQuery) {
        itemsToDisplay = itemsToDisplay.filter(item => item.id === visibleDateId);
    }
    
    // Apply search query if it exists
    if (filterQuery) {
      const lowercasedQuery = filterQuery.toLowerCase();
      
      let sourceItems = accordionItems;
      // If we are in single-day view, filter only within that day's items.
      if (groupBy === 'date' && visibleDateId && !showAllDates) {
          sourceItems = accordionItems.filter(item => item.id === visibleDateId);
      }

      return sourceItems
        .map(group => {
            const matchingItems = group.items.filter(item => 
                (item.title && item.title.toLowerCase().includes(lowercasedQuery)) ||
                (item.centre && item.centre.toLowerCase().includes(lowercasedQuery)) ||
                (item.date && item.date.toLowerCase().includes(lowercasedQuery)) ||
                (item.priest && item.priest.toLowerCase().includes(lowercasedQuery))
            );

            // If the group title itself matches, show all its items
            if (group.title && group.title.toLowerCase().includes(lowercasedQuery) && groupBy !== 'date') {
                return group; 
            }
            
            // If there are matching items within the group, show the group with only those items
            if (matchingItems.length > 0) {
                return { ...group, items: matchingItems }; 
            }
            
            return null;
        })
        .filter((group): group is AccordionGroupData => group !== null);
    }

    return itemsToDisplay;

  }, [accordionItems, filterQuery, groupBy, visibleDateId, showAllDates]);


  const getLoadingComponent = () => (
    <div className="container mx-auto px-4 py-8 min-h-screen flex flex-col items-center justify-center">
        <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
        <p className="text-lg text-muted-foreground">
            Loading Schedule...
        </p>
    </div>
  );

  if (isLoading) {
    return getLoadingComponent();
  }
  
  const getGroupByName = () => {
    switch(groupBy) {
        case 'centre': return 'Centres';
        case 'activity': return 'activities';
        case 'date': return 'days';
        default: return 'items';
    }
  }

  const handleLogout = () => {
    if (typeof window !== 'undefined' && window.localStorage) {
      localStorage.removeItem('zoneUser');
      localStorage.removeItem('pastoresData');
      sessionStorage.removeItem('scrolledToToday');
      dismiss(); // Clear any update toasts on logout
    }
    router.push('/login');
  };
  
  const handleGroupByChange = (value: 'date' | 'centre' | 'activity') => {
    setGroupBy(value);
    // If switching away from date view, clear the single date visibility
    if (value !== 'date') {
      setVisibleDateId(null);
      setShowAllDates(true); // Show all items when not grouped by date
      setOpenAccordionValue(undefined); // Close any open accordion
    } else {
      // If switching back to date, reset to today
      const todayKey = formatDate(new Date(), "yyyy-MM-dd");
      setShowAllDates(false);
      setVisibleDateId(todayKey);
      setOpenAccordionValue(todayKey);
    }
  }

  const handleToggleShowAllDates = () => {
    const newShowAllDates = !showAllDates;
    setShowAllDates(newShowAllDates);
    // If we are switching to single view (showAllDates will be false)
    if (!newShowAllDates) {
      if(visibleDateId) {
        setOpenAccordionValue(visibleDateId); // Open the currently visible single date
      }
    } else {
      // If we are switching to All view, scroll to the currently open item if there is one
      if (openAccordionValue) {
        setTimeout(() => {
          scrollToAccordion(openAccordionValue);
        }, 50);
      }
    }
  };

  return (
    <div>
        <div id="filter-header" className="sticky top-0 z-50 bg-[#ececec] shadow-md border-b border-[#bbb] bg-primary">
          <div className="border-b-0 border-t-0 border-header bg-header">
            <div className="logo relative mx-auto container pt-4 pb-2 px-4 text-left bg-header">
              <div className="flex flex-row items-center justify-between">
                <div>
                  <h1 className="w-[210px] sm:w-full leading-none text-[30px] sm:text-[33px] font-bold text-[#fff]">
                    Pastores
                  </h1>
                  <div className="sub-header mt-0 w-full text-[9px] sm:text-xs text-[#ccc]">Schedule for Pastoral Attention</div>
                </div>
                <div className="flex flex-grow justify-end items-center gap-2">
                  {!isOnline && (
                      <div className="flex items-center gap-1 bg-destructive text-destructive-foreground px-2 py-1 rounded-md">
                          <WifiOff className="h-4 w-4" />
                          <span className="text-xs font-semibold"></span>
                      </div>
                  )}
                  {userRole !== 'ctr' && (
                  <Select value={selectedCentre} onValueChange={handleGoToCentre}>
                    <SelectTrigger className="sub-header w-auto p-0 text-xs text-white bg-transparent border-none sm:shadow-none focus:outline-none focus:ring-0 focus:ring-offset-0">
                        <SelectValue placeholder="Go to centre..." />
                    </SelectTrigger>
                    <SelectContent>
                        {centres.map(centre => (
                            <SelectItem key={centre} value={centre}>
                                {centre}
                            </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                  )}
                </div>
              </div>
            </div>
          </div>
          <div className="container mx-auto mb-1 sm:px-4 sm:py-3 sm:border-b-1 border-[#000] sm:shadow-sm">
            <div className="flex flex-col sm:flex-row gap-2">
              <div className="relative flex-grow flex justify-center items-center">
                  <Input
                    type="text"
                    placeholder="Filter by centre, activity, date, or priest..."
                    value={filterQuery}
                    onChange={(e) => setFilterQuery(e.target.value)}
                    className="w-full h-10 px-4 py-4 text-xs rounded-none border-x-0 border-t-0 shadow-sm sm:border-0 sm:shadow-none pr-10 placeholder:text-primary/80"
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
            <div className="flex flex-row flex-grow gap-2 px-2 sm:px-0">
              <div className="flex flex-grow">
                <Select value={groupBy} onValueChange={(value) => handleGroupByChange(value as 'date' | 'centre' | 'activity')}>
                    <SelectTrigger className="w-full h-10 rounded-lg text-xs sm:shadow-none bg-secondary border-t-1 border-primary/20">
                        <SelectValue placeholder="Group by..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="date">Group by Date</SelectItem>
                        {userRole !== 'ctr' && (
                          <SelectItem value="centre">Group by Centre</SelectItem>
                        )}
                        <SelectItem value="activity">Group by Activity</SelectItem>
                    </SelectContent>
                </Select>
              </div>
              <div className="flex flex-grow">
                <Select value={selectedPriest} onValueChange={setSelectedPriest}>
                  <SelectTrigger className="w-full h-10 rounded-lg text-xs  sm:shadow-none bg-secondary border-t-1 border-primary/20">
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
            {userRole === 'admin' && (
              <div className="flex flex-row flex-grow gap-2 px-2 sm:px-0">
                <div className="flex flex-grow">
                  <Select value={selectedSection} onValueChange={setSelectedSection}>
                    <SelectTrigger className="w-full h-10 rounded-lg text-xs sm:shadow-none bg-secondary border-t-1 border-primary/20">
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
                      <SelectTrigger className="w-full h-10 rounded-lg text-xs sm:shadow-none bg-secondary border-t-1 border-primary/20">
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
            )}
          </div>
        </div>

        <div className="container mx-auto px-0 py-0 min-h-screen">
          <div className="px-2 sm:px-4">
            <div className="flex justify-between items-center mb-0 mt-2 pl-2 sm:px-4">
              <div>
                {groupBy === 'date' && (
                  <div className="flex items-center gap-2">
                      <button
                          onClick={handleScrollToToday}
                          className="text-sm font-semibold text-primary hover:text-primary/80 focus:outline-0 focus:ring-0"
                      >
                          Today
                      </button>
                      <span className="text-sm text-muted-foreground">|</span>
                      <button
                          onClick={handleToggleShowAllDates}
                          className="text-sm font-semibold text-primary hover:text-primary/80 focus:outline-0 focus:ring-0"
                      >
                          {showAllDates ? 'Single day' : 'All days'}
                      </button>
                  </div>
                )}
              </div>
              <div className="mass-count text-right text-sm text-muted-foreground">
                {groupBy === 'date' && (
                  <Dialog open={isCalendarOpen} onOpenChange={(open) => {
                      setIsCalendarOpen(open);
                      if(open) {
                          setCalendarMonth(selectedCalendarDate);
                      }
                  }}>
                    <DialogTrigger asChild>
                      <Button variant="ghost" size="icon" className="text-primary hover:text-primary/80 hover:bg-accent/20">
                        <CalendarIcon className="h-5 w-5" />
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="w-auto sm:max-w-md rounded-xl">
                      <DialogHeader>
                        <DialogTitle>Jump to Date</DialogTitle>
                        <DialogDescription>
                          Select a date to view its schedule.
                        </DialogDescription>
                      </DialogHeader>
                      <Calendar
                        mode="single"
                        selected={selectedCalendarDate}
                        onSelect={handleCalendarSelect}
                        month={calendarMonth}
                        onMonthChange={setCalendarMonth}
                        className="rounded-md border"
                        modifiers={{ today: new Date() }}
                        modifiersClassNames={{
                          today: 'border border-accent rounded-full'
                        }}
                      />
                    </DialogContent>
                  </Dialog>
                )}
              </div>
            </div>
              <GridAccordion 
                  items={filteredAccordionItems}
                  masses={massesData}
                  groupBy={groupBy}
                  value={openAccordionValue}
                  onValueChange={handleAccordionValueChange}
                  userRole={userRole}
              />
          </div>
          
          <footer className="text-center mt-12 py-6 border-t border-border">
            <div className="flex flex-col sm:flex-row justify-center items-center space-y-2 sm:space-y-0 sm:space-x-4">
              <p className="text-xs text-muted-foreground">
                &copy; {new Date().getFullYear()} Pastores <br className="sm:hidden"/> <a href="mailto:patxiworks@gmail.com" className="text-[10px]">Schedule for pastoral attention of Centres</a>
              </p>
              <div className="flex items-center">
                <button onClick={handleLogout} className="text-xs text-destructive hover:underline">Logout</button>
                <span className="text-xs mx-2">|</span>
                <button onClick={() => handleCheckForUpdates(true)} className="text-xs text-primary hover:underline flex items-center" disabled={isCheckingForUpdate || !isOnline}>
                    {isCheckingForUpdate ? (
                        <>
                            <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                            Checking...
                        </>
                    ) : (
                        "Update"
                    )}
                </button>
                <span className="text-xs mx-2">|</span>
                <a href="https://wa.me/2348137243046" target="_blank" rel="noopener noreferrer" className="text-xs">Get help</a>
              </div>
            </div>
          </footer>
        </div>
      </div>
  );
}
