
// The raw data structure from the remote Google Script URL
export interface ApiActivity {
  week: number;
  day: string;
  daySerial: number;
  centre: string;
  activity: string;
  section: string;
  labor: string;
  from: string; // ISO date string e.g., "1899-12-30T06:45:00.000Z"
  to: string;   // ISO date string
  duration: string; // ISO date string
  "mfrequency  ": number; // Note the trailing spaces in the key
  priest: string;
}

// A generic structure for an item within an accordion group
export interface GroupItem {
  title: string; // Will be activity name or centre name depending on grouping
  day: string;
  time: string;
  priest?: string;
}

// The new generic structure for each accordion item (a group)
export interface AccordionGroupData {
  id: string;    // Will use the group title for the ID
  title: string; // Will be centre name or activity name
  items: GroupItem[];
}
