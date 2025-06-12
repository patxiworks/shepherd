
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import fs from 'fs/promises';
import path from 'path';

// Adjust the path to your phones.json file
const PHONES_FILE_PATH = path.join(process.cwd(), 'src', 'data', 'phones.json');

async function readAuthorizedPhones(): Promise<string[]> {
  try {
    const data = await fs.readFile(PHONES_FILE_PATH, 'utf-8');
    return JSON.parse(data) as string[];
  } catch (error) {
    console.error('Error reading phones.json:', error);
    // If the file doesn't exist or is invalid, return empty or handle as critical error
    return []; 
  }
}

export async function POST(request: NextRequest) {
  try {
    const { phoneNumber } = await request.json();

    if (!phoneNumber) {
      return NextResponse.json({ message: 'Phone number is required' }, { status: 400 });
    }

    const authorizedPhones = await readAuthorizedPhones();
    const isAuthorized = authorizedPhones.includes(phoneNumber);

    if (isAuthorized) {
      return NextResponse.json({ success: true, phoneNumber, message: 'Phone number verified successfully' });
    } else {
      return NextResponse.json({ success: false, message: 'Phone number not authorized' }, { status: 401 });
    }
  } catch (error) {
    console.error('Phone login API error:', error);
    return NextResponse.json({ success: false, message: 'An unexpected error occurred during phone verification' }, { status: 500 });
  }
}
