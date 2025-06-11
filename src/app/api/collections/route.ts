
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import fs from 'fs/promises';
import path from 'path';
import type { AccordionItemData } from '@/types';

const collectionsFilePath = path.join(process.cwd(), 'src', 'data', 'collections.json');

async function readCollections(): Promise<AccordionItemData[]> {
  try {
    const fileData = await fs.readFile(collectionsFilePath, 'utf-8');
    return JSON.parse(fileData) as AccordionItemData[];
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      console.warn(`Local collections file not found at ${collectionsFilePath}. Returning empty array.`);
      return []; 
    }
    console.error('Error reading local collections data:', error);
    throw new Error('Failed to read local collections data.');
  }
}

async function writeCollections(data: AccordionItemData[]): Promise<void> {
  try {
    const jsonData = JSON.stringify(data, null, 2);
    await fs.writeFile(collectionsFilePath, jsonData, 'utf-8');
  } catch (error) {
    console.error('Error writing local collections data:', error);
    throw new Error('Failed to write local collections data.');
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
