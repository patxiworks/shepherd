
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
      // If file doesn't exist, return empty array, it will be created on first write
      return [];
    }
    console.error('Error reading collections data:', error);
    throw new Error('Failed to read collections data.');
  }
}

async function writeCollections(data: AccordionItemData[]): Promise<void> {
  try {
    // Ensure src/data directory exists
    await fs.mkdir(path.dirname(dataFilePath), { recursive: true });
    const jsonData = JSON.stringify(data, null, 2);
    await fs.writeFile(dataFilePath, jsonData, 'utf-8');
  } catch (error) {
    console.error('Error writing collections data:', error);
    throw new Error('Failed to write collections data.');
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
    if (!newCollection.id || !newCollection.parishLocation) {
      return NextResponse.json({ message: 'Invalid collection data' }, { status: 400 });
    }

    // Filter out blob images before saving
    newCollection.images = newCollection.images.filter(img => !img.src.startsWith('blob:'));

    const collections = await readCollections();
    collections.push(newCollection);
    await writeCollections(collections);
    return NextResponse.json(newCollection, { status: 201 });
  } catch (error) {
    return NextResponse.json({ message: (error as Error).message || 'Failed to create collection' }, { status: 500 });
  }
}
