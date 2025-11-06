
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';

let REMOTE_ACTIVITIES_URL = 'https://script.google.com/macros/s/AKfycbwUCnrFYXswKFBRIN-cQHIApyLwbLjDxjfPeOHEoPlani7vuQRu_Z7mou8GhrAjdKLMvw/exec';
const REMOTE_MASSES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLiHOCjJ0S2XNOHXVk3AEesA4qe5dMyuZTqCK9wtU-_MRXFZj6000SRLROk0fd9R4DImOeusBE4_pb1i4iRUr8b6ow2cSMAGRk2KNWQZ_uKAhtVq6Jt3wU3GYMSAGCBHvzahEsYHKhlJXaSITrCVq4RAWWanNLDLnGiTt-eJcUzM7qgZWI9WiOtkFN2zYnTvvdy7PI78fW7k4-noDdwTuiWf-sXHO81SpLA6ty-pTpMcjjr7WBDzmO4j8tZRcHPiT4rKyUHpugSodFl_hiFgjxbmLGP8zvCcVyDMI1ogir8Iz-rHt8c&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';

async function readRemoteActivities(zone?: string | null, section?: string | null): Promise<ApiActivity[]> {
  try {
    const url = new URL(REMOTE_ACTIVITIES_URL);
    if (zone) {
      url.searchParams.append('zone', zone);
    }
    if (section) {
      url.searchParams.append('section', section);
    }
    const response = await fetch(url.toString(), { cache: 'no-store' });
    if (!response.ok) {
        throw new Error(`Failed to fetch remote activities: ${response.statusText}`);
    }
    const data = await response.json();
    // The script returns an object with a "data" property which is the array.
    return data || [];
  } catch (error) {
    console.error('Error reading remote activities data:', error);
    return [];
  }
}

async function readRemoteMasses(): Promise<Record<string, any>> {
  try {
    const response = await fetch(REMOTE_MASSES_URL, { cache: 'no-store' });
    if (!response.ok) {
        throw new Error(`Failed to fetch remote masses: ${response.statusText}`);
    }
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error reading remote masses data:', error);
    return {};
  }
}


export async function GET(request: NextRequest) {
  try {
    const searchParams = request.nextUrl.searchParams;
    const zone = searchParams.get('zone');
    const section = searchParams.get('section');
    
    const [activitiesResult, massesResult] = await Promise.all([
      readRemoteActivities(zone, section),
      readRemoteMasses(),
    ]);
    
    return NextResponse.json({
      activities: activitiesResult || [],
      masses: massesResult || {},
    });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : 'Failed to fetch data';
    return NextResponse.json({ message: errorMessage }, { status: 500 });
  }
}

export async function POST(request: NextRequest) {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}

    