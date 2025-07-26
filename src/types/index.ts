

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

// A cleaned-up version for display within a centre's activity list
export interface Activity {
  activity: string;
  day: string;
  time: string; // Formatted time string e.g., "6:45 AM - 8:00 AM"
  priest?: string;
}

// The new structure for each accordion item, representing a "centre"
export interface CentreData {
  id: string; // Will use the centre name for the ID
  centre: string;
  activities: Activity[];
}
