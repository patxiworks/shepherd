
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';
import { DataPartSchema } from 'genkit/model';

// The new data source URL
const REMOTE_ACTIVITIES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLheQk1_23hqYpSg6Y9gi8tIjc0le_BFsuXCcBc6yKcu40FjuKD33rR_PWqG75FmZMu4U6DSE6WJlH__EncOtRlNPfvayzxtAkCswyU0biDIqTuUt5f--0BUtNDl7bHW0YqVDI5-XZw25pm1B3DyZ5qCANYBdjIzptvR_YX0yHa1bV2CoSBtmEyb1ZEi2r2yzGoElHGyNmpDN9B2RlSz1g4CdG2QzihqlDU0u8mMxnVD8Bx589PdM49-8Bso4b_PdnzgIyvKJb-cF4fnIm4lGjetDv4ipc16dIFlc8u_&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';


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
