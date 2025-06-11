
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import fs from 'fs/promises';
import path from 'path';
import type { AccordionItemData } from '@/types';

// Using /tmp directory for collections.json.
// IMPORTANT: Files in /tmp are generally NOT PERSISTENT.
// Data may be lost on server/instance restarts or deployments.
const collectionsFilePath = path.join('/tmp', 'collections.json');

async function readCollections(): Promise<AccordionItemData[]> {
  try {
    const fileData = await fs.readFile(collectionsFilePath, 'utf-8');
    return JSON.parse(fileData) as AccordionItemData[];
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      console.warn(`Collections file not found at ${collectionsFilePath}. Returning empty array. This is expected if the /tmp directory was cleared or on first run.`);
      return []; 
    }
    console.error('Error reading collections data from /tmp:', error);
    throw new Error('Failed to read collections data from /tmp.');
  }
}

async function writeCollections(data: AccordionItemData[]): Promise<void> {
  try {
    const jsonData = JSON.stringify(data, null, 2);
    await fs.writeFile(collectionsFilePath, jsonData, 'utf-8');
  } catch (error) {
    console.error('Error writing collections data to /tmp:', error);
    throw new Error('Failed to write collections data to /tmp.');
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
  } catch (error)
