import { NextResponse } from 'next/server';

// The section colours set in the admin (Structure > Sections), as
// { sections: { sf: '#FFB74D', ... } }. They come from the PHP backend's
// api/sections.php, whose URL is SECTIONS_API_URL; without it the colours in
// src/lib/section-colors.ts are used.
export async function GET() {
  const url = process.env.SECTIONS_API_URL;
  if (!url) return NextResponse.json({ sections: {} });
  try {
    const response = await fetch(url, { cache: 'no-store' });
    if (!response.ok) throw new Error(response.statusText);
    return NextResponse.json(await response.json());
  } catch (error) {
    console.error('Error reading section colours:', error);
    return NextResponse.json({ sections: {} });
  }
}
