
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { AccordionItemData } from '@/types';

const REMOTE_COLLECTIONS_URL = process.env.REMOTE_COLLECTIONS_URL || 'https://criterionpublishers.ng/sj-masses/photos2025/collections.json';
// IMPORTANT: This is the URL to your NEW PHP script on criterionpublishers.ng that will handle saving the collections.json file.
const REMOTE_COLLECTIONS_WRITE_URL = process.env.REMOTE_COLLECTIONS_WRITE_URL;
const COLLECTIONS_API_SECRET_KEY = process.env.COLLECTIONS_API_SECRET_KEY;

async function readCollections(): Promise<AccordionItemData[]> {
  try {
    if (!REMOTE_COLLECTIONS_URL) {
      console.error('REMOTE_COLLECTIONS_URL is not configured.');
      throw new Error('Remote collections URL is not configured.');
    }
    const response = await fetch(REMOTE_COLLECTIONS_URL, { cache: 'no-store' }); // Ensure fresh data
    
    if (!response.ok) {
      if (response.status === 404) {
        console.warn(`Remote collections file not found at ${REMOTE_COLLECTIONS_URL}. Returning empty array.`);
        return []; // File not found, treat as empty
      }
      throw new Error(`Failed to fetch remote collections. Status: ${response.status}`);
    }
    
    const jsonData = await response.json();
    return jsonData as AccordionItemData[];
  } catch (error) {
    if (error instanceof Error && (error.message.includes('fetch') || error.message.toLowerCase().includes('json') || (error as any)?.status === 404)) {
      console.warn('Remote collections.json not found or invalid, returning empty array. Please ensure it exists at the URL and is valid JSON.', error);
      return []; 
    }
    console.error('Error reading remote collections data:', error);
    throw new Error('Failed to read remote collections data.');
  }
}

async function writeCollections(data: AccordionItemData[]): Promise<void> {
  if (!REMOTE_COLLECTIONS_WRITE_URL) {
    console.error('CRITICAL: REMOTE_COLLECTIONS_WRITE_URL is not configured. Cannot save changes to remote collections.');
    throw new Error('Remote collections write URL (REMOTE_COLLECTIONS_WRITE_URL) is not configured. Cannot save changes.');
  }
  if (!COLLECTIONS_API_SECRET_KEY) {
    console.error('CRITICAL: COLLECTIONS_API_SECRET_KEY is not configured. Cannot authenticate with remote write endpoint.');
    throw new Error('Collections API secret key (COLLECTIONS_API_SECRET_KEY) is not configured. Cannot save changes.');
  }

  try {
    const response = await fetch(REMOTE_COLLECTIONS_WRITE_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Secret': COLLECTIONS_API_SECRET_KEY,
      },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error(`Failed to write remote collections. Status: ${response.status}. Response: ${errorText}`);
      throw new Error(`Failed to write remote collections. Status: ${response.status}.`);
    }
    // Assuming PHP script returns JSON like {"status": "success"} or {"status": "error", "message": "..."}
    const result = await response.json();
    if (result.status !== 'success') {
        throw new Error(result.message || 'PHP script reported an error during save.');
    }
  } catch (error) {
    console.error('Error writing remote collections data:', error);
    throw new Error((error as Error).message || 'Failed to write remote collections data.');
  }
}

export async function GET() {
  try {
    const collections = await readCollections();
    return NextResponse.json(collections);
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch collections' }, { status: 500 });
  }
}

export async function POST(request: NextRequest) {
  try {
    const newCollection: AccordionItemData = await request.json();
    if (!newCollection.id || !newCollection.parishLocation || !newCollection.state) {
      return NextResponse.json({ message: 'Invalid collection data' }, { status: 400 });
    }

    newCollection.images = newCollection.images.filter(img => typeof img.src === 'string' && !img.src.startsWith('blob:'));

    const collections = await readCollections();
    collections.push(newCollection);
    await writeCollections(collections);
    return NextResponse.json(newCollection, { status: 201 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to create collection' }, { status: 500 });
  }
}
