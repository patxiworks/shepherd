
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';
import { DataPartSchema } from 'genkit/model';

// The new data source URL
const REMOTE_ACTIVITIES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLiYpxWU-eORBaf1eCCwidHXXQGWfM1E2tzlvU8e82A93N_Km29FFs4KiqCSGUtn64eBMxZ89qooEPP5EXDgrkTSwekWEkHwNgRwKqP-MwQSYaoM7M0C51hlni3hnYHeoH25lqQQqFaYbwgJ7qYNxPhfYM76E50PrHAqjmnpwZnbQOKhaRsdYvSuQh4mu_vX2W0gjw1yuMKd-Mqp0g4eqpLGlriqs329bEqiHbNsL-sRytXLRdKKj9q759PSK0_r_pFFNFzXB3sIAuC8LgjnoQ-pyEH0us3RujlFmsR5&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';


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
