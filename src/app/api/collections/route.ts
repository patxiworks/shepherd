
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';
import fs from 'fs/promises';
import path from 'path';

// The data source URL for activities
const REMOTE_ACTIVITIES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLheQk1_23hqYpSg6Y9gi8tIjc0le_BFsuXCcBc6yKcu40FjuKD33rR_PWqG75FmZMu4U6DSE6WJlH__EncOtRlNPfvayzxtAkCswyU0biDIqTuUt5f--0BUtNDl7bHW0YqVDI5-XZw25pm1B3DyZ5qCANYBdjIzptvR_YX0yHa1bV2CoSBtmEyb1ZEi2r2yzGoElHGyNmpDN9B2RlSz1g4CdG2QzihqlDU0u8mMxnVD8Bx589PdM49-8Bso4b_PdnzgIyvKJb-cF4fnIm4lGjetDv4ipc16dIFlc8u_&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';

// The new data source URL for masses
const REMOTE_MASSES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLiHOCjJ0S2XNOHXVk3AEesA4qe5dMyuZTqCK9wtU-_MRXFZj6000SRLROk0fd9R4DImOeusBE4_pb1i4iRUr8b6ow2cSMAGRk2KNWQZ_uKAhtVq6Jt3wU3GYMSAGCBHvzahEsYHKhlJXaSITrCVq4RAWWanNLDLnGiTt-eJcUzM7qgZWI9WiOtkFN2zYnTvvdy7PI78fW7k4-noDdwTuiWf-sXHO81SpLA6ty-pTpMcjjr7WBDzmO4j8tZRcHPiT4rKyUHpugSodFl_hiFgjxbmLGP8zvCcVyDMI1ogir8Iz-rHt8c&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';


async function readRemoteActivities(): Promise<ApiActivity[]> {
  try {
    const response = await fetch(REMOTE_ACTIVITIES_URL, { cache: 'no-store' });
    if (!response.ok) {
        throw new Error(`Failed to fetch remote activities: ${response.statusText}`);
    }
    const data = await response.json();
    // The API returns an object with a 'data' key which is the array
    return data.data || [];
  } catch (error) {
    console.error('Error reading remote activities data:', error);
    return [];
  }
}

async function readRemoteMasses(): Promise<Record<string, { Mass: string }>> {
  try {
    const response = await fetch(REMOTE_MASSES_URL, { cache: 'no-store' });
     if (!response.ok) {
        throw new Error(`Failed to fetch remote masses: ${response.statusText}`);
    }
    const data = await response.json();
    return data || {};
  } catch (error) {
    console.error('Error reading remote masses data:', error);
    return {};
  }
}

export async function GET() {
  try {
    const [activitiesResponse, massesResponse] = await Promise.all([
      readRemoteActivities(),
      readRemoteMasses()
    ]);
    
    // The activities data is nested under a 'data' key, the masses data is not.
    const activities = activitiesResponse; 
    const masses = massesResponse;

    return NextResponse.json({ activities, masses });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch data' }, { status: 500 });
  }
}

// POST, PUT, DELETE are no longer supported with this read-only data source.
export async function POST(request: NextRequest) {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}
