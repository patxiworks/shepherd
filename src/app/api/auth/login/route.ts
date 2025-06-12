
'use server';

import { type NextRequest, NextResponse } from 'next/server';
import fs from 'fs/promises';
import path from 'path';
import type { UserCredentials } from '@/types';

const USERS_FILE_PATH = path.join(process.cwd(), 'src', 'data', 'users.json');

async function readUsers(): Promise<UserCredentials[]> {
  try {
    const data = await fs.readFile(USERS_FILE_PATH, 'utf-8');
    return JSON.parse(data) as UserCredentials[];
  } catch (error) {
    console.error('Error reading users.json:', error);
    // If the file doesn't exist or is invalid, return empty or handle as critical error
    return []; 
  }
}

export async function POST(request: NextRequest) {
  try {
    const { username, password }: LoginFormData = await request.json();

    if (!username || !password) {
      return NextResponse.json({ message: 'Username and password are required' }, { status: 400 });
    }

    const users = await readUsers();
    const user = users.find(u => u.username === username && u.password === password); // Plaintext comparison for prototype

    if (user) {
      // In a real app, you'd return a session token or JWT, not the user object directly with password
      return NextResponse.json({ username: user.username, message: 'Login successful' });
    } else {
      return NextResponse.json({ message: 'Invalid username or password' }, { status: 401 });
    }
  } catch (error) {
    console.error('Login API error:', error);
    return NextResponse.json({ message: 'An unexpected error occurred during login' }, { status: 500 });
  }
}

// Define LoginFormData type locally if not importing from '@/types' or if there's a circular dependency concern
// For simplicity here, assuming it can be imported or is simple enough not to conflict.
interface LoginFormData {
  username: string;
  password?: string;
}
