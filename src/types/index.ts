
// The raw data structure from the remote Google Script URL
export interface ApiActivity {
  week: number | null;
  day: string | null;
  daySerial: number | null;
  centre: string | null;
  activity: string | null;
  section: string | null;
  labor: string | null;
  from: string | null; // ISO date string e.g., "1899-12-30T06:45:00.000Z"
  to: string | null;   // ISO date string
  duration: string | null; // ISO date string
  "mfrequency  ": number | null; // Note the trailing spaces in the key
  priest: string | null;
}

// A generic structure for an item within an accordion group
export interface GroupItem {
  title: string | null; // Will be activity name or centre name depending on grouping
  centre: string | null;
  day: string | null;
  time: string;
  priest?: string | null;
}

// The new generic structure for each accordion item (a group)
export interface AccordionGroupData {
  id: string;    // Will use the group title for the ID
  title: string; // Will be centre name or activity name
  items: GroupItem[];
}
