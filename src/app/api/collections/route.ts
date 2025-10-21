
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { ApiActivity } from '@/types';
import fs from 'fs/promises';
import path from 'path';

// The new data source URL for activities
const REMOTE_ACTIVITIES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLheQk1_23hqYpSg6Y9gi8tIjc0le_BFsuXCcBc6yKcu40FjuKD33rR_PWqG75FmZMu4U6DSE6WJlH__EncOtRlNPfvayzxtAkCswyU0biDIqTuUt5f--0BUtNDl7bHW0YqVDI5-XZw25pm1B3DyZ5qCANYBdjIzptvR_YX0yHa1bV2CoSBtmEyb1ZEi2r2yzGoElHGyNmpDN9B2RlSz1g4CdG2QzihqlDU0u8mMxnVD8Bx589PdM49-8Bso4b_PdnzgIyvKJb-cF4fnIm4lGjetDv4ipc16dIFlc8u_&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';

// Path to the new local masses data
const MASSES_FILE_PATH = path.join(process.cwd(), 'src', 'data', 'masses.json');


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

async function readLocalMasses(): Promise<Record<string, { Mass: string }>> {
  try {
    const data = await fs.readFile(MASSES_FILE_PATH, 'utf-8');
    return JSON.parse(data);
  } catch (error) {
    console.error('Error reading masses.json:', error);
    return {};
  }
}

export async function GET() {
  try {
    const [activities, masses] = await Promise.all([
      readRemoteActivities(),
      readLocalMasses()
    ]);
    
    return NextResponse.json({ activities, masses });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch data' }, { status: 500 });
  }
}

// POST, PUT, DELETE are no longer supported with this read-only data source.
export async function POST(request: NextRequest) {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}
