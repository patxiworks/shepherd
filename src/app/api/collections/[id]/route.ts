
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import fs from 'fs/promises';
import path from 'path';
import type { AccordionItemData } from '@/types';

const dataFilePath = path.join(process.cwd(), 'src', 'data', 'collections.json');

async function readCollections(): Promise<AccordionItemData[]> {
  try {
    const jsonData = await fs.readFile(dataFilePath, 'utf-8');
    return JSON.parse(jsonData) as AccordionItemData[];
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return [];
    }
    console.error('Error reading collections data:', error);
    throw new Error('Failed to read collections data.');
  }
}

async function writeCollections(data: AccordionItemData[]): Promise<void> {
  try {
    await fs.mkdir(path.dirname(dataFilePath), { recursive: true });
    const jsonData = JSON.stringify(data, null, 2);
    await fs.writeFile(dataFilePath, jsonData, 'utf-8');
  } catch (error) {
    console.error('Error writing collections data:', error);
    throw new Error('Failed to write collections data.');
  }
}

export async function GET(request: NextRequest, { params }: { params: { id: string } }) {
  try {
    const collections = await readCollections();
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
  try {
    const updatedCollectionData: Partial<AccordionItemData> = await request.json();
    
    let collections = await readCollections();
    const collectionIndex = collections.findIndex(item => item.id === params.id);

    if (collectionIndex === -1) {
      return NextResponse.json({ message: 'Collection not found' }, { status: 404 });
    }

    // Filter out blob images before saving
    const imagesToPersist = (updatedCollectionData.images || collections[collectionIndex].images || [])
                            .filter(img => typeof img.src === 'string' && !img.src.startsWith('blob:'));

    collections[collectionIndex] = { 
      ...collections[collectionIndex], 
      ...updatedCollectionData,
      images: imagesToPersist 
    };
    
    await writeCollections(collections);
    return NextResponse.json(collections[collectionIndex]);
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to update collection' }, { status: 500 });
  }
}

export async function DELETE(request: NextRequest, { params }: { params: { id: string } }) {
  try {
    let collections = await readCollections();
    const initialLength = collections.length;
    collections = collections.filter(item => item.id !== params.id);

    if (collections.length === initialLength) {
      return NextResponse.json({ message: 'Collection not found' }, { status: 404 });
    }

    await writeCollections(collections);
    return NextResponse.json({ message: 'Collection deleted successfully' }, { status: 200 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to delete collection' }, { status: 500 });
  }
}
