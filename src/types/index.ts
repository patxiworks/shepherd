

// The raw data structure from the remote Google Script URL
export interface ApiActivity {
  unit?: string | null;
  week: number | null;
  day: string | null;
  weekday?: number | null;
  date: string | null; // ISO date string e.g., "2025-07-26T23:00:00.000Z"
  centre: string | null;
  activity: string | null;
  section: string | null;
  labor: string | null;
  from: string | null; // ISO date string e.g., "1899-12-30T06:45:00.000Z"
  to: string | null;   // ISO date string
  duration: string | null; // ISO date string
  mfrequency: number | null;
  priest: string | null;
}

// A generic structure for an item within an accordion group
export interface GroupItem {
  title: string | null; // Will be activity name or centre name depending on grouping
  centre: string | null;
  date: string | null;
  time: string;
  priest?: string | null;
  section: string;
  labor: string;
  sortableDate: string; // The original, sortable date string
}

// The new generic structure for each accordion item (a group)
export interface AccordionGroupData {
  id: string;    // Will use the group title for the ID
  title: string; // Will be centre name, activity name, or date
  items: GroupItem[];
  mainSection: string; // The dominant section for coloring the accordion header
}
