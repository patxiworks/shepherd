'use server';

import {type NextRequest, NextResponse} from 'next/server';
import type {ZoneUser} from '@/types';

// This URL should be in an environment variable in a real application
const REMOTE_USERS_URL =
  'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLgY2m6JdYSLfN5zGz_pC4F2A_C3mQG4s-hX0x4s3E8a3e7s8g5f2t1q0w9p8o7n6m5l4k3j2h1g0f9e8d7c6b5a4&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';

async function getZoneUsers(): Promise<ZoneUser[]> {
  try {
    const response = await fetch(REMOTE_USERS_URL, {cache: 'no-store'});
    if (!response.ok) {
      throw new Error('Failed to fetch zone user data');
    }
    const data = (await response.json()) as {data: ZoneUser[]};
    return data.data || [];
  } catch (error) {
    console.error('Error fetching zone users:', error);
    return [];
  }
}

export async function GET() {
  try {
    const users = await getZoneUsers();
    const zones = [...new Set(users.map(u => u.zone).filter(Boolean))].sort();
    return NextResponse.json({zones});
  } catch (error) {
    return NextResponse.json(
      {message: 'Failed to get zones'},
      {status: 500}
    );
  }
}

export async function POST(request: NextRequest) {
  try {
    const {zone, passcode} = await request.json();

    if (!zone || !passcode) {
      return NextResponse.json(
        {message: 'Zone and passcode are required'},
        {status: 400}
      );
    }

    const users = await getZoneUsers();
    const user = users.find(
      u => u.zone === zone && u.passcode.toLowerCase() === passcode.toLowerCase()
    );

    if (user) {
      // Return the matched user object on success
      return NextResponse.json({success: true, user});
    } else {
      return NextResponse.json(
        {success: false, message: 'Invalid zone or passcode'},
        {status: 401}
      );
    }
  } catch (error) {
    console.error('Zone login API error:', error);
    return NextResponse.json(
      {success: false, message: 'An unexpected error occurred'},
      {status: 500}
    );
  }
}
