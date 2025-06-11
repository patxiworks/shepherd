
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
        return [];
      }
      throw new Error(`Failed to fetch remote collections: ${response.statusText}`);
    }
    const data = await response.json();
    return data as AccordionItemData[];
  } catch (error) {
    console.error('Error reading remote collections data:', error);
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
      body: JSON.stringify(data, null, 2),
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Failed to write remote collections: ${response.statusText} - ${errorText}`);
    }
    const result = await response.json();
    if (result.status !== 'success') { // Adjust based on your PHP script's actual response
        console.warn('Remote write operation reported an issue:', result);
    }
  } catch (error) {
    console.error('Error writing remote collections data:', error);
    throw new Error('Failed to write remote collections data.');
  }
}

export async function GET(request: NextRequest, { params }: { params: { id: string } }) {
  try {
    const collections = await readRemoteCollections();
    const collection = collections.find(item => item.id === params.id);
    if (collection) {
      return NextResponse.json(collection);
    }
    return NextResponse.json({ message: 'Collection not found' }, { status: 404 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to fetch collection' }, { status: 500 });
  }
}

export async function PUT(request: NextRequest, { params }: { params: { id: string } }) {
  if (!REMOTE_COLLECTIONS_WRITE_URL || !COLLECTIONS_API_SECRET_KEY) {
    return NextResponse.json({ message: 'Remote write functionality is not configured on the server.' }, { status: 503 });
  }
  try {
    const updatedCollectionData: Partial<AccordionItemData> = await request.json();
    
    let collections = await readRemoteCollections();
    const collectionIndex = collections.findIndex(item => item.id === params.id);

    if (collectionIndex === -1) {
      return NextResponse.json({ message: 'Collection not found' }, { status: 404 });
    }

    // Ensure images being persisted are not blob URLs
    const imagesToPersist = (updatedCollectionData.images || collections[collectionIndex].images || [])
                            .filter(img => typeof img.src === 'string' && !img.src.startsWith('blob:'));

    collections[collectionIndex] = { 
      ...collections[collectionIndex], 
      ...updatedCollectionData,
      images: imagesToPersist 
    };
    
    await writeRemoteCollections(collections);
    return NextResponse.json(collections[collectionIndex]);
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to update collection' }, { status: 500 });
  }
}

export async function DELETE(request: NextRequest, { params }: { params: { id: string } }) {
  if (!REMOTE_COLLECTIONS_WRITE_URL || !COLLECTIONS_API_SECRET_KEY) {
    return NextResponse.json({ message: 'Remote write functionality is not configured on the server.' }, { status: 503 });
  }
  try {
    let collections = await readRemoteCollections();
    const initialLength = collections.length;
    collections = collections.filter(item => item.id !== params.id);

    if (collections.length === initialLength) {
      return NextResponse.json({ message: 'Collection not found' }, { status: 404 });
    }

    await writeRemoteCollections(collections);
    return NextResponse.json({ message: 'Collection deleted successfully' }, { status: 200 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to delete collection' }, { status: 500 });
  }
}
