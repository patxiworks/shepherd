
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import type { AccordionItemData } from '@/types';

const REMOTE_COLLECTIONS_URL = process.env.REMOTE_COLLECTIONS_URL || 'https://criterionpublishers.org/sj-masses/photos2025/collections.json';
const REMOTE_COLLECTIONS_WRITE_URL = process.env.REMOTE_COLLECTIONS_WRITE_URL;
const COLLECTIONS_API_SECRET_KEY = process.env.SECRET_KEY;

async function readRemoteCollections(): Promise<AccordionItemData[]> {
  try {
    const response = await fetch(REMOTE_COLLECTIONS_URL, { cache: 'no-store' });
    if (!response.ok) {
      if (response.status === 404) {
        console.warn(`Remote collections file not found at ${REMOTE_COLLECTIONS_URL}. Returning empty array.`);
        return []; // If not found, assume it's empty and can be created on first write
      }
      throw new Error(`Failed to fetch remote collections: ${response.statusText}`);
    }
    const data = await response.json();
    return data as AccordionItemData[];
  } catch (error) {
    console.error('Error reading remote collections data:', error);
    // If any error occurs (network, parsing, etc.), return empty array or rethrow
    // For robustness, if the file is expected to exist, rethrowing might be better
    // For now, returning empty to allow app to function and potentially create on write
    return [];
  }
}

async function writeRemoteCollections(data: AccordionItemData[]): Promise<void> {
  if (!REMOTE_COLLECTIONS_WRITE_URL) {
    throw new Error('Remote write URL is not configured. Cannot save collections.');
  }
  if (!COLLECTIONS_API_SECRET_KEY) {
    throw new Error('Collections API secret key is not configured. Cannot save collections.');
  }

  try {
    const response = await fetch(REMOTE_COLLECTIONS_WRITE_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Secret': COLLECTIONS_API_SECRET_KEY,
      },
      body: JSON.stringify(data, null, 2), // Send the complete array as JSON
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Failed to write remote collections: ${response.statusText} - ${errorText}`);
    }
    // Assuming the PHP script returns a success status or relevant JSON
    const result = await response.json();
    if (result.status !== 'success') { // Adjust based on your PHP script's actual response
        console.warn('Remote write operation reported an issue:', result);
    }

  } catch (error) {
    console.error('Error writing remote collections data:', error);
    throw new Error('Failed to write remote collections data.');
  }
}

export async function GET() {
  try {
    const collections = await readRemoteCollections();
    return NextResponse.json(collections);
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch collections' }, { status: 500 });
  }
}

export async function POST(request: NextRequest) {
  if (!REMOTE_COLLECTIONS_WRITE_URL || !COLLECTIONS_API_SECRET_KEY) {
    return NextResponse.json({ message: 'Remote write functionality is not configured on the server.' }, { status: 503 });
  }
  try {
    const newCollection: AccordionItemData = await request.json();
    if (!newCollection.id || !newCollection.parishLocation || !newCollection.state) {
      return NextResponse.json({ message: 'Invalid collection data' }, { status: 400 });
    }

    // Ensure images are not blobs before saving
    newCollection.images = newCollection.images.filter(img => typeof img.src === 'string' && !img.src.startsWith('blob:'));

    const collections = await readRemoteCollections();
    collections.push(newCollection);
    await writeRemoteCollections(collections);
    return NextResponse.json(newCollection, { status: 201 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to create collection' }, { status: 500 });
  }
}
