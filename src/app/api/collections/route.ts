
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';
import { DataPartSchema } from 'genkit/model';

// The new data source URL
const REMOTE_ACTIVITIES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLi9A0g6TGYbQiZ1M5arH8zweUPOgP9JqIdzd6o7-btA7o-dd6MvulPpTwUNPd0FO7DuCJVR8d_Na5Cn1PNxdSs93syaK_zx7psdisDtWt_bXTQaYSO2AseZVmbI7sz2v_gj8Yl1TMSAPdHvji1Yu-kCDyx1lV5_7hUDVXMt9QlLQoRNf3IHZPzGe1K4viG47BaPDZ3sBWJj66JVbOm5w_GdN888Lg0-e9o_AslkBlbACEJeWq3mPxYWfuiNHak-RWoXmSZRAgT22JIWwQdKztD65Y5Idw&lib=MpttvUYGzARBHcLHx-Q7aYCekRsELq_92';


async function readRemoteActivities(): Promise<{data: ApiActivity[]}> {
  try {
    const response = await fetch(REMOTE_ACTIVITIES_URL, { cache: 'no-store' });
    if (!response.ok) {
        throw new Error(`Failed to fetch remote activities: ${response.statusText}`);
    }
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error reading remote activities data:', error);
    return { data: [] };
  }
}


export async function GET() {
  try {
    const remoteData = await readRemoteActivities();
    // The API returns an object with a 'data' key which is the array
    //const activities = remoteData.data || [];
    return NextResponse.json(remoteData);
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch activities' }, { status: 500 });
  }
}

// POST, PUT, DELETE are no longer supported with this read-only data source.
// We can remove the POST handler.

export async function POST(request: NextRequest) {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}
