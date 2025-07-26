
'use server';

import { NextResponse } from 'next/server';

// This file is no longer used for fetching individual collections with the new data structure.
// Individual activities are fetched in the main /api/collections route and processed client-side.
// We are leaving this file but returning a 404 for any requests to prevent errors if it's still linked somewhere.

export async function GET() {
  return NextResponse.json({ message: 'Endpoint not found' }, { status: 404 });
}

export async function PUT() {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}

export async function DELETE() {
    return NextResponse.json({ message: 'Method not allowed' }, { status: 405 });
}
